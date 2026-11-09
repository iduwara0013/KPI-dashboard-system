<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MdDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_md_can_login_view_empty_dashboard_and_cannot_change_work(): void
    {
        $md = User::factory()->create(['system_role' => 'md', 'is_active' => true]);
        $this->getJson('/api/md/workspace')->assertUnauthorized();
        $this->postJson('/api/login', ['email' => $md->email, 'password' => 'password'])->assertOk();
        $this->getJson('/api/md/workspace?year=2026')->assertOk()->assertJsonPath('year', 2026)->assertJsonCount(0, 'activities');
        foreach (['/api/admin/users', '/api/planning/plans', '/api/hof/assignments', '/api/hof/kpi-assignments', '/api/delivery/tasks/1/submit', '/api/delivery/submissions/1/review', '/api/allocations/draft'] as $endpoint) {
            $this->postJson($endpoint, [])->assertForbidden();
        }
        $this->getJson('/api/md/workspace?year=1900')->assertUnprocessable();
        $md->update(['is_active' => false]);
        $this->actingAs($md)->getJson('/api/md/workspace')->assertForbidden();
    }

    public function test_other_roles_cannot_open_executive_data(): void
    {
        foreach (['super_admin', 'plan_editor', 'member'] as $role) {
            $this->actingAs(User::factory()->create(['system_role' => $role, 'is_active' => true]))->getJson('/api/md/workspace')->assertForbidden();
        }
    }
}
