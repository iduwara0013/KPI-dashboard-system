<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrganizationAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['system_role' => 'super_admin', 'is_active' => true]);
    }

    private function department(string $code = 'IT'): int
    {
        return DB::table('departments')->insertGetId([
            'name' => $code.' department', 'code' => $code, 'color' => '#23796b', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function membership(User $user, int $department, string $role = 'manager'): void
    {
        DB::table('department_memberships')->insert([
            'user_id' => $user->id, 'department_id' => $department, 'responsibility' => $role,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Test Employee', 'email' => 'new@example.test', 'employee_number' => 'EMP100',
            'designation' => 'Systems Executive', 'system_role' => 'member', 'is_active' => true,
            'password' => 'Strong-local-password!', 'memberships' => [],
        ], $overrides);
    }

    public function test_all_admin_endpoints_require_an_active_administrator(): void
    {
        $this->getJson('/api/admin/workspace')->assertUnauthorized();
        $member = User::factory()->create(['system_role' => 'member']);
        $this->actingAs($member)->getJson('/api/admin/workspace')->assertForbidden();
        $this->postJson('/api/admin/users', $this->payload())->assertForbidden();
        $this->postJson('/api/admin/departments', [])->assertForbidden();
        $this->putJson('/api/admin/users/'.$member->id, [])->assertForbidden();
        $this->deleteJson('/api/admin/users/'.$member->id)->assertForbidden();
        $this->putJson('/api/admin/departments/1', [])->assertForbidden();
        $this->putJson('/api/admin/departments/1/reporting', [])->assertForbidden();
        $member->update(['system_role' => 'super_admin', 'is_active' => false]);
        $this->actingAs($member)->getJson('/api/admin/workspace')->assertForbidden();
    }

    public function test_admin_can_remove_an_unlinked_account_and_its_memberships(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create();
        $department = $this->department();
        $this->membership($member, $department, 'employee');
        DB::table('reporting_relationships')->insert([
            'department_id' => $department, 'user_id' => $member->id, 'manager_id' => $admin->id,
        ]);
        $this->actingAs($admin)->deleteJson('/api/admin/users/'.$admin->id)->assertUnprocessable();
        $this->deleteJson('/api/admin/users/'.$member->id)->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $member->id]);
        $this->assertDatabaseMissing('department_memberships', ['user_id' => $member->id]);
        $this->assertDatabaseMissing('reporting_relationships', ['user_id' => $member->id]);
        $this->assertDatabaseHas('admin_activity', ['actor_id' => $admin->id, 'action' => 'user.removed']);
    }

    public function test_admin_can_only_remove_a_department_without_related_records(): void
    {
        $department = $this->department();
        $member = User::factory()->create(['system_role' => 'member']);
        $manager = User::factory()->create();
        $url = '/api/admin/departments/'.$department;
        $this->actingAs($member)->deleteJson($url)->assertForbidden();
        $this->actingAs($this->admin());

        $this->membership($member, $department, 'employee');
        $this->deleteJson($url)->assertConflict();
        $this->assertDatabaseHas('department_memberships', ['department_id' => $department, 'user_id' => $member->id]);
        DB::table('department_memberships')->where('department_id', $department)->delete();
        DB::table('reporting_relationships')->insert([
            'department_id' => $department, 'user_id' => $member->id, 'manager_id' => $manager->id,
        ]);
        $this->deleteJson($url)->assertConflict();
        DB::table('reporting_relationships')->where('department_id', $department)->delete();

        $this->deleteJson($url)->assertOk();
        $this->assertDatabaseMissing('departments', ['id' => $department]);
        $this->assertDatabaseHas('admin_activity', ['action' => 'department.removed', 'subject' => 'IT department']);
    }

    public function test_account_removal_preserves_direct_reports_and_saved_history(): void
    {
        $admin = $this->admin();
        $manager = User::factory()->create();
        $member = User::factory()->create();
        $department = $this->department();
        $this->membership($manager, $department);
        $this->membership($member, $department, 'employee');
        DB::table('reporting_relationships')->insert([
            'department_id' => $department, 'user_id' => $member->id, 'manager_id' => $manager->id,
        ]);
        DB::table('admin_activity')->insert([
            'actor_id' => $member->id, 'action' => 'work.assigned', 'subject' => 'Existing record',
        ]);
        $this->actingAs($admin)->deleteJson('/api/admin/users/'.$manager->id)->assertConflict();
        $this->deleteJson('/api/admin/users/'.$member->id)->assertConflict();
        $this->assertDatabaseHas('department_memberships', ['user_id' => $member->id]);
        $this->assertDatabaseHas('reporting_relationships', ['user_id' => $member->id, 'manager_id' => $manager->id]);
        $this->assertDatabaseHas('admin_activity', ['actor_id' => $member->id]);
    }

    public function test_department_reporting_validates_the_final_graph_and_rejects_stale_edits(): void
    {
        $department = $this->department();
        $otherDepartment = $this->department('DIG');
        $first = User::factory()->create(['is_active' => true]);
        $second = User::factory()->create(['is_active' => true]);
        $third = User::factory()->create(['is_active' => true]);
        foreach ([$department, $otherDepartment] as $id) {
            $this->membership($first, $id);
            $this->membership($second, $id);
        }
        $this->membership($third, $department, 'employee');
        foreach ([$department, $otherDepartment] as $id) {
            DB::table('reporting_relationships')->insert(['department_id' => $id, 'user_id' => $second->id, 'manager_id' => $first->id]);
        }
        $url = '/api/admin/departments/'.$department.'/reporting';
        $this->actingAs($this->admin());
        $this->putJson($url, ['changes' => [
            ['user_id' => $third->id, 'manager_ids' => [$first->id], 'expected_manager_ids' => []],
            ['user_id' => $first->id, 'manager_ids' => [$second->id], 'expected_manager_ids' => []],
        ]])->assertUnprocessable()->assertJsonValidationErrors('changes');
        $this->assertDatabaseMissing('reporting_relationships', ['user_id' => $third->id]);

        $change = ['changes' => [
            ['user_id' => $first->id, 'manager_ids' => [$second->id], 'expected_manager_ids' => []],
            ['user_id' => $second->id, 'manager_ids' => [], 'expected_manager_ids' => [$first->id]],
        ]];
        $this->putJson($url, $change)->assertOk()->assertJsonCount(1, 'links');
        $this->putJson($url, $change)->assertConflict();
        $this->assertDatabaseHas('reporting_relationships', ['department_id' => $department, 'user_id' => $first->id, 'manager_id' => $second->id]);
        $this->assertDatabaseHas('reporting_relationships', ['department_id' => $otherDepartment, 'user_id' => $second->id, 'manager_id' => $first->id]);
        $this->assertDatabaseHas('admin_activity', ['action' => 'department.reporting_updated']);
    }

    public function test_login_uses_a_session_and_logout_ends_it(): void
    {
        $admin = User::factory()->create(['system_role' => 'super_admin', 'password' => 'Correct-password-123']);
        $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'Correct-password-123'])
            ->assertOk()->assertJsonPath('user.id', $admin->id)->assertJsonMissingPath('user.password');
        $this->getJson('/api/admin/workspace')->assertOk();
        $this->postJson('/api/logout')->assertOk();
        $this->assertGuest();
    }

    public function test_md_has_no_department_and_only_one_active_director_can_be_saved(): void
    {
        $department = $this->department();
        $payload = $this->payload(['system_role' => 'md']);
        $this->actingAs($this->admin())->postJson('/api/admin/users', [...$payload, 'memberships' => [
            ['department_id' => $department, 'responsibility_title' => 'Managing Director'],
        ]])->assertUnprocessable()->assertJsonValidationErrors('memberships');
        $id = $this->postJson('/api/admin/users', $payload)->assertCreated()->json('user.id');
        $this->assertDatabaseMissing('department_memberships', ['user_id' => $id]);
        $this->putJson('/api/admin/users/'.$id, [...$payload, 'password' => ''])->assertOk();
        $second = [...$payload, 'email' => 'next-md@example.test', 'employee_number' => 'MD002'];
        $this->postJson('/api/admin/users', $second)->assertUnprocessable()->assertJsonValidationErrors('system_role');
        $inactive = $this->postJson('/api/admin/users', [...$second, 'is_active' => false])->assertCreated()->json('user.id');
        $this->putJson('/api/admin/users/'.$inactive, $second)->assertUnprocessable()->assertJsonValidationErrors('system_role');
    }

    public function test_old_md_department_links_are_removed_and_cannot_be_reassigned_through_reporting(): void
    {
        $department = $this->department();
        $director = User::factory()->create(['system_role' => 'md']);
        $manager = User::factory()->create();
        $employee = User::factory()->create();
        $this->membership($director, $department, 'hof');
        $this->membership($manager, $department, 'manager');
        $this->membership($employee, $department, 'employee');
        $this->actingAs($this->admin())->putJson('/api/admin/departments/'.$department.'/reporting', ['changes' => [
            ['user_id' => $employee->id, 'manager_ids' => [$director->id], 'expected_manager_ids' => []],
        ]])->assertUnprocessable();
        DB::table('reporting_relationships')->insert([
            ['department_id' => $department, 'user_id' => $director->id, 'manager_id' => $manager->id],
            ['department_id' => $department, 'user_id' => $employee->id, 'manager_id' => $director->id],
        ]);
        $migration = require database_path('migrations/2026_11_09_000002_remove_md_department_assignments.php');
        $migration->up();
        $this->assertDatabaseHas('users', ['id' => $director->id, 'system_role' => 'md']);
        $this->assertDatabaseMissing('department_memberships', ['user_id' => $director->id]);
        $this->assertDatabaseMissing('reporting_relationships', ['user_id' => $director->id]);
        $this->assertDatabaseMissing('reporting_relationships', ['manager_id' => $director->id]);
        $this->assertDatabaseHas('department_memberships', ['user_id' => $manager->id]);
        $this->assertDatabaseHas('admin_activity', ['action' => 'md.department_links_removed', 'subject' => $director->name]);
    }

    public function test_person_form_accepts_five_character_password_and_free_department_responsibilities(): void
    {
        $first = $this->department();
        $second = $this->department('DC');
        $payload = $this->payload(['password' => 'abc12', 'memberships' => [
            ['department_id' => $first, 'responsibility_title' => 'Information Systems Coordinator'],
            ['department_id' => $second, 'responsibility_title' => 'Acting Data Center Lead'],
        ]]);
        $this->actingAs($this->admin())->postJson('/api/admin/users', [...$payload, 'password' => '1234'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $id = $this->postJson('/api/admin/users', $payload)->assertCreated()->json('user.id');
        $this->assertDatabaseHas('department_memberships', [
            'user_id' => $id, 'department_id' => $first, 'responsibility' => 'employee',
            'responsibility_title' => 'Information Systems Coordinator',
        ]);
        $this->postJson('/api/logout')->assertOk();
        $this->postJson('/api/login', ['email' => $payload['email'], 'password' => 'abc12'])->assertOk();
    }

    public function test_editing_person_details_preserves_reporting_and_workflow_access(): void
    {
        $department = $this->department();
        $owner = User::factory()->create(['is_active' => true]);
        $manager = User::factory()->create(['is_active' => true]);
        $this->membership($owner, $department, 'hof');
        $this->membership($manager, $department, 'hof');
        DB::table('reporting_relationships')->insert([
            'department_id' => $department, 'user_id' => $owner->id, 'manager_id' => $manager->id,
        ]);
        $this->actingAs($this->admin())->putJson('/api/admin/users/'.$owner->id, $this->payload([
            'email' => $owner->email, 'password' => '', 'memberships' => [
                ['department_id' => $department, 'responsibility_title' => 'CIO'],
            ],
        ]))->assertOk();
        $this->assertDatabaseHas('department_memberships', ['user_id' => $owner->id, 'responsibility' => 'hof', 'responsibility_title' => 'CIO']);
        $this->assertDatabaseHas('reporting_relationships', ['user_id' => $owner->id, 'manager_id' => $manager->id, 'department_id' => $department]);
    }

    public function test_workflow_access_can_be_set_in_department_reporting_with_stale_edit_protection(): void
    {
        $department = $this->department();
        $secondDepartment = $this->department('DC');
        $head = User::factory()->create(['is_active' => true]);
        $employee = User::factory()->create(['is_active' => true]);
        $this->membership($head, $department, 'employee');
        $this->membership($head, $secondDepartment, 'employee');
        $this->membership($employee, $department, 'employee');
        $url = '/api/admin/departments/'.$department.'/reporting';
        $changes = ['changes' => [
            ['user_id' => $head->id, 'manager_ids' => [], 'expected_manager_ids' => [], 'responsibility' => 'hof', 'expected_responsibility' => 'employee'],
            ['user_id' => $employee->id, 'manager_ids' => [$head->id], 'expected_manager_ids' => [], 'responsibility' => 'employee', 'expected_responsibility' => 'employee'],
        ]];
        $this->actingAs($this->admin())->putJson($url, $changes)->assertOk()->assertJsonCount(2, 'memberships');
        $this->putJson($url, $changes)->assertConflict();
        $this->assertDatabaseHas('department_memberships', ['user_id' => $head->id, 'department_id' => $department, 'responsibility' => 'hof']);
        $this->assertDatabaseHas('department_memberships', ['user_id' => $head->id, 'department_id' => $secondDepartment, 'responsibility' => 'employee']);
        $this->putJson($url, ['changes' => [
            ['user_id' => $head->id, 'manager_ids' => [], 'expected_manager_ids' => [], 'responsibility' => 'employee', 'expected_responsibility' => 'hof'],
        ]])->assertUnprocessable();
        $this->assertDatabaseHas('department_memberships', ['user_id' => $head->id, 'department_id' => $department, 'responsibility' => 'hof']);
    }

    public function test_one_hof_can_belong_to_two_departments_and_audit_excludes_passwords(): void
    {
        $a = $this->department(); $b = $this->department('DIG');
        $payload = $this->payload(['memberships' => [
            ['department_id' => $a, 'responsibility' => 'hof', 'manager_ids' => []],
            ['department_id' => $b, 'responsibility' => 'hof', 'manager_ids' => []],
        ]]);
        $this->actingAs($this->admin())->postJson('/api/admin/users', $payload)->assertCreated()->assertJsonMissingPath('user.password');
        $this->assertDatabaseCount('department_memberships', 2);
        $audit = DB::table('admin_activity')->first();
        $this->assertSame('user.created', $audit->action);
        $this->assertStringNotContainsString('password', $audit->after_data);
        $this->assertStringNotContainsString($payload['password'], $audit->after_data);
    }

    public function test_department_title_is_saved_and_preserved_by_edits_from_older_clients(): void
    {
        $department = $this->department();
        $payload = $this->payload(['memberships' => [
            ['department_id' => $department, 'responsibility' => 'manager', 'responsibility_title' => 'Head of Data Center', 'manager_ids' => []],
        ]]);
        $id = $this->actingAs($this->admin())->postJson('/api/admin/users', $payload)->assertCreated()->json('user.id');
        unset($payload['memberships'][0]['responsibility_title']);
        $this->putJson('/api/admin/users/'.$id, $payload)->assertOk();
        $this->getJson('/api/admin/workspace')->assertOk()
            ->assertJsonPath('memberships.0.responsibility_title', 'Head of Data Center')
            ->assertJsonPath('memberships.0.responsibility', 'manager');
        $payload['memberships'][0]['responsibility_title'] = null;
        $this->putJson('/api/admin/users/'.$id, $payload)->assertOk();
        $this->assertDatabaseHas('department_memberships', ['user_id' => $id, 'responsibility_title' => null]);
    }

    public function test_multiple_reporting_managers_and_same_manager_across_departments_are_supported(): void
    {
        $a = $this->department(); $b = $this->department('DIG');
        $manager = User::factory()->create(); $hof = User::factory()->create();
        $this->membership($manager, $a); $this->membership($manager, $b); $this->membership($hof, $a, 'hof');
        $payload = $this->payload(['memberships' => [
            ['department_id' => $a, 'responsibility' => 'employee', 'manager_ids' => [$manager->id, $hof->id]],
            ['department_id' => $b, 'responsibility' => 'employee', 'manager_ids' => [$manager->id]],
        ]]);
        $this->actingAs($this->admin())->postJson('/api/admin/users', $payload)->assertCreated();
        $this->assertDatabaseCount('reporting_relationships', 3);
    }

    public function test_cross_department_reporting_is_rejected_without_partial_user_creation(): void
    {
        $a = $this->department(); $b = $this->department('FIN');
        $manager = User::factory()->create(); $this->membership($manager, $b);
        $payload = $this->payload(['memberships' => [['department_id' => $a, 'responsibility' => 'employee', 'manager_ids' => [$manager->id]]]]);
        $this->actingAs($this->admin())->postJson('/api/admin/users', $payload)->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['employee_number' => 'EMP100']);
        $this->assertDatabaseCount('admin_activity', 0);
    }

    public function test_reporting_cycles_and_self_reporting_are_rejected(): void
    {
        $d = $this->department(); $a = User::factory()->create(); $b = User::factory()->create();
        $this->membership($a, $d); $this->membership($b, $d);
        DB::table('reporting_relationships')->insert(['department_id' => $d, 'user_id' => $b->id, 'manager_id' => $a->id]);
        $payload = $this->payload(['email' => $a->email, 'memberships' => [['department_id' => $d, 'responsibility' => 'manager', 'manager_ids' => [$b->id]]]]);
        $this->actingAs($this->admin())->putJson('/api/admin/users/'.$a->id, $payload)->assertUnprocessable();
        $payload['memberships'][0]['manager_ids'] = [$a->id];
        $this->putJson('/api/admin/users/'.$a->id, $payload)->assertUnprocessable();
        $this->assertDatabaseCount('reporting_relationships', 1);
    }

    public function test_managers_with_reporting_people_cannot_be_removed_or_deactivated(): void
    {
        $d = $this->department(); $manager = User::factory()->create(); $employee = User::factory()->create();
        $this->membership($manager, $d);
        DB::table('reporting_relationships')->insert(['department_id' => $d, 'user_id' => $employee->id, 'manager_id' => $manager->id]);
        $this->actingAs($this->admin())->putJson('/api/admin/users/'.$manager->id, $this->payload(['email' => $manager->email, 'is_active' => false]))->assertUnprocessable();
        $this->assertTrue($manager->fresh()->is_active);
    }

    public function test_administrator_cannot_lock_out_their_current_account(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->putJson('/api/admin/users/'.$admin->id, $this->payload(['email' => $admin->email]))
            ->assertUnprocessable()->assertJsonValidationErrors('system_role');
        $this->assertSame('super_admin', $admin->fresh()->system_role);
    }

    public function test_unique_identifiers_and_duplicate_department_memberships_are_rejected(): void
    {
        $admin = $this->admin(); $d = $this->department();
        $this->actingAs($admin)->postJson('/api/admin/users', $this->payload())->assertCreated();
        $this->postJson('/api/admin/users', $this->payload())->assertUnprocessable()->assertJsonValidationErrors(['email', 'employee_number']);
        $m = ['department_id' => $d, 'responsibility' => 'employee', 'manager_ids' => []];
        $this->postJson('/api/admin/users', $this->payload(['email' => 'other@example.test', 'employee_number' => 'EMP101', 'memberships' => [$m, $m]]))->assertUnprocessable();
    }

    public function test_departments_can_be_created_updated_and_archived_when_empty(): void
    {
        $payload = ['name' => 'Human Resources', 'code' => 'HR', 'description' => 'People operations', 'color' => '#23796b', 'is_active' => true];
        $response = $this->actingAs($this->admin())->postJson('/api/admin/departments', $payload)->assertCreated();
        $id = $response->json('id');
        $this->putJson('/api/admin/departments/'.$id, [...$payload, 'is_active' => false])->assertOk();
        $this->assertDatabaseHas('departments', ['id' => $id, 'is_active' => false]);
        $this->assertDatabaseCount('admin_activity', 2);
    }

    public function test_department_with_members_cannot_be_archived(): void
    {
        $d = $this->department(); $this->membership(User::factory()->create(), $d, 'employee');
        $this->actingAs($this->admin())->putJson('/api/admin/departments/'.$d, [
            'name' => 'IT department', 'code' => 'IT', 'color' => '#23796b', 'is_active' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors('is_active');
    }

    public function test_edit_preserves_password_unless_replaced_and_persists_membership_changes(): void
    {
        $employee = User::factory()->create(); $hash = $employee->password; $d = $this->department();
        $payload = $this->payload(['email' => $employee->email, 'password' => '', 'memberships' => [
            ['department_id' => $d, 'responsibility' => 'hof', 'manager_ids' => []],
        ]]);
        $this->actingAs($this->admin())->putJson('/api/admin/users/'.$employee->id, $payload)->assertOk();
        $this->assertSame($hash, $employee->fresh()->password);
        $this->assertDatabaseHas('department_memberships', ['user_id' => $employee->id, 'department_id' => $d, 'responsibility' => 'hof']);
    }
}
