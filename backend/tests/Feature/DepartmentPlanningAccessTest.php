<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DepartmentPlanningAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $editor;

    private User $globalEditor;

    private array $departments;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->departments = DB::table('departments')->pluck('id', 'code')->all();
        $this->globalEditor = User::where('employee_number', 'EMP011')->firstOrFail();
        $this->grantPlanningDepartments($this->globalEditor);
        $this->editor = User::factory()->create(['system_role' => 'plan_editor', 'is_active' => true]);
        $this->grantPlanningDepartments($this->editor, [$this->departments['IT']]);
    }

    private function document(string $owner, array $departments): array
    {
        return ['code' => 'PLAN-'.$owner, 'title' => 'Personal plan', 'owner_id' => User::where('employee_number', $owner)->value('id'), 'year' => 2026,
            'kpas' => array_map(fn ($department) => ['code' => 'KPA-'.$department, 'title' => 'Area '.$department,
                'department_id' => $this->departments[$department], 'weight' => 1 / count($departments), 'kpis' => [
                    ['code' => 'KPI-'.$department, 'title' => 'Measure '.$department, 'weight' => 1, 'target_value' => 1,
                        'target_unit' => 'count', 'frequency' => 'Monthly', 'direction' => 'higher', 'activities' => [
                            ['code' => 'ACT-'.$department, 'title' => 'Work '.$department, 'weight' => 1],
                        ]],
                ]], $departments)];
    }

    private function plan(array $document): int
    {
        return $this->actingAs($this->globalEditor)->postJson('/api/planning/plans', $document)->assertCreated()->json('id');
    }

    public function test_workspace_and_direct_reads_expose_only_assigned_departments(): void
    {
        $mixed = $this->plan($this->document('EMP001', ['IT', 'DIG']));
        $outside = $this->plan($this->document('EMP002', ['FIN']));
        $response = $this->actingAs($this->editor)->getJson('/api/planning/workspace')->assertOk();
        $response->assertJsonCount(1, 'departments')->assertJsonCount(1, 'plans')->assertJsonPath('plans.0.id', $mixed)
            ->assertJsonPath('plans.0.kpa_count', 1)->assertJsonPath('plans.0.can_edit', false);
        $this->assertSame([$this->departments['IT']], collect($response->json('memberships'))->pluck('department_id')->unique()->values()->all());
        $this->assertNotContains(User::where('employee_number', 'EMP002')->value('id'), array_column($response->json('owners'), 'id'));
        $this->getJson('/api/planning/plans/'.$outside)->assertForbidden();
        $detail = $this->getJson('/api/planning/plans/'.$mixed)->assertOk()->assertJsonPath('can_edit', false)
            ->assertJsonPath('department_view_only', true)->assertJsonCount(1, 'plan.kpas')->assertJsonCount(0, 'versions');
        $this->assertStringNotContainsString('Measure DIG', $detail->getContent());
        $this->assertStringNotContainsString('Work DIG', $detail->getContent());
        $this->getJson('/api/organization-chart')->assertOk()->assertJsonCount(1, 'departments')
            ->assertJsonPath('departments.0.id', $this->departments['IT']);
    }

    public function test_mutations_reject_foreign_owners_foreign_kpas_and_mixed_plan_changes(): void
    {
        $document = $this->document('EMP001', ['IT', 'DIG']);
        $mixed = $this->plan($document);
        $outside = $this->plan($this->document('EMP002', ['FIN']));
        $this->actingAs($this->editor);
        foreach ([$mixed, $outside] as $id) {
            $this->putJson('/api/planning/plans/'.$id, [...$document, 'revision' => 1])->assertForbidden();
            $this->postJson('/api/planning/plans/'.$id.'/publish', ['revision' => 1])->assertForbidden();
            $this->getJson('/api/planning/plans/'.$id.'/deletion')->assertForbidden();
            $this->deleteJson('/api/planning/plans/'.$id, ['revision' => 1])->assertForbidden();
        }
        $this->postJson('/api/planning/plans', [...$this->document('EMP002', ['FIN']), 'code' => 'FORGED'])->assertForbidden();
        $this->postJson('/api/planning/plans', [...$document, 'code' => 'FORGED-MIXED'])->assertForbidden();
        $this->assertDatabaseCount('performance_plans', 2);
    }

    public function test_in_scope_plan_remains_editable_and_revoked_membership_takes_effect_immediately(): void
    {
        $document = $this->document('EMP004', ['IT']);
        $id = $this->actingAs($this->editor)->postJson('/api/planning/plans', $document)->assertCreated()->json('id');
        $this->putJson('/api/planning/plans/'.$id, [...$document, 'revision' => 1, 'title' => 'Updated title'])->assertOk();
        $this->postJson('/api/planning/plans/'.$id.'/publish', ['revision' => 2])->assertOk();
        $this->getJson('/api/planning/plans/'.$id)->assertOk()->assertJsonPath('can_edit', true);
        DB::table('department_memberships')->where('user_id', $this->editor->id)->delete();
        $this->getJson('/api/planning/workspace')->assertOk()->assertJsonCount(0, 'owners')->assertJsonCount(0, 'plans')->assertJsonCount(0, 'departments');
        $this->getJson('/api/planning/plans/'.$id)->assertForbidden();
        $this->postJson('/api/planning/plans/'.$id.'/publish', ['revision' => 2])->assertForbidden();
        $this->getJson('/api/organization-chart')->assertOk()->assertJsonCount(0, 'people');
    }

    public function test_monthly_results_and_import_options_are_scoped_independently(): void
    {
        $id = $this->plan($this->document('EMP001', ['IT', 'DIG']));
        $this->postJson('/api/planning/plans/'.$id.'/publish', ['revision' => 1])->assertOk();
        $this->actingAs($this->editor)->getJson('/api/kpi-results/workspace?period=2026-11')->assertOk()
            ->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.kpi_code', 'KPI-IT');
        $this->getJson('/api/planning/excel-import/options')->assertOk()->assertJsonCount(1, 'departments');
        $this->getJson('/api/planning/excel-import/options?department_id='.$this->departments['FIN'])->assertForbidden();
        $this->post('/api/planning/excel-import', ['workbook' => UploadedFile::fake()->create('plan.xlsx', 1), 'year' => 2026,
            'owner_id' => User::where('employee_number', 'EMP002')->value('id'), 'department_id' => $this->departments['FIN']], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_personal_allocations_cannot_leak_cross_department_work_or_be_forged(): void
    {
        $outside = (int) User::where('employee_number', 'EMP002')->value('id');
        $mixed = (int) User::where('employee_number', 'EMP001')->value('id');
        $own = (int) User::where('employee_number', 'EMP004')->value('id');
        $response = $this->actingAs($this->editor)->getJson('/api/allocations/workspace')->assertOk();
        $ids = array_column($response->json('people'), 'id');
        $this->assertContains($own, $ids);
        $this->assertNotContains($outside, $ids);
        $this->assertNotContains($mixed, $ids);
        foreach ([$outside, $mixed] as $owner) {
            $this->getJson('/api/allocations/people/'.$owner.'/2026')->assertForbidden();
            $this->postJson('/api/allocations/draft', ['owner_id' => $owner, 'year' => 2026, 'version' => 0,
                'entries' => [['key' => 'forged', 'token' => str_repeat('a', 64), 'weight' => 1]]])->assertForbidden();
        }
        $this->getJson('/api/allocations/people/'.$own.'/2026')->assertOk();
        $id = DB::table('personal_allocations')->insertGetId(['owner_id' => $outside, 'year' => 2026,
            'version' => 1, 'status' => 'draft', 'draft_payload' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson('/api/allocations/'.$id.'/publish', ['version' => 1])->assertForbidden();
    }
}
