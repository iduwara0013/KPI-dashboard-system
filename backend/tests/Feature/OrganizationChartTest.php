<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationChartTest extends TestCase
{
    use RefreshDatabase;

    public function test_chart_shows_real_relationships_and_scopes_member_visibility(): void
    {
        $this->seed();
        $employee = User::where('employee_number', 'EMP007')->firstOrFail();
        $sibling = User::where('employee_number', 'EMP008')->firstOrFail();
        $manager = User::where('employee_number', 'EMP004')->firstOrFail();
        $hof = User::where('employee_number', 'EMP001')->firstOrFail();
        $this->getJson('/api/organization-chart')->assertUnauthorized();
        $response = $this->actingAs($employee)->getJson('/api/organization-chart')->assertOk();
        $ids = collect($response->json('people'))->pluck('id');
        $this->assertTrue($ids->contains($employee->id));
        $this->assertTrue($ids->contains($manager->id));
        $this->assertTrue($ids->contains($hof->id));
        $this->assertFalse($ids->contains($sibling->id));
        $response->assertJsonMissingPath('people.0.email')->assertJsonMissingPath('people.0.password');
        $response = $this->actingAs($manager)->getJson('/api/organization-chart')->assertOk();
        $this->assertTrue(collect($response->json('people'))->pluck('id')->contains($sibling->id));
        $this->assertCount(1, $response->json('departments'));
        $md = User::factory()->create(['system_role' => 'md', 'is_active' => true]);
        $this->actingAs($md)->getJson('/api/organization-chart')->assertOk()->assertJsonCount(4, 'departments');
        $md->update(['is_active' => false]);
        $this->actingAs($md)->getJson('/api/organization-chart')->assertForbidden();
    }
}
