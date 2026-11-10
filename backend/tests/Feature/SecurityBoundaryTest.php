<?php

namespace Tests\Feature;

use App\Http\Middleware\ProductionSecurity;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_protected_workspaces_reject_guests_even_with_a_forged_identity_header(): void
    {
        $this->withHeader('X-Workspace-User', '1');
        foreach (['admin', 'planning', 'delivery', 'hof', 'md', 'display', 'allocations', 'kpi-results'] as $workspace) {
            $this->getJson('/api/'.$workspace.'/workspace')->assertUnauthorized();
        }
        $this->getJson('/api/notifications')->assertUnauthorized();
        $this->getJson('/api/organization-chart')->assertUnauthorized();
    }

    public function test_login_is_throttled_after_five_failed_attempts(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/login', ['email' => 'missing@example.test', 'password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson('/api/login', ['email' => 'missing@example.test', 'password' => 'wrong'])
            ->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_inactive_accounts_cannot_login_or_use_existing_privileged_workspaces(): void
    {
        $user = User::factory()->create(['system_role' => 'super_admin', 'is_active' => false]);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertUnprocessable();
        $this->actingAs($user);
        foreach (['admin', 'planning', 'delivery', 'md', 'display'] as $workspace) {
            $this->getJson('/api/'.$workspace.'/workspace')->assertForbidden();
        }
        $this->getJson('/api/notifications')->assertForbidden();
        $this->postJson('/api/notifications/read-all')->assertForbidden();
        $this->getJson('/api/organization-chart')->assertForbidden();
    }

    public function test_sql_like_login_input_cannot_authenticate_and_password_fields_are_not_disclosed(): void
    {
        $user = User::factory()->create(['system_role' => 'super_admin', 'is_active' => true]);
        $this->postJson('/api/login', ['email' => "' OR 1=1 --", 'password' => 'password'])->assertUnprocessable();
        $this->assertGuest();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()->assertJsonMissingPath('user.password')->assertJsonMissingPath('user.remember_token');
        $this->getJson('/api/admin/workspace')->assertOk()
            ->assertJsonMissingPath('users.0.password')->assertJsonMissingPath('users.0.remember_token');
    }

    public function test_cross_site_mutations_need_a_valid_csrf_token_with_the_test_bypass_disabled(): void
    {
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $user = User::factory()->create(['system_role' => 'super_admin', 'is_active' => true]);
        $payload = ['name' => 'Security test', 'code' => 'SECURITY', 'color' => '#23796b', 'is_active' => true];
        $this->actingAs($user)->withSession(['_token' => 'security-test-token']);
        $this->withHeader('Sec-Fetch-Site', 'cross-site')->postJson('/api/admin/departments', $payload)->assertStatus(419);
        $this->withHeader('X-CSRF-TOKEN', 'incorrect')->postJson('/api/admin/departments', $payload)->assertStatus(419);
        $this->assertDatabaseCount('departments', 0);
        $this->withHeader('X-CSRF-TOKEN', 'security-test-token')->postJson('/api/admin/departments', $payload)->assertCreated();
    }

    public function test_api_guests_receive_json_401_without_a_json_accept_header(): void
    {
        $this->get('/api/admin/workspace')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
        $this->withHeader('Accept', 'text/html')->get('/api/delivery/workspace')->assertUnauthorized();
    }

    public function test_password_change_invalidates_a_previously_authenticated_session(): void
    {
        $user = User::factory()->create(['system_role' => 'plan_editor', 'is_active' => true]);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $user->update(['password' => 'New security passphrase 2026']);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/planning/workspace')->assertUnauthorized();
        $this->getJson('/api/session')->assertOk()->assertJsonPath('user', null);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'New security passphrase 2026'])->assertOk();
    }

    public function test_existing_sessions_without_a_fingerprint_require_a_new_login(): void
    {
        $user = User::factory()->create(['system_role' => 'plan_editor', 'is_active' => true]);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $this->app['session']->forget('account_fingerprint');
        $this->getJson('/api/session')->assertOk()->assertJsonPath('user', null);
        $this->getJson('/api/planning/workspace')->assertUnauthorized();
    }

    public function test_api_responses_disable_caching_and_frame_embedding(): void
    {
        $this->getJson('/api/session')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
        $this->assertStringContainsString('no-store', $this->getJson('/api/session')->headers->get('Cache-Control'));
    }

    public function test_production_blocks_insecure_configuration_and_requires_https(): void
    {
        $this->app->instance('env', 'production');
        config(['app.debug' => true]);
        $this->getJson('/api/session')->assertStatus(503)->assertJsonMissingPath('exception');
        $this->productionConfiguration();
        $this->assertSame([], ProductionSecurity::configurationIssues());
        $this->getJson('http://office.example/api/session')->assertStatus(503);
        $this->getJson('https://office.example/api/session')->assertOk()->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_production_preflight_rejects_unverified_database_certificates(): void
    {
        $connection = config('database.default');
        $this->productionConfiguration();
        config(['database.default' => 'sqlsrv', 'database.connections.sqlsrv.encrypt' => 'yes', 'database.connections.sqlsrv.trust_server_certificate' => true]);
        $this->assertContains('SQL Server must verify a trusted server certificate.', ProductionSecurity::configurationIssues());
        config(['database.connections.sqlsrv.trust_server_certificate' => false]);
        $this->assertSame([], ProductionSecurity::configurationIssues());
        config(['database.connections.sqlsrv.encrypt' => 'no']);
        $this->assertContains('SQL Server encryption must be enabled.', ProductionSecurity::configurationIssues());
        config(['database.default' => $connection]);
    }

    public function test_security_preflight_command_fails_until_production_settings_are_ready(): void
    {
        $this->artisan('security:check')->assertFailed();
        $this->app->instance('env', 'production');
        $this->productionConfiguration();
        $this->artisan('security:check')->assertSuccessful();
    }

    private function productionConfiguration(): void
    {
        config([
            'app.debug' => false, 'app.url' => 'https://office.example',
            'session.secure' => true, 'session.http_only' => true, 'session.same_site' => 'lax',
            'session.driver' => 'database', 'session.lifetime' => 30, 'cache.default' => 'database',
            'database.connections.sqlsrv.encrypt' => 'yes', 'database.connections.sqlsrv.trust_server_certificate' => false,
        ]);
    }
}
