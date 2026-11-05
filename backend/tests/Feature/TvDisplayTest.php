<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TvDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_display_requires_login_and_restricts_department_access(): void
    {
        $this->seed();
        $this->getJson('/api/display/workspace')->assertUnauthorized();
        foreach (['EMP007', 'EMP004', 'EMP011', 'SYS001'] as $number) {
            $this->actingAs(User::where('employee_number', $number)->firstOrFail())->getJson('/api/display/workspace')->assertForbidden();
        }
        $hof = User::where('employee_number', 'EMP001')->firstOrFail();
        $response = $this->actingAs($hof)->getJson('/api/display/workspace?year=2026')->assertOk()->assertJsonCount(2, 'departments')->assertJsonCount(0, 'activities');
        $this->assertSame('Your published HOF responsibilities', $response->json('scope'));
        $this->getJson('/api/display/workspace?department=999')->assertForbidden();
        $md = User::factory()->create(['system_role' => 'md', 'is_active' => true]);
        $this->actingAs($md)->getJson('/api/display/workspace?year=2026')->assertOk()->assertJsonCount(4, 'departments');
        $this->getJson('/api/display/workspace?year=1900')->assertUnprocessable();
        $md->update(['is_active' => false]);
        $this->actingAs($md)->getJson('/api/display/workspace')->assertForbidden();
    }
}
