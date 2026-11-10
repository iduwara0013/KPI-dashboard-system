<?php

namespace Tests\Feature;

use App\Http\Controllers\NotificationController;
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

    public function test_tv_display_uses_published_work_and_omits_personal_and_evidence_details(): void
    {
        $this->assignActivity('ACT-A', $this->employee);
        $this->actingAs($this->hof)->getJson('/api/display/workspace?year=2026')->assertOk()->assertJsonCount(2, 'activities')->assertJsonPath('activities.0.assigned', true)->assertJsonPath('activities.0.approved', 0)->assertJsonMissingPath('activities.0.owner_name')->assertJsonMissingPath('activities.0.assignee')->assertJsonMissingPath('activities.0.tasks');
        $this->getJson('/api/display/workspace?year=2025')->assertJsonCount(0, 'activities');
        $other = $this->user('EMP002');
        $this->actingAs($other)->getJson('/api/display/workspace?year=2026')->assertOk()->assertJsonCount(0, 'activities');
        $md = User::factory()->create(['system_role' => 'md', 'is_active' => true]);
        $this->actingAs($md)->getJson('/api/display/workspace?year=2026')->assertOk()->assertJsonCount(2, 'activities');
    }

    public function test_tv_employee_counts_follow_current_approved_tasks_and_department_scope(): void
    {
        $assignment = $this->assignActivity('ACT-A', $this->employee);
        $task = DB::table('delivery_tasks')->where('assignment_id', $assignment)->first();
        $this->actingAs($this->employee)->postJson('/api/delivery/tasks/'.$task->id.'/submit', ['version' => 1, 'evidence' => 'Completed network delivery.'])->assertOk();
        $submission = DB::table('task_submissions')->where('task_id', $task->id)->value('id');
        $department = DB::table('departments')->where('code', 'IT')->value('id');
        $response = $this->actingAs($this->hof)->getJson('/api/display/workspace?year=2026&department='.$department)->assertOk();
        $member = collect($response->json('team'))->firstWhere('id', $this->employee->id);
        $this->assertSame(1, $member['assigned_tasks']);
        $this->assertSame(1, $member['pending_tasks']);
        $this->assertSame(0, $member['approved_tasks']);
        $this->assertArrayNotHasKey('email', $member);
        $this->assertTrue(collect($response->json('team'))->contains('id', $this->manager->id));
        $this->assertFalse(collect($response->json('team'))->contains('id', $this->user('EMP009')->id));
        $this->postJson('/api/delivery/submissions/'.$submission.'/review', ['version' => 2, 'decision' => 'approved'])->assertOk();
        $response = $this->getJson('/api/display/workspace?year=2026&department='.$department)->assertOk();
        $member = collect($response->json('team'))->firstWhere('id', $this->employee->id);
        $this->assertSame(1, $member['approved_tasks']);
        $this->assertSame(0, $member['pending_tasks']);
        $response = $this->getJson('/api/display/workspace?year=2025&department='.$department)->assertOk();
        $this->assertSame(0, collect($response->json('team'))->firstWhere('id', $this->employee->id)['assigned_tasks']);
    }

    public function test_full_kpi_sends_one_notification_and_md_overdue_link_retains_plan_year(): void
    {
        $this->postJson('/api/hof/kpi-assignments', [...$this->kpiPayload($this->manager), 'due_date' => '2026-12-01'])->assertOk();
        $this->assertEquals(1, DB::table('work_notifications')->where('user_id', $this->manager->id)->count());
        $this->actingAs($this->manager)->getJson('/api/notifications')->assertJsonPath('items.0.target.page', 'kpis');
        $this->actingAs($this->hof)->postJson('/api/hof/kpi-assignments', [...$this->kpiPayload($this->manager, 1), 'due_date' => '2026-12-01'])->assertOk();
        $this->assertEquals(1, DB::table('work_notifications')->where('user_id', $this->manager->id)->count());
        $this->travelTo(now()->setDate(2027, 1, 5)->startOfDay());
        $md = User::factory()->create(['system_role' => 'md', 'is_active' => true]);
        NotificationController::remind($md);
        $this->actingAs($md)->getJson('/api/notifications')->assertOk()->assertJsonPath('items.0.type', 'overdue')->assertJsonPath('items.0.target.year', 2026)->assertJsonPath('items.0.target.workspace', 'md');
        $this->postJson('/api/notifications/read-all')->assertOk();
        $this->getJson('/api/notifications')->assertJsonPath('unread', 0);
    }

    public function test_complete_kpi_manager_employee_review_workflow_preserves_hof_and_personal_weights(): void
    {
        $this->actingAs($this->editor);
        $draft = $this->getJson('/api/planning/plans/'.$this->plan)->json('plan');
        $draft['kpas'][0]['weight'] = 0.6;
        foreach ($draft['kpas'][0]['kpis'][0]['activities'] as &$activity) {
            $activity['weight'] = 0.5;
            $activity['due_date'] = '2026-12-15';
        }
        unset($activity);
        $second = $draft['kpas'][0];
        $second['code'] = 'KPA-OTHER';
        $second['weight'] = 0.4;
        $second['kpis'][0]['code'] = 'KPI-OTHER';
        $second['kpis'][0]['activities'] = [['code' => 'ACT-OTHER', 'title' => 'Other responsibility', 'weight' => 1]];
        $draft['kpas'][] = $second;
        $this->putJson('/api/planning/plans/'.$this->plan, $draft)->assertOk();
        $this->postJson('/api/planning/plans/'.$this->plan.'/publish', ['revision' => 3])->assertOk();

        $this->actingAs($this->hof)->postJson('/api/hof/kpi-assignments', [
            ...$this->kpiPayload($this->manager), 'source_revision' => 4, 'due_date' => '2026-12-15',
        ])->assertOk();
        $assignments = DB::table('work_assignments')->orderBy('activity_code')->get();
        $this->assertCount(2, $assignments);
        foreach ($assignments as $assignment) {
            $this->actingAs($this->manager)->putJson('/api/delivery/assignments/'.$assignment->id.'/tasks', [
                'version' => $assignment->version,
                'tasks' => [['title' => 'Deliver '.$assignment->activity_code, 'assignee_id' => $assignment->activity_code === 'ACT-A' ? $this->employee->id : $this->manager->id, 'weight' => 1, 'due_date' => '2026-12-15']],
            ])->assertOk();
        }
        $this->saveAllocation($this->manager, $this->allocation($this->manager), [1]);
        $this->publishAllocation($this->manager);
        $this->saveAllocation($this->hof, $this->allocation($this->hof), [0.6, 0.4]);
        $this->publishAllocation($this->hof);

        $employeeTask = DB::table('delivery_tasks')->where('assignee_id', $this->employee->id)->first();
        $this->actingAs($this->employee)->putJson('/api/delivery/tasks/'.$employeeTask->id.'/progress', ['version' => 1, 'reported_progress' => 70, 'progress_note' => 'Delivery underway.'])->assertOk();
        $this->assertEquals(0, $this->allocation($this->hof)['score']['confirmed_completion']);
        $this->actingAs($this->employee)->postJson('/api/delivery/tasks/'.$employeeTask->id.'/submit', ['version' => 2, 'evidence' => 'Completed and verified network upgrade.'])->assertOk();
        $submission = DB::table('task_submissions')->where('task_id', $employeeTask->id)->value('id');
        $this->assertEquals(0, $this->allocation($this->manager)['score']['confirmed_completion']);
        $this->actingAs($this->manager)->postJson('/api/delivery/submissions/'.$submission.'/review', ['version' => 3, 'decision' => 'needs_changes', 'review_note' => 'Include the validation results.'])->assertOk();
        $this->actingAs($this->employee)->postJson('/api/delivery/tasks/'.$employeeTask->id.'/submit', ['version' => 4, 'evidence' => 'Completed upgrade with validation results.'])->assertOk();
        $submission = DB::table('task_submissions')->where('task_id', $employeeTask->id)->orderByDesc('id')->value('id');
        $this->actingAs($this->manager)->postJson('/api/delivery/submissions/'.$submission.'/review', ['version' => 5, 'decision' => 'approved'])->assertOk();
        $this->assertEquals(50, $this->allocation($this->manager)['score']['confirmed_completion']);
        $this->assertEquals(30, $this->allocation($this->hof)['score']['confirmed_completion']);
        $this->actingAs($this->hof)->getJson('/api/hof/workspace')->assertJsonPath('kpis.0.confirmed_progress', 50);

        $ownTask = DB::table('delivery_tasks')->where('assignee_id', $this->manager->id)->first();
        $this->actingAs($this->manager)->postJson('/api/delivery/tasks/'.$ownTask->id.'/submit', ['version' => 1, 'evidence' => 'Server delivery completed and validated.'])->assertOk();
        $submission = DB::table('task_submissions')->where('task_id', $ownTask->id)->value('id');
        $this->postJson('/api/delivery/submissions/'.$submission.'/review', ['version' => 2, 'decision' => 'approved'])->assertForbidden();
        $this->actingAs($this->hof)->postJson('/api/delivery/submissions/'.$submission.'/review', ['version' => 2, 'decision' => 'approved'])->assertOk();
        $this->assertEquals(100, $this->allocation($this->manager)['score']['confirmed_completion']);
        $this->assertEquals(60, $this->allocation($this->hof)['score']['confirmed_completion']);
        $this->assertDatabaseCount('task_submissions', 3);
        $this->assertDatabaseCount('work_assignments', 2);
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

    public function test_hof_confirms_own_work_without_an_independent_reviewer(): void
    {
        $id = $this->assignActivity('ACT-A', $this->hof);
        $task = DB::table('delivery_tasks')->where('assignment_id', $id)->first();
        $reviewer = $this->user('EMP002');
        $this->actingAs($this->editor)->postJson('/api/planning/hof-reviewers/'.$id, [
            'version' => 1, 'reviewer_id' => $reviewer->id,
        ])->assertNotFound();
        $this->actingAs($this->hof)->getJson('/api/delivery/workspace')
            ->assertJsonPath('tasks.0.self_confirm', true)->assertJsonPath('tasks.0.reviewer_id', null);
        $this->postJson('/api/delivery/tasks/'.$task->id.'/submit', [
            'version' => 1, 'evidence' => 'HOF coordination completed with supporting records.',
        ])->assertOk();
        $this->assertDatabaseHas('delivery_tasks', ['id' => $task->id, 'status' => 'approved', 'confirmed_progress' => 100]);
        $this->assertDatabaseHas('task_submissions', ['task_id' => $task->id, 'decision' => 'approved', 'reviewed_by' => null]);
        $this->getJson('/api/hof/workspace')->assertJsonPath('activities.0.assignment.confirmed_progress', 100);
        $this->actingAs($reviewer)->getJson('/api/delivery/workspace')->assertJsonCount(0, 'reviews');
        $reviewer->update(['is_active' => false]);
        $this->actingAs($this->hof)->getJson('/api/hof/workspace')->assertJsonPath('activities.0.assignment.confirmed_progress', 100);
        $this->postJson('/api/delivery/tasks/'.$task->id.'/submit', ['version' => 2, 'evidence' => 'Duplicate confirmation.'])->assertConflict();
    }

    public function test_full_kpi_can_go_directly_to_employee_without_duplicate_personal_entries(): void
    {
        $this->postJson('/api/hof/kpi-assignments', $this->kpiPayload($this->employee))->assertOk();
        $this->assertDatabaseCount('delivery_tasks', 2);
        $this->actingAs($this->employee)->getJson('/api/delivery/workspace')->assertJsonCount(2, 'tasks')->assertJsonCount(1, 'delegated_kpis')->assertJsonCount(0, 'managed');
        $this->assertCount(1, $this->allocation($this->employee)['entries']);
        $assignment = DB::table('work_assignments')->first();
        $this->putJson('/api/delivery/assignments/'.$assignment->id.'/tasks', ['version' => $assignment->version, 'tasks' => [['title' => 'My delivery work', 'assignee_id' => $this->employee->id, 'weight' => 1]]])->assertOk();
    }

    public function test_hof_can_confirm_a_pending_submission_created_under_the_old_reviewer_flow(): void
    {
        $id = $this->assignActivity('ACT-A', $this->hof);
        $task = DB::table('delivery_tasks')->where('assignment_id', $id)->first();
        DB::table('delivery_tasks')->where('id', $task->id)->update([
            'reviewer_id' => $this->user('EMP002')->id, 'status' => 'submitted', 'reported_progress' => 100, 'version' => 2,
        ]);
        $old = DB::table('task_submissions')->insertGetId([
            'task_id' => $task->id, 'submitted_by' => $this->hof->id, 'evidence' => 'Old pending evidence.', 'submitted_at' => now(),
        ]);
        $this->postJson('/api/delivery/tasks/'.$task->id.'/submit', ['version' => 2, 'evidence' => 'HOF confirms the completed coordination.'])->assertOk();
        $this->assertDatabaseHas('task_submissions', ['id' => $old, 'decision' => 'superseded']);
        $this->assertDatabaseHas('work_assignments', ['id' => $id, 'confirmed_progress' => 100]);
    }

    public function test_md_dashboard_tracks_approved_published_work_and_pending_reviews_without_duplicates(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 20));
        $id = $this->assignActivity('ACT-A', $this->employee, 0, ['due_date' => '2026-11-15']);
        $task = DB::table('delivery_tasks')->where('assignment_id', $id)->first();
        $this->actingAs($this->employee)->postJson('/api/delivery/tasks/'.$task->id.'/submit', ['version' => 1, 'evidence' => 'Network delivery complete.'])->assertOk();
        $md = User::factory()->create(['system_role' => 'md', 'is_active' => true]);
        $this->actingAs($md)->getJson('/api/md/workspace?year=2026')->assertOk()->assertJsonCount(2, 'activities')->assertJsonPath('activities.0.pending', 1)->assertJsonPath('activities.0.approved', 0)->assertJsonPath('activities.0.overdue', true)->assertJsonPath('activities.0.tasks.0.reviewer', $this->hof->name)->assertJsonMissingPath('activities.0.tasks.0.evidence');
        $this->getJson('/api/md/workspace?year=2025')->assertJsonCount(0, 'activities');
        $submission = DB::table('task_submissions')->value('id');
        $this->actingAs($this->hof)->postJson('/api/delivery/submissions/'.$submission.'/review', ['version' => 2, 'decision' => 'approved'])->assertOk();
        $this->actingAs($md)->getJson('/api/md/workspace?year=2026')->assertJsonPath('activities.0.approved', 100)->assertJsonPath('activities.0.pending', 0)->assertJsonPath('activities.0.overdue', false);
        $this->actingAs($this->editor);
        $draft = $this->getJson('/api/planning/plans/'.$this->plan)->json('plan');
        $draft['kpas'][0]['kpis'][0]['activities'][0]['title'] = 'Unpublished change';
        $this->putJson('/api/planning/plans/'.$this->plan, $draft)->assertOk();
        $this->actingAs($md)->getJson('/api/md/workspace?year=2026')->assertJsonPath('activities.0.activity', 'Network upgrade')->assertJsonPath('activities.0.approved', 100);
        $this->actingAs($this->editor)->postJson('/api/planning/plans/'.$this->plan.'/publish', ['revision' => 3])->assertOk();
        $this->actingAs($md)->getJson('/api/md/workspace?year=2026')->assertJsonPath('activities.0.approved', 0)->assertJsonPath('activities.0.needs_review', true);
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
