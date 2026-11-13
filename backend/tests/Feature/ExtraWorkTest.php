<?php

namespace Tests\Feature;

use App\Http\Controllers\ExtraWorkController;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExtraWorkTest extends TestCase
{
    use RefreshDatabase;

    private User $hof;

    private User $manager;

    private User $employee;

    private User $other;

    private User $finance;

    private int $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->hof = User::where('employee_number', 'EMP001')->firstOrFail();
        $this->manager = User::where('employee_number', 'EMP004')->firstOrFail();
        $this->employee = User::where('employee_number', 'EMP007')->firstOrFail();
        $this->other = User::where('employee_number', 'EMP008')->firstOrFail();
        $this->finance = User::where('employee_number', 'EMP006')->firstOrFail();
        $this->department = (int) DB::table('departments')->where('code', 'IT')->value('id');
        $this->travelTo(new DateTimeImmutable('2026-11-20T12:00:00Z'));
    }

    private function assign(User $actor, User $recipient, float $weight = 70, string $title = 'Emergency network repair'): int
    {
        return $this->actingAs($actor)->postJson('/api/extra-work/assignments', ['title' => $title, 'department_id' => $this->department,
            'assignee_id' => $recipient->id, 'year' => 2026, 'weight' => $weight, 'priority' => 'urgent', 'due_date' => '2026-11-20', 'instructions' => 'Verify the repaired service.'])->assertCreated()->json('id');
    }

    private function task(int $assignment): object
    {
        return DB::table('extra_work_tasks')->where('assignment_id', $assignment)->whereNull('parent_task_id')->first();
    }

    private function submit(User $actor, int $task): int
    {
        $this->actingAs($actor)->postJson('/api/extra-work/tasks/'.$task.'/submit', ['version' => DB::table('extra_work_tasks')->where('id', $task)->value('version'), 'evidence' => 'Completed and verified the work.', 'evidence_url' => 'https://example.test/result'])->assertOk();

        return (int) DB::table('extra_work_submissions')->where('task_id', $task)->max('id');
    }

    private function review(User $actor, int $task, int $submission, string $decision = 'approved', string $type = 'incomplete'): void
    {
        $this->actingAs($actor)->postJson('/api/extra-work/submissions/'.$submission.'/review', ['version' => DB::table('extra_work_tasks')->where('id', $task)->value('version'), 'decision' => $decision, 'correction_type' => $type, 'review_note' => 'Reviewed the verification evidence.'])->assertOk();
    }

    public function test_extra_work_requires_no_plan_and_follows_scoped_reporting_and_read_only_permissions(): void
    {
        $this->getJson('/api/extra-work/workspace')->assertUnauthorized();
        $assignment = $this->assign($this->manager, $this->employee);
        $task = $this->task($assignment);
        $this->assertDatabaseCount('performance_plans', 0);
        $this->assertDatabaseCount('work_assignments', 0);
        $this->assertDatabaseCount('delivery_tasks', 0);
        $this->actingAs($this->employee)->getJson('/api/extra-work/workspace')->assertOk()->assertJsonCount(1, 'tasks')->assertJsonPath('tasks.0.id', $task->id);
        $this->actingAs($this->finance)->getJson('/api/extra-work/workspace')->assertOk()->assertJsonCount(0, 'assignments')->assertJsonCount(0, 'tasks');
        $this->postJson('/api/extra-work/assignments', ['title' => 'Forged work', 'department_id' => $this->department, 'assignee_id' => $this->employee->id, 'year' => 2026, 'weight' => 30, 'priority' => 'normal', 'due_date' => '2026-11-30'])->assertForbidden();
        $this->getJson('/api/extra-work/assignments/'.$assignment.'/history')->assertForbidden();
        $this->putJson('/api/extra-work/tasks/'.$task->id.'/progress', ['version' => 1, 'reported_progress' => 50, 'progress_note' => 'Forged progress'])->assertForbidden();
        $md = User::factory()->create(['system_role' => 'md', 'is_active' => true]);
        $this->actingAs($md)->getJson('/api/extra-work/workspace')->assertOk()->assertJsonCount(1, 'assignments')->assertJsonPath('read_only', true);
        $this->postJson('/api/extra-work/assignments', [])->assertForbidden();
        $editor = User::where('employee_number', 'EMP011')->firstOrFail();
        $this->actingAs($editor)->getJson('/api/extra-work/workspace')->assertOk()->assertJsonCount(0, 'assignments');
        $this->grantPlanningDepartments($editor, [$this->department]);
        $this->getJson('/api/extra-work/workspace')->assertOk()->assertJsonCount(1, 'assignments')->assertJsonPath('can_assign', false);
        $this->putJson('/api/extra-work/tasks/'.$task->id.'/progress', ['version' => 1, 'reported_progress' => 50, 'progress_note' => 'Editor cannot do this'])->assertForbidden();
    }

    public function test_unequal_extra_weights_rebalance_and_keep_prior_scores_without_changing_planned_scores(): void
    {
        $editor = User::where('employee_number', 'EMP011')->firstOrFail();
        $this->grantPlanningDepartments($editor);
        $plan = $this->actingAs($editor)->postJson('/api/planning/plans', ['code' => 'UNCHANGED-PLAN', 'title' => 'Original plan', 'owner_id' => $this->hof->id, 'year' => 2026,
            'kpas' => [['code' => 'KPA', 'title' => 'Original KPA', 'department_id' => $this->department, 'weight' => 1, 'kpis' => [['code' => 'KPI', 'title' => 'Original KPI', 'weight' => 1, 'target_value' => 1, 'target_unit' => 'count', 'frequency' => 'On completion', 'direction' => 'higher', 'activities' => [['code' => 'ACT', 'title' => 'Planned activity', 'weight' => 1]]]]]]])->assertCreated()->json('id');
        $this->postJson('/api/planning/plans/'.$plan.'/publish', ['revision' => 1])->assertOk();
        $before = DB::table('performance_plan_items')->get()->toJson();
        $first = $this->assign($this->manager, $this->employee, 70);
        $firstTask = $this->task($first);
        $id = $this->submit($this->employee, $firstTask->id);
        $this->review($this->manager, $firstTask->id, $id);
        $this->actingAs($this->employee)->getJson('/api/extra-work/workspace')->assertOk()->assertJsonPath('personal_sources.0.confirmed_progress', 100);
        $second = $this->assign($this->manager, $this->employee, 30, 'Additional report');
        $history = $this->getJson('/api/extra-work/assignments/'.$second.'/history')->assertOk()->json('versions.0.snapshot');
        $snapshot = json_decode($history, true);
        $this->assertEquals(100, $snapshot['before']['confirmed_completion']);
        $this->assertEquals(70, $snapshot['after']['confirmed_completion']);
        $this->travelTo(new DateTimeImmutable('2026-11-28T12:00:00Z'));
        $secondTask = $this->task($second);
        $id = $this->submit($this->employee, $secondTask->id);
        $this->review($this->manager, $secondTask->id, $id);
        $this->assertSame($before, DB::table('performance_plan_items')->get()->toJson());
        $state = $this->actingAs($this->employee)->getJson('/api/extra-work/workspace')->assertOk()->json();
        $score = app(ExtraWorkController::class)->summarize($state['personal_sources']);
        $this->assertEquals(100, $score['confirmed_completion']);
        $this->assertEquals(85, $score['timeliness']['score']);
        $this->assertDatabaseCount('work_assignments', 0);
        $this->assertDatabaseCount('task_submissions', 0);
    }

    public function test_progress_evidence_corrections_and_exact_extra_notifications_use_the_existing_review_rules(): void
    {
        $assignment = $this->assign($this->manager, $this->employee);
        $task = $this->task($assignment);
        $this->actingAs($this->employee)->putJson('/api/extra-work/tasks/'.$task->id.'/progress', ['version' => 1, 'reported_progress' => 40, 'progress_note' => 'Repair in progress'])->assertOk();
        $id = $this->submit($this->employee, $task->id);
        $this->postJson('/api/extra-work/submissions/'.$id.'/review', ['version' => 3, 'decision' => 'approved'])->assertForbidden();
        $notification = $this->actingAs($this->manager)->getJson('/api/notifications')->assertOk()->json('items');
        $notice = collect($notification)->first(fn ($row) => $row['target']['workspace'] === 'extra');
        $this->assertTrue($notice['available']);
        $this->assertTrue($notice['action_required']);
        $this->assertSame($id, $notice['target']['extra_submission_id']);
        $this->review($this->manager, $task->id, $id, 'needs_changes', 'clarification');
        $this->travelTo(new DateTimeImmutable('2026-11-28T12:00:00Z'));
        $newId = $this->submit($this->employee, $task->id);
        $notices = $this->actingAs($this->manager)->getJson('/api/notifications')->assertOk()->json('items');
        $old = collect($notices)->firstWhere('id', $notice['id']);
        $this->assertFalse($old['action_required']);
        $this->review($this->manager, $task->id, $newId);
        $this->actingAs($this->employee)->getJson('/api/extra-work/workspace')->assertOk()->assertJsonPath('tasks.0.timeliness.score', 100)->assertJsonPath('tasks.0.confirmed_progress', 100)->assertJsonCount(2, 'submissions');
        $this->assertDatabaseHas('admin_activity', ['action' => 'extra_work.reviewed']);
    }

    public function test_subordinate_split_locks_after_progress_and_uses_actual_branch_weights(): void
    {
        $assignment = $this->assign($this->hof, $this->manager, 100);
        $root = $this->task($assignment);
        $payload = ['version' => 1, 'tasks' => [['title' => 'Main work', 'assignee_id' => $this->employee->id, 'weight' => .7], ['title' => 'Documentation', 'assignee_id' => $this->other->id, 'weight' => .3]]];
        $this->actingAs($this->manager)->putJson('/api/extra-work/tasks/'.$root->id.'/tasks', $payload)->assertOk();
        $children = DB::table('extra_work_tasks')->where('parent_task_id', $root->id)->orderBy('id')->get();
        $id = $this->submit($this->employee, $children[0]->id);
        $this->review($this->manager, $children[0]->id, $id);
        $this->assertEquals(70, DB::table('extra_work_assignments')->where('id', $assignment)->value('confirmed_progress'));
        $this->actingAs($this->manager)->putJson('/api/extra-work/tasks/'.$root->id.'/tasks', [...$payload, 'version' => DB::table('extra_work_tasks')->where('id', $root->id)->value('version')])->assertConflict();
        $id = $this->submit($this->other, $children[1]->id);
        $this->review($this->manager, $children[1]->id, $id);
        $this->assertEquals(100, DB::table('extra_work_assignments')->where('id', $assignment)->value('confirmed_progress'));
        $this->actingAs($this->hof)->getJson('/api/extra-work/workspace')->assertOk()->assertJsonPath('assignments.0.timeliness.score', 100);
    }

    public function test_weight_changes_require_assigning_reviewer_approval_reason_and_current_version(): void
    {
        $assignment = $this->assign($this->manager, $this->employee);
        $task = $this->task($assignment);
        $id = $this->submit($this->employee, $task->id);
        $this->review($this->manager, $task->id, $id);
        $payload = ['version' => DB::table('extra_work_assignments')->where('id', $assignment)->value('version'), 'weight' => 30, 'reason' => 'Approved revised importance.'];
        $this->actingAs($this->employee)->putJson('/api/extra-work/assignments/'.$assignment.'/weight', $payload)->assertForbidden();
        $this->actingAs($this->manager)->putJson('/api/extra-work/assignments/'.$assignment.'/weight', [...$payload, 'version' => 1])->assertConflict();
        $this->putJson('/api/extra-work/assignments/'.$assignment.'/weight', [...$payload, 'reason' => ''])->assertUnprocessable();
        $this->putJson('/api/extra-work/assignments/'.$assignment.'/weight', $payload)->assertOk();
        $this->assertDatabaseHas('extra_work_versions', ['assignment_id' => $assignment, 'event' => 'weight_approved', 'reason' => $payload['reason']]);
        $this->assertDatabaseHas('extra_work_tasks', ['id' => $task->id, 'status' => 'approved', 'weight' => 1]);
        $this->assertDatabaseCount('extra_work_submissions', 1);
    }

    public function test_deadline_extension_and_hof_own_confirmation_keep_timeliness_rules(): void
    {
        $assignment = $this->assign($this->manager, $this->employee);
        $task = $this->task($assignment);
        $this->travelTo(new DateTimeImmutable('2026-11-28T12:00:00Z'));
        $id = $this->submit($this->employee, $task->id);
        $payload = ['version' => 2, 'due_date' => '2026-11-30', 'reason' => 'Approved service access delay.'];
        $this->putJson('/api/extra-work/tasks/'.$task->id.'/deadline', $payload)->assertForbidden();
        $this->actingAs($this->manager)->putJson('/api/extra-work/tasks/'.$task->id.'/deadline', $payload)->assertOk();
        $this->review($this->manager, $task->id, $id);
        $this->actingAs($this->employee)->getJson('/api/extra-work/workspace')->assertOk()->assertJsonPath('tasks.0.timeliness.score', 100);
        $own = $this->assign($this->hof, $this->hof, 100, 'HOF own urgent work');
        $task = $this->task($own);
        $this->submit($this->hof, $task->id);
        $this->getJson('/api/extra-work/workspace')->assertOk()->assertJsonPath('tasks.0.self_confirm', true)->assertJsonPath('tasks.0.confirmed_progress', 100)->assertJsonPath('tasks.0.timeliness.score', 50);
    }

    public function test_foreign_children_forged_timestamps_and_rounding_cannot_bypass_assignment_rules(): void
    {
        $assignment = $this->assign($this->hof, $this->manager);
        $root = $this->task($assignment);
        $payload = ['version' => 1, 'tasks' => [['title' => 'Foreign assignment', 'assignee_id' => $this->finance->id, 'weight' => 1]]];
        $this->actingAs($this->manager)->putJson('/api/extra-work/tasks/'.$root->id.'/tasks', $payload)->assertForbidden();
        $payload['tasks'] = array_fill(0, 3, ['title' => 'Rounded allocation', 'assignee_id' => $this->employee->id, 'weight' => 1 / 3]);
        $this->putJson('/api/extra-work/tasks/'.$root->id.'/tasks', $payload)->assertUnprocessable();
        $this->travelTo(new DateTimeImmutable('2026-11-28T12:00:00Z'));
        $this->postJson('/api/extra-work/tasks/'.$root->id.'/submit', ['version' => 1, 'evidence' => 'Verified completed work', 'timeliness_submitted_at' => '2026-11-19', 'timeliness_due_date' => '2026-12-31'])->assertOk();
        $submission = (int) DB::table('extra_work_submissions')->max('id');
        $this->review($this->hof, $root->id, $submission);
        $this->actingAs($this->manager)->getJson('/api/extra-work/workspace')->assertOk()->assertJsonPath('tasks.0.timeliness.score', 50);
    }
}
