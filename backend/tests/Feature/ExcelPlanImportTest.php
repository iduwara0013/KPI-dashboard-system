<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use ZipArchive;

class ExcelPlanImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_editor_previews_an_employee_workbook_and_publishes_after_review(): void
    {
        [$employee, $department] = $this->employeeInDepartment();
        $editor = User::factory()->create(['system_role' => 'plan_editor', 'is_active' => true]);
        $this->grantPlanningDepartments($editor);
        $other = User::factory()->create(['system_role' => 'member', 'is_active' => true, 'employee_number' => '11474']);
        $url = '/api/planning/excel-import';
        $scope = ['year' => 2026, 'owner_id' => $employee->id, 'department_id' => $department];
        $this->actingAs($employee)->getJson($url.'/options')->assertForbidden();
        $this->post($url, [...$scope, 'workbook' => $this->workbook()], ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($editor)->getJson($url.'/options')->assertOk()
            ->assertJsonPath('years.0', 2026)->assertJsonPath('departments.0.id', $department);
        $inactive = User::factory()->create(['name' => 'ZZ Inactive Employee', 'system_role' => 'member', 'is_active' => false, 'employee_number' => '11475']);
        DB::table('department_memberships')->insert([
            'user_id' => $inactive->id, 'department_id' => $department, 'responsibility' => 'manager',
            'responsibility_title' => 'Head of Data Center',
        ]);
        $this->getJson($url.'/options?department_id='.$department)->assertOk()->assertJsonCount(2, 'employees')
            ->assertJsonPath('employees.0.id', $employee->id)->assertJsonPath('employees.0.import_block_reason', null)
            ->assertJsonPath('employees.1.id', $inactive->id)->assertJsonPath('employees.1.responsibility_title', 'Head of Data Center')
            ->assertJsonPath('employees.1.import_block_reason', 'Inactive account');
        $this->post($url, [...$scope, 'owner_id' => $inactive->id, 'workbook' => $this->workbook()], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('workbook');
        $this->post($url, [...$scope, 'owner_id' => $other->id, 'workbook' => $this->workbook()], ['Accept' => 'application/json'])
            ->assertUnprocessable();
        DB::table('department_memberships')->insert(['user_id' => $other->id, 'department_id' => $department, 'responsibility' => 'employee']);
        $this->post($url, [...$scope, 'owner_id' => $other->id, 'sheet' => 'Individual', 'workbook' => $this->workbook()], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('workbook');
        $this->post($url, [...$scope, 'workbook' => $this->workbook()], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('sheets.0', 'Individual');
        $this->post($url, [...$scope, 'workbook' => $this->workbook(), 'sheet' => 'Individual'], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('employee.id', $employee->id)->assertJsonPath('weight_total', 1)
            ->assertJsonPath('rows.0.target', '100%')->assertJsonPath('rows.0.monitoring', 'M')
            ->assertJsonPath('rows.0.activities.1', 'Check service access');
        $this->assertDatabaseCount('performance_plans', 0);
        $data = [...$scope, 'sheet' => 'Individual', 'save' => '1'];
        $this->post($url, [...$data, 'owner_id' => $editor->id, 'workbook' => $this->workbook()], ['Accept' => 'application/json'])->assertUnprocessable();
        $plan = $this->post($url, [...$data, 'workbook' => $this->workbook()], ['Accept' => 'application/json'])->assertCreated()->json('id');
        $this->post($url, [...$data, 'workbook' => $this->workbook()], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseCount('performance_plans', 1);
        $planUrl = '/api/planning/plans/'.$plan;
        $detail = $this->getJson($planUrl)->assertOk()
            ->assertJsonPath('plan.excel_source.sheet', 'Individual')->assertJsonPath('plan.status', 'draft')
            ->assertJsonPath('plan.kpas.0.kpis.0.frequency', null)->assertJsonPath('plan.kpas.0.kpis.0.target_value', 100)
            ->assertJsonPath('plan.kpas.0.kpis.0.activities.0.weight', null)->json('plan');
        $this->actingAs($employee)->getJson($planUrl)->assertForbidden();
        $this->actingAs($editor);
        $detail['title'] = 'Reviewed draft title';
        $this->putJson($planUrl, $detail)->assertOk();
        $kpi = &$detail['kpas'][0]['kpis'][0];
        $this->postJson($planUrl.'/publish', ['revision' => 2])->assertUnprocessable()
            ->assertJsonPath('errors.publication.0', $kpi['code'].': choose monitoring frequency and target direction.')
            ->assertJsonPath('errors.publication.1', $kpi['code'].' activity allocation: enter every weight.');

        $detail['revision'] = 2;
        $kpi['frequency'] = 'Monthly';
        $kpi['direction'] = 'higher';
        $kpi['activities'][0]['weight'] = 0.6;
        $kpi['activities'][1]['weight'] = 0.4;
        $this->putJson($planUrl, $detail)->assertOk();
        $this->postJson($planUrl.'/publish', ['revision' => 3])->assertOk();
        $this->actingAs($employee)->getJson('/api/planning/workspace')->assertOk()
            ->assertJsonPath('can_edit', false)->assertJsonPath('plans.0.id', $plan);
        $this->getJson('/api/session')->assertOk()->assertJsonPath('user.can_view_plans', true);
        $this->getJson($planUrl)->assertOk()->assertJsonPath('plan.status', 'published')
            ->assertJsonPath('plan.kpas.0.kpis.0.activities.0.weight', 0.6);
        $this->postJson($planUrl.'/publish', ['revision' => 4])->assertForbidden();
        $this->actingAs($other)->getJson($planUrl)->assertForbidden();
        $md = User::factory()->create(['system_role' => 'md', 'is_active' => true]);
        $this->actingAs($md)->getJson('/api/md/workspace?year=2026')->assertOk()->assertJsonCount(2, 'activities');
    }

    public function test_bad_weights_are_rejected_during_preview(): void
    {
        [$employee, $department] = $this->employeeInDepartment();
        $editor = User::factory()->create(['system_role' => 'plan_editor', 'is_active' => true]);
        $this->grantPlanningDepartments($editor);
        $this->actingAs($editor)->post('/api/planning/excel-import', [
            'workbook' => $this->workbook('0.75'), 'sheet' => 'Individual', 'year' => 2026,
            'owner_id' => $employee->id, 'department_id' => $department,
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('workbook');
        $this->assertDatabaseCount('performance_plans', 0);
    }

    private function employeeInDepartment(): array
    {
        $employee = User::factory()->create(['system_role' => 'member', 'is_active' => true, 'employee_number' => '11473']);
        $department = DB::table('departments')->insertGetId(['name' => 'Information Technology', 'code' => 'IT', 'color' => '#c5120c', 'is_active' => true]);
        DB::table('department_memberships')->insert(['user_id' => $employee->id, 'department_id' => $department, 'responsibility' => 'employee']);

        return [$employee, $department];
    }

    private function workbook(string $weight = '1'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'kpi-import-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Individual" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cellXfs><xf numFmtId="0"/><xf numFmtId="9"/></cellXfs></styleSheet>');
        $zip->addFromString('xl/worksheets/sheet1.xml', <<<XML
            <worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>
              <row r="1"><c r="E1" t="inlineStr"><is><t>Test Employee</t></is></c></row>
              <row r="2"><c r="D2" t="inlineStr"><is><t>EPF</t></is></c><c r="E2" t="inlineStr"><is><t>11473 Systems Executive</t></is></c></row>
              <row r="3"><c r="B3" t="inlineStr"><is><t>KPIs of the CIO &amp; Team- 2026</t></is></c></row>
              <row r="4"><c r="F4" t="inlineStr"><is><t>Target for 2026</t></is></c><c r="I4" t="inlineStr"><is><t>KPI Measures</t></is></c></row>
              <row r="5">
                <c r="B5" t="inlineStr"><is><t>Internal Processes</t></is></c>
                <c r="C5" t="inlineStr"><is><t>IT system availability</t></is></c>
                <c r="D5"><v>1</v></c>
                <c r="E5" t="inlineStr"><is><t>Availability</t></is></c>
                <c r="F5" s="1"><v>1</v></c>
                <c r="G5" t="inlineStr"><is><t>1.Monitor uptime    2.Check service access</t></is></c>
                <c r="H5" t="inlineStr"><is><t>M</t></is></c>
                <c r="I5"><v>{$weight}</v></c>
              </row>
              <row r="6"><c r="I6"><f>SUM(I5:I5)</f><v>{$weight}</v></c></row>
            </sheetData></worksheet>
            XML);
        $zip->close();
        try {
            return UploadedFile::fake()->createWithContent('test-plan.xlsx', file_get_contents($path));
        } finally {
            unlink($path);
        }
    }
}
