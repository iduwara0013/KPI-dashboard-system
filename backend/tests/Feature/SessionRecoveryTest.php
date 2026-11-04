<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_workspace_cannot_save_under_another_account(): void
    {
        $original = User::factory()->create(['system_role' => 'super_admin', 'is_active' => true]);
        $other = User::factory()->create(['system_role' => 'super_admin', 'is_active' => true]);
        $payload = ['name' => 'Test department', 'code' => 'TEST', 'color' => '#c5120c', 'is_active' => true];

        $this->actingAs($other)->withHeader('X-Workspace-User', (string) $original->id)
            ->postJson('/api/admin/departments', $payload)->assertUnauthorized();
        $this->assertDatabaseCount('departments', 0);
        $this->getJson('/api/session')->assertOk()->assertJsonPath('user.id', $other->id);
        $this->withHeader('X-Workspace-User', (string) $other->id)
            ->postJson('/api/admin/departments', $payload)->assertCreated();
    }

    public function test_identity_header_does_not_replace_authentication_or_permissions(): void
    {
        $member = User::factory()->create(['system_role' => 'member']);
        $this->withHeader('X-Workspace-User', (string) $member->id)->getJson('/api/admin/workspace')->assertUnauthorized();
        $this->actingAs($member)->getJson('/api/admin/workspace')->assertForbidden();
    }

    public function test_original_account_can_sign_in_again_despite_stale_identity_header(): void
    {
        $original = User::factory()->create(['system_role' => 'plan_editor', 'password' => 'Correct-password-123']);
        $other = User::factory()->create(['system_role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($other)->withHeader('X-Workspace-User', (string) $original->id)
            ->postJson('/api/login', ['email' => $original->email, 'password' => 'Correct-password-123'])
            ->assertOk()->assertJsonPath('user.id', $original->id);
        $this->getJson('/api/planning/workspace')->assertOk();
        $this->postJson('/api/logout')->assertOk();
        $this->assertGuest();
    }
}
