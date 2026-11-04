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
        $this->putJson('/api/admin/departments/1', [])->assertForbidden();
        $member->update(['system_role' => 'super_admin', 'is_active' => false]);
        $this->actingAs($member)->getJson('/api/admin/workspace')->assertForbidden();
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
