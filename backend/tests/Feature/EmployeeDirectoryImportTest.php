<?php

namespace Tests\Feature;

use App\Http\Controllers\EmployeeDirectoryImportController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class EmployeeDirectoryImportTest extends TestCase
{
    use RefreshDatabase;

    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            is_dir($path) ? File::deleteDirectory($path) : File::delete($path);
        }
        parent::tearDown();
    }

    private function source(array $rows): string
    {
        $path = storage_path('framework/testing/employees-'.Str::random(12).'.csv');
        File::ensureDirectoryExists(dirname($path));
        $handle = fopen($path, 'wb');
        fputcsv($handle, ['epf', 'firstname', 'lastname', 'contact', 'function', 'personnel_area', 'designation', '', '', '', ''], ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, [...$row, '', '', '', ''], ',', '"', '');
        }
        fclose($handle);
        $this->paths[] = $path;

        return $path;
    }

    public function test_preview_then_replacement_preserves_admin_and_verifies_backup_and_every_employee_field(): void
    {
        $this->seed();
        $admin = User::where('system_role', 'super_admin')->firstOrFail();
        $password = $admin->password;
        $previousCount = User::count();
        $oldOwner = User::where('employee_number', 'EMP001')->value('id');
        $oldEmployee = User::where('employee_number', 'EMP007')->value('id');
        $oldDepartment = DB::table('departments')->where('code', 'IT')->value('id');
        $oldPlan = DB::table('performance_plans')->insertGetId(['code' => 'OLD', 'title' => 'Old test plan', 'owner_id' => $oldOwner, 'created_by' => $admin->id, 'year' => 2026]);
        $oldArea = DB::table('performance_plan_items')->insertGetId(['plan_id' => $oldPlan, 'department_id' => $oldDepartment, 'code' => 'AREA', 'kind' => 'kpa', 'title' => 'Old area']);
        DB::table('performance_plan_items')->insert(['plan_id' => $oldPlan, 'department_id' => $oldDepartment, 'parent_id' => $oldArea, 'code' => 'KPI', 'kind' => 'kpi', 'title' => 'Old KPI']);
        $oldAssignment = DB::table('work_assignments')->insertGetId(['plan_id' => $oldPlan, 'activity_code' => 'ACT', 'owner_id' => $oldOwner, 'assignee_id' => $oldEmployee,
            'department_id' => $oldDepartment, 'source_revision' => 1, 'source_signature' => str_repeat('a', 64)]);
        $attributes = ['assignment_id' => $oldAssignment, 'generation' => 1, 'manager_id' => $oldOwner, 'reviewer_id' => $oldOwner, 'assignee_id' => $oldEmployee, 'title' => 'Old work', 'weight' => 1];
        $oldTask = DB::table('delivery_tasks')->insertGetId($attributes);
        $oldChild = DB::table('delivery_tasks')->insertGetId([...$attributes, 'parent_task_id' => $oldTask]);
        DB::table('task_submissions')->insert(['task_id' => $oldChild, 'submitted_by' => $oldEmployee, 'evidence' => 'Old evidence', 'submitted_at' => now()]);
        $source = $this->source([
            ['11473', 'A G L C', 'WIJETHUNGA', '0712345678', 'Information Systems', 'Kolonnawa', 'HEAD OF DATA CENTER'],
            ['60367', 'N K M C', 'NETHTHIKUMARA', '', 'Secretariat', 'Kolonnawa', 'MANAGING DIRECTOR'],
            ['50015', 'U R A S', 'WEERASINGHE', '', '', 'LBD Badulla', 'DAILY PAID BASIS'],
        ]);
        $import = app(EmployeeDirectoryImportController::class);
        $preview = $import->replace($source, false, '60367');
        $this->assertSame(3, $preview['employees']);
        $this->assertSame(2, $preview['departments']);
        $this->assertSame($previousCount, User::count());
        $result = $import->replace($source, true, '60367');
        $this->paths[] = dirname($result['backup']);
        $this->assertTrue($result['applied']);
        $this->assertSame(4, $result['users_after']);
        $this->assertSame(1, $result['memberships']);
        $this->assertSame($password, $admin->fresh()->password);
        $backup = json_decode(Crypt::decryptString(file_get_contents($result['backup'])), true);
        $this->assertCount($previousCount, $backup['tables']['users']);
        $this->assertStringNotContainsString('WIJETHUNGA', file_get_contents($result['backup']));
        $employee = User::where('employee_number', '11473')->firstOrFail();
        $this->assertSame('A G L C WIJETHUNGA', $employee->name);
        $this->assertSame('0712345678', $employee->contact);
        $this->assertSame('Kolonnawa', $employee->personnel_area);
        $this->assertSame('Information Systems', $employee->source_function);
        $this->assertNull($employee->grade);
        $this->assertSame('member', $employee->system_role);
        $this->assertTrue($employee->is_active);
        $md = User::where('employee_number', '60367')->firstOrFail();
        $this->assertSame('md', $md->system_role);
        $this->assertDatabaseMissing('department_memberships', ['user_id' => $md->id]);
        $this->assertFalse(User::where('employee_number', '50015')->firstOrFail()->is_active);
        $this->assertDatabaseCount('reporting_relationships', 0);
        $this->assertDatabaseCount('performance_plans', 0);
        $this->assertDatabaseCount('performance_plan_items', 0);
        $this->assertDatabaseCount('delivery_tasks', 0);
        $this->assertDatabaseCount('task_submissions', 0);
        $this->assertCount(2, $backup['tables']['delivery_tasks']);
        $this->assertCount(1, $backup['tables']['task_submissions']);
        $this->assertDatabaseHas('admin_activity', ['action' => 'employees.replaced']);
        $credentials = array_map('str_getcsv', file($result['credentials']));
        $this->assertCount(4, $credentials);
        $this->assertNotSame($credentials[1][3], $credentials[2][3]);
        $this->assertTrue(Hash::check($credentials[1][3], $employee->password));
        $verification = $import->verify($source, '60367');
        $this->assertSame(3, $verification['verified_employees']);
        $this->assertSame([], $verification['mismatches']);
        $employee->update(['contact' => 'wrong']);
        $this->assertSame([['epf' => '11473', 'fields' => ['contact']]], $import->verify($source, '60367')['mismatches']);
        $this->postJson('/api/login', ['email' => '11473', 'password' => $credentials[1][3]])->assertOk()->assertJsonPath('user.employee_number', '11473');
    }

    public function test_duplicate_epfs_and_invalid_md_are_rejected_before_any_data_changes(): void
    {
        $this->seed();
        $before = User::count();
        $source = $this->source([
            ['11473', 'A', 'PERSON', '', 'IT', 'Kolonnawa', 'ENGINEER'],
            ['11473', 'B', 'PERSON', '', 'IT', 'Kolonnawa', 'ENGINEER'],
        ]);
        try {
            app(EmployeeDirectoryImportController::class)->replace($source, true);
            $this->fail('Duplicate employee numbers must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('duplicate', $exception->getMessage());
        }
        $this->assertSame($before, User::count());
        $valid = $this->source([['11473', 'A', 'PERSON', '', 'IT', 'Kolonnawa', 'ENGINEER']]);
        $this->expectException(RuntimeException::class);
        app(EmployeeDirectoryImportController::class)->replace($valid, true, '11473');
    }

    public function test_epf_and_email_authentication_keep_the_same_inactive_password_and_scope_checks(): void
    {
        $this->seed();
        $employee = User::where('employee_number', 'EMP007')->firstOrFail();
        $employee->update(['email' => 'EMP007@directory.cpstl.invalid', 'password' => 'Unique-temp-123']);
        $this->postJson('/api/login', ['email' => ' EMP007 ', 'password' => 'Unique-temp-123'])->assertOk();
        $this->postJson('/api/logout')->assertOk();
        $this->postJson('/api/login', ['email' => $employee->email, 'password' => 'Unique-temp-123'])->assertUnprocessable();
        $this->postJson('/api/login', ['email' => 'EMP007', 'password' => 'wrong'])->assertUnprocessable();
        $employee->update(['is_active' => false]);
        $this->postJson('/api/login', ['email' => 'EMP007', 'password' => 'Unique-temp-123'])->assertUnprocessable();
    }

    public function test_admin_can_update_imported_contact_location_and_department_without_an_invented_email(): void
    {
        $this->seed();
        $admin = User::where('system_role', 'super_admin')->firstOrFail();
        $employee = User::where('employee_number', 'EMP007')->firstOrFail();
        $employee->update(['email' => 'EMP007@directory.cpstl.invalid']);
        $department = DB::table('departments')->where('code', 'IT')->value('id');
        $this->actingAs($admin)->putJson('/api/admin/users/'.$employee->id, [
            'name' => $employee->name, 'email' => '', 'employee_number' => $employee->employee_number, 'designation' => $employee->designation,
            'system_role' => 'member', 'is_active' => true, 'contact' => '0712345678', 'personnel_area' => 'Kolonnawa',
            'memberships' => [['department_id' => $department, 'responsibility' => 'employee']],
        ])->assertOk();
        $this->assertSame('0712345678', $employee->fresh()->contact);
        $this->assertSame('Kolonnawa', $employee->fresh()->personnel_area);
        $this->assertSame('emp007@directory.cpstl.invalid', strtolower($employee->fresh()->email));
    }
}
