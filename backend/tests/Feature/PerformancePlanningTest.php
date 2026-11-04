<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PerformancePlanningTest extends TestCase
{
    use RefreshDatabase;

    private function editor(): User
    {
        return User::factory()->create(['system_role' => 'plan_editor', 'is_active' => true]);
    }

    private function hof(array $codes = ['IT', 'DIG']): array
    {
        $user = User::factory()->create(['system_role' => 'member', 'is_active' => true]);
        $departments = [];
        foreach ($codes as $code) {
            $id = DB::table('departments')->insertGetId([
                'name' => $code.' department', 'code' => $code, 'color' => '#c5120c', 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $departments[] = $id;
            DB::table('department_memberships')->insert([
                'user_id' => $user->id, 'department_id' => $id, 'responsibility' => 'hof',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return [$user, $departments];
    }

    private function payload(User $hof, array $departments): array
    {
        return [
            'code' => 'PLAN-2026-IT', 'title' => 'IT and digital performance plan', 'owner_id' => $hof->id, 'year' => 2026,
            'kpas' => array_map(fn (int $department, int $i): array => [
                'code' => 'KPA-'.$i, 'title' => 'Responsibility '.$i, 'department_id' => $department,
                'weight' => $i === 0 ? 0.6 : 0.4, 'category' => 'Finance', 'kpis' => [[
                    'code' => 'KPI-'.$i, 'title' => 'Budget utilization', 'weight' => 1,
                    'target_value' => 30, 'target_unit' => 'percent', 'frequency' => 'Monthly', 'direction' => 'higher',
                    'activities' => [
                        ['code' => 'ACT-'.$i.'A', 'title' => 'Prepare specifications', 'weight' => 0.25],
                        ['code' => 'ACT-'.$i.'B', 'title' => 'Complete procurement', 'weight' => 0.75, 'due_date' => '2026-12-01'],
                    ],
                ]],
            ], $departments, array_keys($departments)),
        ];
    }

    private function createPlan(array $payload): int
    {
        return $this->postJson('/api/planning/plans', $payload)->assertCreated()->json('id');
    }

    public function test_plan_editor_login_opens_planning_but_not_administration(): void
    {
        $this->getJson('/api/planning/workspace')->assertUnauthorized();
        $editor = $this->editor();
        $this->postJson('/api/login', ['email' => $editor->email, 'password' => 'password'])
            ->assertOk()->assertJsonPath('user.system_role', 'plan_editor')->assertJsonMissingPath('user.password');
        $this->getJson('/api/planning/workspace')->assertOk()->assertJsonPath('can_edit', true);
        $this->getJson('/api/admin/workspace')->assertForbidden();
        $this->postJson('/api/admin/users', [])->assertForbidden();
        $this->postJson('/api/logout')->assertOk();
        $this->getJson('/api/planning/workspace')->assertUnauthorized();
    }

    public function test_only_active_planning_roles_and_active_hofs_have_access(): void
    {
        $member = User::factory()->create(['system_role' => 'member']);
        $this->postJson('/api/login', ['email' => $member->email, 'password' => 'password'])->assertForbidden();
        $this->assertGuest();
        [$hof, $departments] = $this->hof();
        $this->postJson('/api/login', ['email' => $hof->email, 'password' => 'password'])->assertOk();
        $this->getJson('/api/planning/workspace')->assertOk()->assertJsonPath('can_edit', false);
        $this->postJson('/api/planning/plans', [])->assertForbidden();
        $this->putJson('/api/planning/plans/1', [])->assertForbidden();
        $this->postJson('/api/planning/plans/1/publish', [])->assertForbidden();
        $hof->update(['is_active' => false]);
        $this->actingAs($hof)->getJson('/api/planning/workspace')->assertForbidden();
        $editor = $this->editor();
        $editor->update(['is_active' => false]);
        $this->actingAs($editor)->getJson('/api/planning/workspace')->assertForbidden();
        $admin = User::factory()->create(['system_role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($admin)->getJson('/api/planning/workspace')->assertForbidden();
        $this->postJson('/api/planning/plans', [])->assertForbidden();
        $this->getJson('/api/planning/plans/1')->assertForbidden();
        $this->putJson('/api/planning/plans/1', [])->assertForbidden();
        $this->postJson('/api/planning/plans/1/publish', [])->assertForbidden();
        $this->getJson('/api/admin/workspace')->assertOk();
        DB::table('department_memberships')->where('user_id', $hof->id)->update(['user_id' => $admin->id]);
        $this->getJson('/api/planning/workspace')->assertForbidden();
    }

    public function test_incomplete_drafts_are_saved_but_must_be_complete_to_publish(): void
    {
        [$hof, $departments] = $this->hof();
        $this->actingAs($this->editor());
        $payload = $this->payload($hof, $departments);
        $payload['kpas'] = [];
        $id = $this->createPlan($payload);
        $this->assertDatabaseHas('performance_plans', ['id' => $id, 'status' => 'draft', 'revision' => 1]);
        $this->getJson('/api/planning/plans/'.$id)->assertOk()->assertJsonCount(1, 'issues');
        $this->postJson('/api/planning/plans/'.$id.'/publish', ['revision' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('performance_plan_versions', 1);
        $this->actingAs($hof)->getJson('/api/planning/plans/'.$id)->assertForbidden();
        $this->getJson('/api/planning/workspace')->assertJsonCount(0, 'plans');
    }

    public function test_expired_editor_session_can_reauthenticate_and_retry_draft_creation_once(): void
    {
        [$hof, $departments] = $this->hof();
        $editor = $this->editor();
        $payload = $this->payload($hof, $departments);
        $payload['kpas'] = [];
        $this->withHeader('X-Workspace-User', (string) $editor->id);
        $this->postJson('/api/planning/plans', $payload)->assertUnauthorized();
        $this->assertDatabaseCount('performance_plans', 0);

        $this->postJson('/api/login', ['email' => $editor->email, 'password' => 'password'])->assertOk();
        $this->postJson('/api/planning/plans', $payload)->assertCreated();
        $this->assertDatabaseCount('performance_plans', 1);
        $this->assertDatabaseHas('performance_plans', ['owner_id' => $hof->id, 'status' => 'draft']);
    }

    public function test_one_personal_plan_per_hof_year_and_department_scope_are_enforced(): void
    {
        [$hof, $departments] = $this->hof();
        [$other, $otherDepartments] = $this->hof(['FIN']);
        $this->actingAs($this->editor());
        $payload = $this->payload($hof, $departments);
        $invalid = $payload;
        $invalid['kpas'][0]['department_id'] = $otherDepartments[0];
        $this->postJson('/api/planning/plans', $invalid)->assertUnprocessable()->assertJsonValidationErrors('kpas');
        $this->assertDatabaseCount('performance_plans', 0);
        $invalid = $payload;
        $invalid['kpas'][1]['code'] = 'kpa-0';
        $this->postJson('/api/planning/plans', $invalid)->assertUnprocessable();
        $this->createPlan($payload);
        $payload['code'] = 'PLAN-ANOTHER';
        $this->postJson('/api/planning/plans', $payload)->assertUnprocessable()->assertJsonValidationErrors('owner_id');
        $payload['owner_id'] = $other->id;
        $payload['code'] = 'PLAN-2026-IT';
        $this->postJson('/api/planning/plans', $payload)->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertDatabaseCount('performance_plans', 1);
    }

    public function test_each_weight_group_and_required_kpi_fields_are_validated_independently(): void
    {
        [$hof, $departments] = $this->hof();
        $this->actingAs($this->editor());
        $payload = $this->payload($hof, $departments);
        $payload['kpas'][0]['weight'] = 0.1;
        $payload['kpas'][0]['kpis'][0]['weight'] = 0.8;
        $payload['kpas'][1]['kpis'][0]['target_value'] = null;
        $payload['kpas'][1]['kpis'][0]['activities'][0]['weight'] = null;
        $id = $this->createPlan($payload);
        $this->getJson('/api/planning/plans/'.$id)->assertJsonCount(4, 'issues');
        $this->postJson('/api/planning/plans/'.$id.'/publish', ['revision' => 1])->assertUnprocessable();
        $payload = $this->payload($hof, $departments);
        $payload['revision'] = 1;
        $payload['kpas'][1]['kpis'][0]['target_value'] = 0;
        $payload['kpas'][1]['kpis'][0]['direction'] = 'lower';
        $this->putJson('/api/planning/plans/'.$id, $payload)->assertOk();
        $this->postJson('/api/planning/plans/'.$id.'/publish', ['revision' => 2])->assertOk();
        $this->assertDatabaseHas('performance_plans', ['id' => $id, 'published_revision' => 3, 'status' => 'published']);
    }

    public function test_hof_sees_only_own_last_published_snapshot_while_editor_revises(): void
    {
        [$hof, $departments] = $this->hof();
        [$other] = $this->hof(['FIN']);
        $editor = $this->editor();
        $this->actingAs($editor);
        $id = $this->createPlan($this->payload($hof, $departments));
        $this->postJson('/api/planning/plans/'.$id.'/publish', ['revision' => 1])->assertOk();
        $this->actingAs($hof)->getJson('/api/planning/plans/'.$id)
            ->assertOk()->assertJsonPath('plan.revision', 2)->assertJsonCount(1, 'versions')->assertJsonPath('can_edit', false);
        $this->getJson('/api/planning/workspace')->assertJsonCount(1, 'plans')->assertJsonCount(1, 'owners')->assertJsonCount(2, 'departments');
        $this->actingAs($other)->getJson('/api/planning/plans/'.$id)->assertForbidden();
        $this->getJson('/api/planning/workspace')->assertJsonCount(0, 'plans');
        $this->actingAs($editor);
        $draft = $this->getJson('/api/planning/plans/'.$id)->json('plan');
        $draft['kpas'][0]['kpis'][0]['target_value'] = 45;
        $draft['title'] = 'Revised personal plan';
        $this->putJson('/api/planning/plans/'.$id, $draft)->assertOk();
        $this->actingAs($hof)->getJson('/api/planning/plans/'.$id)
            ->assertJsonPath('plan.title', 'IT and digital performance plan')->assertJsonPath('plan.kpas.0.kpis.0.target_value', 30);
        $this->getJson('/api/planning/workspace')->assertJsonPath('plans.0.status', 'published');
        $this->actingAs($editor)->postJson('/api/planning/plans/'.$id.'/publish', ['revision' => 3])->assertOk();
        $this->actingAs($hof)->getJson('/api/planning/plans/'.$id)
            ->assertJsonPath('plan.title', 'Revised personal plan')->assertJsonPath('plan.kpas.0.kpis.0.target_value', 45)->assertJsonCount(2, 'versions');
    }

    public function test_no_op_saves_preserve_ids_timestamps_and_revisions_and_conflicts_are_rejected(): void
    {
        [$hof, $departments] = $this->hof();
        $this->actingAs($this->editor());
        $id = $this->createPlan($this->payload($hof, $departments));
        $before = DB::table('performance_plan_items')->orderBy('id')->get()->toJson();
        $this->getJson('/api/planning/plans/'.$id)->assertJsonPath('plan.kpas.0.kpis.0.activities.1.due_date', '2026-12-01');
        $draft = $this->getJson('/api/planning/plans/'.$id)->json('plan');
        $this->travel(5)->minutes();
        $this->putJson('/api/planning/plans/'.$id, $draft)->assertOk();
        $this->assertSame($before, DB::table('performance_plan_items')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('performance_plan_versions', 1);
        $draft['kpas'][0]['kpis'][0]['activities'][0]['title'] = 'New specifications';
        $this->putJson('/api/planning/plans/'.$id, $draft)->assertOk();
        $this->putJson('/api/planning/plans/'.$id, $draft)->assertConflict();
        $this->postJson('/api/planning/plans/'.$id.'/publish', ['revision' => 1])->assertConflict();
        $changes = json_decode(DB::table('performance_plan_versions')->where('version', 2)->value('changes'), true);
        $this->assertCount(1, $changes);
        $this->assertSame('ACT-0A', $changes[0]['code']);
        $this->assertSame(['before' => 'Prepare specifications', 'after' => 'New specifications'], $changes[0]['fields']['title']);
        $this->assertSame(8, DB::table('performance_plan_items')->count());
    }

    public function test_removal_retains_items_and_saved_history_and_cannot_change_item_kind(): void
    {
        [$hof, $departments] = $this->hof();
        $this->actingAs($this->editor());
        $id = $this->createPlan($this->payload($hof, $departments));
        $draft = $this->getJson('/api/planning/plans/'.$id)->json('plan');
        array_pop($draft['kpas']);
        $this->putJson('/api/planning/plans/'.$id, $draft)->assertOk();
        $this->assertDatabaseHas('performance_plan_items', ['plan_id' => $id, 'code' => 'KPA-1', 'is_active' => false]);
        $this->assertDatabaseCount('performance_plan_items', 8);
        $this->getJson('/api/planning/plans/'.$id)->assertJsonCount(1, 'plan.kpas')->assertJsonCount(2, 'versions');
        $original = json_decode(DB::table('performance_plan_versions')->where('version', 1)->value('snapshot'), true);
        $this->assertCount(2, $original['kpas']);
        $draft['revision'] = 2;
        $draft['kpas'][0]['kpis'][0]['code'] = 'KPA-1';
        $this->putJson('/api/planning/plans/'.$id, $draft)->assertUnprocessable();
        $this->assertDatabaseCount('performance_plan_versions', 2);
        $draft['owner_id'] = $this->editor()->id;
        $this->putJson('/api/planning/plans/'.$id, $draft)->assertUnprocessable();
    }
}
