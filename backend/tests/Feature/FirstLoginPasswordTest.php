<?php

namespace Tests\Feature;

use App\Http\Controllers\EmployeeDirectoryImportController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class FirstLoginPasswordTest extends TestCase
{
    use RefreshDatabase;

    private function employee(): User
    {
        $this->seed();
        $user = User::where('employee_number', 'EMP007')->firstOrFail();
        $user->forceFill(['employee_number' => '11473', 'password' => '11473', 'must_change_password' => true])->save();

        return $user;
    }

    public function test_first_login_is_restricted_until_password_changes_then_epf_password_stops_working(): void
    {
        $user = $this->employee();
        $this->postJson('/api/login', ['email' => '11473', 'password' => '11473'])->assertOk()->assertJsonPath('user.must_change_password', true);
        foreach (['delivery/workspace', 'extra-work/workspace', 'organization-chart', 'notifications', 'display/workspace'] as $path) {
            $this->getJson('/api/'.$path)->assertStatus(423)->assertJsonPath('code', 'password_change_required');
        }
        $this->postJson('/api/extra-work/assignments', [])->assertStatus(423);
        $this->getJson('/api/session')->assertOk()->assertJsonPath('user.must_change_password', true);
        $oldFingerprint = $user->sessionFingerprint();
        $oldSession = session()->getId();
        $this->postJson('/api/password/change', ['current_password' => '11473', 'password' => 'My-New-Password!', 'password_confirmation' => 'My-New-Password!'])
            ->assertOk()->assertJsonPath('user.must_change_password', false);
        $this->assertNotSame($oldSession, session()->getId());
        $user->refresh();
        $this->assertNotSame($oldFingerprint, $user->sessionFingerprint());
        $this->assertNotNull($user->password_changed_at);
        $this->assertTrue(Hash::check('My-New-Password!', $user->password));
        $this->assertFalse(Hash::check('11473', $user->password));
        $this->getJson('/api/delivery/workspace')->assertOk();
        $this->postJson('/api/logout')->assertOk();
        $this->postJson('/api/login', ['email' => '11473', 'password' => '11473'])->assertUnprocessable();
        $this->postJson('/api/login', ['email' => '11473', 'password' => 'My-New-Password!'])->assertOk()->assertJsonPath('user.must_change_password', false);
        $this->assertDatabaseHas('admin_activity', ['actor_id' => $user->id, 'action' => 'password.changed']);
        $this->assertStringNotContainsString('My-New-Password!', json_encode(DB::table('admin_activity')->get()));
    }

    public function test_password_change_checks_current_password_confirmation_length_and_reuse(): void
    {
        $user = $this->employee();
        $this->actingAs($user);
        foreach ([
            ['current_password' => 'wrong', 'password' => 'New-123!', 'password_confirmation' => 'New-123!'],
            ['current_password' => '11473', 'password' => 'New-123!', 'password_confirmation' => 'Mismatch'],
            ['current_password' => '11473', 'password' => '12345', 'password_confirmation' => '12345'],
        ] as $payload) {
            $this->postJson('/api/password/change', $payload)->assertUnprocessable();
            $this->assertTrue($user->fresh()->must_change_password);
        }
        $user->forceFill(['employee_number' => '123456', 'password' => '123456'])->save();
        $this->postJson('/api/password/change', ['current_password' => '123456', 'password' => '123456', 'password_confirmation' => '123456'])->assertUnprocessable();
        $this->postJson('/api/password/change', ['current_password' => '123456', 'password' => 'abc789', 'password_confirmation' => 'abc789'])->assertOk();
    }

    public function test_md_can_change_own_password_but_read_only_rules_still_apply_afterwards(): void
    {
        $user = User::factory()->create(['system_role' => 'md', 'employee_number' => '60367', 'password' => '60367', 'is_active' => true]);
        $user->forceFill(['must_change_password' => true])->save();
        $this->actingAs($user)->postJson('/api/password/change', ['current_password' => '60367', 'password' => 'MD-new-123', 'password_confirmation' => 'MD-new-123'])
            ->assertOk()->assertJsonPath('user.must_change_password', false);
        $this->postJson('/api/extra-work/assignments', [])->assertForbidden();
        $this->getJson('/api/md/workspace')->assertOk();
    }

    public function test_initialization_is_scoped_keeps_admin_password_and_requires_change_even_when_later_activated(): void
    {
        $this->seed();
        $admin = User::where('system_role', 'super_admin')->firstOrFail();
        $adminHash = $admin->password;
        $path = storage_path('framework/testing/first-login-'.Str::random(10).'.csv');
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, "epf,firstname,lastname,contact,function,personnel_area,designation\n11473,A,PERSON,,IT,Kolonnawa,ENGINEER\n");
        $user = User::where('employee_number', 'EMP007')->firstOrFail();
        $user->forceFill(['employee_number' => '11473', 'is_active' => false])->save();
        $other = User::where('employee_number', 'EMP008')->firstOrFail();
        $otherHash = $other->password;
        $directory = null;
        try {
            $importer = app(EmployeeDirectoryImportController::class);
            $this->assertFalse($importer->initializePasswords($path)['applied']);
            $this->assertFalse($user->fresh()->must_change_password);
            $result = $importer->initializePasswords($path, true);
            $directory = dirname($result['backup']);
            $this->assertSame(1, $result['verified_accounts']);
            $this->assertTrue(Hash::check('11473', $user->fresh()->password));
            $this->assertTrue($user->fresh()->must_change_password);
            $this->assertFalse($user->fresh()->is_active);
            $this->assertSame($adminHash, $admin->fresh()->password);
            $this->assertSame($otherHash, $other->fresh()->password);
        } finally {
            File::delete($path);
            if ($directory) {
                File::deleteDirectory($directory);
            }
        }
    }
}
