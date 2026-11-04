<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MixedAssignmentsTest extends TestCase
{
    use RefreshDatabase;

    private User $hof;

    private User $manager;

    private User $employee;

    private User $editor;

    private int $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->hof = $this->user('EMP001');
        $this->manager = $this->user('EMP004');
        $this->employee = $this->user('EMP007');
        $this->editor = $this->user('EMP011');
        $department = DB::table('departments')->where('code', 'IT')->value('id');
        $payload = ['code' => 'MIXED-2026', 'title' => 'Mixed responsibility plan', 'year' => 2026, 'owner_id' => $this->hof->id,
            'kpas' => [['code' => 'KPA-IT', 'title' => 'Infrastructure', 'department_id' => $department, 'weight' => 1, 'kpis' => [[
                'code' => 'KPI-IT', 'title' => 'Project completion', 'weight' => 1, 'target_value' => 100, 'target_unit' => 'percent', 'frequency' => 'Quarterly', 'direction' => 'higher',
                'activities' => [['code' => 'ACT-A', 'title' => 'Network upgrade', 'weight' => 0.4], ['code' => 'ACT-B', 'title' => 'Server upgrade', 'weight' => 0.6]],
            ]]]]];
        $this->actingAs($this->editor);
        $this->plan = $this->postJson('/api/planning/plans', $payload)->assertCreated()->json('id');
        $this->postJson('/api/planning/plans/'.$this->plan.'/publish', ['revision' => 1])->assertOk();
        $this->actingAs($this->hof);
    }

    private function user(string $number): User
    {
        return User::where('employee_number', $number)->firstOrFail();
    }

    private function kpiPayload(User $recipient, int $version = 0): array
    {
        return ['plan_id' => $this->plan, 'kpi_code' => 'KPI-IT', 'source_revision' => 2, 'version' => $version, 'assignee_id' => $recipient->id];
    }

    private function assignActivity(string $code, User $recipient, int $version = 0, array $extra = []): int
    {
        $this->actingAs($this->hof)->postJson('/api/hof/assignments', ['plan_id' => $this->plan, 'activity_code' => $code, 'source_revision' => 2, 'version' => $version, 'assignee_id' => $recipient->id, ...$extra])->assertOk();

        return (int) DB::table('work_assignments')->where('plan_id', $this->plan)->where('activity_code', $code)->value('id');
    }

    private function allocation(User $owner): array
    {
        return $this->actingAs($owner)->getJson('/api/allocations/people/'.$owner->id.'/2026')->assertOk()->json();
    }

    private function saveAllocation(User $owner, array $detail, array $weights): void
    {
        $entries = array_map(fn ($entry, $weight) => ['key' => $entry['key'], 'token' => $entry['token'], 'weight' => $weight], $detail['entries'], $weights);
        $this->actingAs($owner)->postJson('/api/allocations/draft', ['owner_id' => $owner->id, 'year' => 2026, 'version' => $detail['version'], 'entries' => $entries])->assertOk();
    }

    private function publishAllocation(User $owner): array
    {
        $detail = $this->allocation($owner);
        $this->actingAs($this->editor)->postJson('/api/allocations/'.$detail['id'].'/publish', ['version' => $detail['version'], 'review_note' => 'Reviewed across all responsibilities.'])->assertOk();

        return $this->allocation($owner);
    }

    public function test_full_kpi_creates_one_linked_kpa_and_preserves_single_leaf_work_tree(): void
    {
        $this->postJson('/api/hof/kpi-assignments', $this->kpiPayload($this->manager))->assertOk();
        $this->assertDatabaseCount('kpi_delegations', 1);
        $this->assertDatabaseCount('work_assignments', 2);
        $this->assertDatabaseCount('delivery_tasks', 0);
        $this->actingAs($this->manager)->getJson('/api/delivery/workspace')->assertJsonCount(1, 'delegated_kpis')->assertJsonCount(2, 'managed');
        $personal = $this->allocation($this->manager);
        $this->assertCount(1, $personal['entries']);
        $this->assertSame('Linked KPA · delegated KPI', $personal['entries'][0]['kind']);
        $assignment = DB::table('work_assignments')->where('activity_code', 'ACT-A')->first();
        $this->assignActivity('ACT-A', $this->employee, (int) $assignment->version);
        $this->assertDatabaseCount('work_assignments', 2);
        $this->assertDatabaseCount('delivery_tasks', 1);
        $this->actingAs($this->manager)->getJson('/api/delivery/workspace')->assertJsonCount(1, 'managed')->assertJsonCount(2, 'delegated_kpis.0.activities');
        $this->assertCount(1, $this->allocation($this->manager)['entries']);
        $this->assertSame('Direct HOF work', $this->allocation($this->employee)['entries'][0]['kind']);
    }

    public function test_direct_employee_work_is_reviewed_by_hof_and_rolls_up_once(): void
    {
        $assignment = $this->assignActivity('ACT-A', $this->employee, 0, ['proposed_personal_weight' => 0.2]);
        $task = DB::table('delivery_tasks')->where('assignment_id', $assignment)->first();
        $this->assertSame($this->hof->id, (int) $task->reviewer_id);
        $this->assertEquals(1, $task->weight);
        $personal = $this->allocation($this->employee);
        $this->assertSame(0.2, $personal['entries'][0]['suggested_weight']);
        $this->actingAs($this->employee)->getJson('/api/delivery/workspace')->assertJsonCount(1, 'tasks')->assertJsonCount(0, 'managed');
        $this->postJson('/api/delivery/tasks/'.$task->id.'/submit', ['version' => 1, 'evidence' => 'Network upgrade completed and checked.'])->assertOk();
        $submission = DB::table('task_submissions')->value('id');
        $this->actingAs($this->manager)->postJson('/api/delivery/submissions/'.$submission.'/review', ['version' => 2, 'decision' => 'approved'])->assertForbidden();
        $this->actingAs($this->hof)->postJson('/api/delivery/submissions/'.$submission.'/review', ['version' => 2, 'decision' => 'approved'])->assertOk();
        $this->getJson('/api/hof/workspace')->assertJsonPath('activities.0.assignment.confirmed_progress', 100)->assertJsonPath('activities.1.assignment', null);
        $this->saveAllocation($this->employee, $this->allocation($this->employee), [1]);
        $published = $this->publishAllocation($this->employee);
        $this->assertSame(100, $published['score']['confirmed_completion']);
    }

    public function test_full_kpi_can_go_directly_to_employee_without_duplicate_personal_entries(): void
    {
        $this->postJson('/api/hof/kpi-assignments', $this->kpiPayload($this->employee))->assertOk();
        $this->assertDatabaseCount('delivery_tasks', 2);
        $this->actingAs($this->employee)->getJson('/api/delivery/workspace')->assertJsonCount(2, 'tasks')->assertJsonCount(1, 'delegated_kpis')->assertJsonCount(0, 'managed');
        $this->assertCount(1, $this->allocation($this->employee)['entries']);
        $assignment = DB::table('work_assignments')->first();
        $this->putJson('/api/delivery/assignments/'.$assignment->id.'/tasks', ['version' => $assignment->version, 'tasks' => [['title' => 'Tampered task', 'assignee_id' => $this->employee->id, 'weight' => 1]]])->assertForbidden();
    }

    public function test_individual_overrides_survive_kpi_delegation_and_can_restore_inheritance(): void
    {
        $id = $this->assignActivity('ACT-A', $this->hof);
        $this->putJson('/api/hof/assignments/'.$id.'/progress', ['version' => 1, 'reported_progress' => 40, 'progress_note' => 'Own coordination in progress.'])->assertOk();
        $this->postJson('/api/hof/kpi-assignments', $this->kpiPayload($this->manager))->assertOk();
        $this->assertDatabaseHas('work_assignments', ['id' => $id, 'assignee_id' => $this->hof->id, 'reported_progress' => 40, 'ownership_mode' => 'activity']);
        $version = DB::table('work_assignments')->where('id', $id)->value('version');
        $this->assignActivity('ACT-A', $this->hof, (int) $version, ['use_kpi_owner' => true]);
        $this->assertDatabaseHas('work_assignments', ['id' => $id, 'assignee_id' => $this->manager->id, 'ownership_mode' => 'kpi', 'reported_progress' => 0, 'delivery_generation' => 2]);
    }

    public function test_assignment_scope_and_role_changes_are_enforced(): void
    {
        foreach ([$this->user('EMP009'), $this->user('EMP006')] as $recipient) {
            $this->postJson('/api/hof/kpi-assignments', $this->kpiPayload($recipient))->assertUnprocessable();
        }
        $this->actingAs($this->manager)->postJson('/api/hof/kpi-assignments', $this->kpiPayload($this->manager))->assertForbidden();
        $this->actingAs($this->editor)->postJson('/api/hof/kpi-assignments', $this->kpiPayload($this->manager))->assertForbidden();
        $this->actingAs($this->hof)->postJson('/api/hof/kpi-assignments', $this->kpiPayload($this->manager))->assertOk();
        $this->postJson('/api/hof/kpi-assignments', $this->kpiPayload($this->manager))->assertConflict();
        DB::table('department_memberships')->where('user_id', $this->manager->id)->update(['responsibility' => 'employee']);
        $this->actingAs($this->manager)->getJson('/api/delivery/workspace')->assertJsonPath('delegated_kpis.0.valid', false)->assertJsonCount(0, 'managed');
        $this->assertFalse($this->allocation($this->manager)['entries'][0]['valid']);
    }

    public function test_personal_allocation_requires_full_100_percent_and_plan_editor_approval(): void
    {
        $this->assignActivity('ACT-A', $this->manager);
        $detail = $this->allocation($this->manager);
        $this->saveAllocation($this->manager, $detail, [0.4]);
        $detail = $this->allocation($this->manager);
        $this->postJson('/api/allocations/'.$detail['id'].'/publish', ['version' => $detail['version']])->assertForbidden();
        $this->actingAs($this->editor)->postJson('/api/allocations/'.$detail['id'].'/publish', ['version' => $detail['version']])->assertUnprocessable();
        $this->saveAllocation($this->manager, $detail, [1]);
        $published = $this->publishAllocation($this->manager);
        $this->assertTrue($published['score']['current']);
        $this->assertSame(0, $published['score']['confirmed_completion']);
        $this->actingAs($this->employee)->getJson('/api/allocations/people/'.$this->manager->id.'/2026')->assertForbidden();
        $this->actingAs($this->user('SYS001'))->getJson('/api/allocations/workspace')->assertForbidden();
    }

    public function test_personal_entries_cannot_be_omitted_forged_or_overallocated(): void
    {
        $this->assignActivity('ACT-A', $this->manager);
        $this->assignActivity('ACT-B', $this->manager);
        $detail = $this->allocation($this->manager);
        $base = ['owner_id' => $this->manager->id, 'year' => 2026, 'version' => 0, 'entries' => array_map(fn ($entry) => ['key' => $entry['key'], 'token' => $entry['token'], 'weight' => 0.7], $detail['entries'])];
        $this->postJson('/api/allocations/draft', $base)->assertUnprocessable();
        array_pop($base['entries']);
        $base['entries'][0]['weight'] = 1;
        $this->postJson('/api/allocations/draft', $base)->assertUnprocessable();
        $base['entries'][0]['token'] = str_repeat('a', 64);
        $this->postJson('/api/allocations/draft', $base)->assertConflict();
        $this->assertDatabaseCount('personal_allocations', 0);
    }

    public function test_personal_weight_revisions_preserve_published_weights_and_previous_score_snapshot(): void
    {
        $assignment = $this->assignActivity('ACT-A', $this->manager);
        $this->assignActivity('ACT-B', $this->manager);
        $this->actingAs($this->manager)->putJson('/api/delivery/assignments/'.$assignment.'/tasks', ['version' => 1, 'tasks' => [['title' => 'Own delivery', 'assignee_id' => $this->manager->id, 'weight' => 1]]])->assertOk();
        $task = DB::table('delivery_tasks')->first();
        $this->postJson('/api/delivery/tasks/'.$task->id.'/submit', ['version' => 1, 'evidence' => 'Completed own work.'])->assertOk();
        $submission = DB::table('task_submissions')->value('id');
        $this->actingAs($this->hof)->postJson('/api/delivery/submissions/'.$submission.'/review', ['version' => 2, 'decision' => 'approved'])->assertOk();
        $this->saveAllocation($this->manager, $this->allocation($this->manager), [0.6, 0.4]);
        $published = $this->publishAllocation($this->manager);
        $this->assertSame(60, $published['score']['confirmed_completion']);
        $this->saveAllocation($this->manager, $published, [0.3, 0.7]);
        $pending = $this->allocation($this->manager);
        $this->assertSame(60, $pending['score']['confirmed_completion']);
        $revised = $this->publishAllocation($this->manager);
        $this->assertSame(30, $revised['score']['confirmed_completion']);
        $snapshot = json_decode($revised['history'][0]['snapshot'], true);
        $this->assertSame(60, $snapshot['previous_score_at_revision']['confirmed_completion']);
        $this->assertCount(4, $revised['history']);
        $this->assertDatabaseCount('task_submissions', 1);
    }

    public function test_new_work_invalidates_current_personal_balance_until_reviewed(): void
    {
        $this->assignActivity('ACT-A', $this->manager);
        $this->saveAllocation($this->manager, $this->allocation($this->manager), [1]);
        $this->publishAllocation($this->manager);
        $this->assignActivity('ACT-B', $this->manager);
        $changed = $this->allocation($this->manager);
        $this->assertCount(2, $changed['entries']);
        $this->assertFalse($changed['score']['current']);
        $this->assertNull($changed['score']['confirmed_completion']);
        $this->actingAs($this->hof)->postJson('/api/hof/kpi-assignments', $this->kpiPayload($this->manager))->assertOk();
        $linked = $this->allocation($this->manager);
        $this->assertCount(1, $linked['entries']);
        $this->assertFalse($linked['score']['current']);
    }

    public function test_hof_personal_sources_keep_kpa_weights_and_do_not_duplicate_own_activities(): void
    {
        $this->assignActivity('ACT-A', $this->hof);
        $detail = $this->allocation($this->hof);
        $this->assertCount(1, $detail['entries']);
        $this->assertSame('HOF responsibility', $detail['entries'][0]['kind']);
        $this->assertSame(1, $detail['entries'][0]['weight']);
        $this->saveAllocation($this->hof, $detail, [1]);
        $this->assertTrue($this->publishAllocation($this->hof)['score']['current']);
    }

    public function test_personal_allocation_combines_work_across_departments_into_one_100_percent_plan(): void
    {
        $digital = (int) DB::table('departments')->where('code', 'DIG')->value('id');
        DB::table('department_memberships')->insert(['user_id' => $this->manager->id, 'department_id' => $digital, 'responsibility' => 'manager', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('reporting_relationships')->insert(['user_id' => $this->manager->id, 'manager_id' => $this->hof->id, 'department_id' => $digital, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->editor);
        $draft = $this->getJson('/api/planning/plans/'.$this->plan)->json('plan');
        $draft['kpas'][0]['weight'] = 0.5;
        $draft['kpas'][] = ['code' => 'KPA-DIG', 'title' => 'Digital services', 'department_id' => $digital, 'weight' => 0.5, 'kpis' => [[
            'code' => 'KPI-DIG', 'title' => 'Digitized processes', 'weight' => 1, 'target_value' => 4, 'target_unit' => 'count', 'frequency' => 'Quarterly', 'direction' => 'higher',
            'activities' => [['code' => 'ACT-DIG', 'title' => 'Deploy digital processes', 'weight' => 1]],
        ]]];
        $this->putJson('/api/planning/plans/'.$this->plan, $draft)->assertOk();
        $this->postJson('/api/planning/plans/'.$this->plan.'/publish', ['revision' => 3])->assertOk();
        $this->actingAs($this->hof);
        foreach (['ACT-A', 'ACT-DIG'] as $code) {
            $this->postJson('/api/hof/assignments', ['plan_id' => $this->plan, 'activity_code' => $code, 'source_revision' => 4, 'version' => 0, 'assignee_id' => $this->manager->id])->assertOk();
        }
        $detail = $this->allocation($this->manager);
        $this->assertCount(2, $detail['entries']);
        $this->assertCount(2, array_unique(array_column($detail['entries'], 'department_id')));
        $this->saveAllocation($this->manager, $detail, [0.25, 0.75]);
        $published = $this->publishAllocation($this->manager);
        $this->assertTrue($published['score']['current']);
        $this->assertSame(1.0, array_sum(array_column($published['published_entries'], 'weight')));
        $this->assertDatabaseCount('personal_allocations', 1);
    }

    public function test_changed_kpi_requires_review_before_inheritance_or_personal_publication(): void
    {
        $this->postJson('/api/hof/kpi-assignments', $this->kpiPayload($this->manager))->assertOk();
        $this->saveAllocation($this->manager, $this->allocation($this->manager), [1]);
        $this->publishAllocation($this->manager);
        $this->actingAs($this->editor);
        $draft = $this->getJson('/api/planning/plans/'.$this->plan)->json('plan');
        $draft['kpas'][0]['kpis'][0]['target_value'] = 95;
        $this->putJson('/api/planning/plans/'.$this->plan, $draft)->assertOk();
        $this->postJson('/api/planning/plans/'.$this->plan.'/publish', ['revision' => 3])->assertOk();
        $changed = $this->allocation($this->manager);
        $this->assertFalse($changed['entries'][0]['valid']);
        $this->assertFalse($changed['score']['current']);
        $assignment = DB::table('work_assignments')->where('activity_code', 'ACT-A')->first();
        $this->actingAs($this->hof)->postJson('/api/hof/assignments', ['plan_id' => $this->plan, 'activity_code' => 'ACT-A', 'source_revision' => 4, 'version' => $assignment->version, 'assignee_id' => $this->manager->id, 'use_kpi_owner' => true])->assertConflict();
        $this->postJson('/api/hof/kpi-assignments', [...$this->kpiPayload($this->manager, 1), 'source_revision' => 4])->assertOk();
        $this->assertDatabaseHas('kpi_delegations', ['generation' => 2]);
        $this->assertTrue($this->allocation($this->manager)['entries'][0]['valid']);
    }

    public function test_long_activity_descriptions_fit_generated_direct_tasks_without_losing_parent_text(): void
    {
        $this->actingAs($this->editor);
        $draft = $this->getJson('/api/planning/plans/'.$this->plan)->json('plan');
        $title = str_repeat('Network requirements ', 35);
        $draft['kpas'][0]['kpis'][0]['activities'][0]['title'] = $title;
        $this->putJson('/api/planning/plans/'.$this->plan, $draft)->assertOk();
        $this->postJson('/api/planning/plans/'.$this->plan.'/publish', ['revision' => 3])->assertOk();
        $this->actingAs($this->hof)->postJson('/api/hof/assignments', ['plan_id' => $this->plan, 'activity_code' => 'ACT-A', 'source_revision' => 4, 'version' => 0, 'assignee_id' => $this->employee->id])->assertOk();
        $this->assertSame(500, mb_strlen(DB::table('delivery_tasks')->value('title')));
        $this->actingAs($this->employee)->getJson('/api/delivery/workspace')->assertJsonPath('tasks.0.parent.activity', trim($title));
    }
}
