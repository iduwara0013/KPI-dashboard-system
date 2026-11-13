<?php

namespace Tests\Feature;

use App\Http\Controllers\TimelinessController;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TimelinessTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $employee;

    private User $other;

    private User $hof;

    private int $assignment;

    private array $tasks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $editor = User::where('employee_number', 'EMP011')->firstOrFail();
        $this->grantPlanningDepartments($editor);
        $this->hof = User::where('employee_number', 'EMP001')->firstOrFail();
        $this->manager = User::where('employee_number', 'EMP004')->firstOrFail();
        $this->employee = User::where('employee_number', 'EMP007')->firstOrFail();
        $this->other = User::where('employee_number', 'EMP008')->firstOrFail();
        $department = DB::table('departments')->where('code', 'IT')->value('id');
        $plan = $this->actingAs($editor)->postJson('/api/planning/plans', ['code' => 'TIMING-2026', 'title' => 'Timing plan', 'owner_id' => $this->hof->id, 'year' => 2026,
            'kpas' => [['code' => 'KPA', 'title' => 'IT work', 'department_id' => $department, 'weight' => 1,
                'kpis' => [['code' => 'KPI', 'title' => 'Project', 'weight' => 1, 'target_value' => 1, 'target_unit' => 'count', 'frequency' => 'On completion', 'direction' => 'higher',
                    'activities' => [['code' => 'ACT', 'title' => 'Delivery', 'weight' => 1, 'due_date' => '2026-11-20']]]]]]])->assertCreated()->json('id');
        $this->postJson('/api/planning/plans/'.$plan.'/publish', ['revision' => 1])->assertOk();
        $this->actingAs($this->hof)->postJson('/api/hof/assignments', ['plan_id' => $plan, 'activity_code' => 'ACT', 'source_revision' => 2, 'version' => 0, 'assignee_id' => $this->manager->id, 'due_date' => '2026-11-20'])->assertOk();
        $this->assignment = (int) DB::table('work_assignments')->value('id');
        $this->actingAs($this->manager)->putJson('/api/delivery/assignments/'.$this->assignment.'/tasks', ['version' => 1, 'tasks' => [
            ['title' => 'Main work', 'assignee_id' => $this->employee->id, 'weight' => .7, 'due_date' => '2026-11-20'],
            ['title' => 'Documentation', 'assignee_id' => $this->other->id, 'weight' => .3, 'due_date' => '2026-11-20'],
        ]])->assertOk();
        $this->tasks = DB::table('delivery_tasks')->orderBy('id')->pluck('id')->all();
    }

    private function at(string $date): void
    {
        $this->travelTo(new DateTimeImmutable($date));
    }

    private function submit(User $owner, int $task): int
    {
        $this->actingAs($owner)->postJson('/api/delivery/tasks/'.$task.'/submit', ['version' => DB::table('delivery_tasks')->where('id', $task)->value('version'), 'evidence' => 'Completed work with verified evidence.'])->assertOk();

        return (int) DB::table('task_submissions')->where('task_id', $task)->max('id');
    }

    private function review(int $task, int $submission, string $decision = 'approved', string $type = 'incomplete', ?User $reviewer = null): void
    {
        $this->actingAs($reviewer ?? $this->manager)->postJson('/api/delivery/submissions/'.$submission.'/review', ['version' => DB::table('delivery_tasks')->where('id', $task)->value('version'), 'decision' => $decision, 'review_note' => 'Checked delivery evidence.', 'correction_type' => $type])->assertOk();
    }

    public function test_calendar_day_thresholds_use_colombo_and_reviewer_delay_does_not_reduce_score(): void
    {
        $this->at('2026-11-20T18:29:59Z');
        $id = $this->submit($this->employee, $this->tasks[0]);
        $this->at('2026-12-01T12:00:00Z');
        $this->review($this->tasks[0], $id);
        $this->actingAs($this->employee)->getJson('/api/delivery/workspace')->assertOk()->assertJsonPath('tasks.0.timeliness.score', 100)
            ->assertJsonPath('tasks.0.timeliness.late_days', 0);
        $calculator = app(TimelinessController::class);
        foreach ([0 => 100, 1 => 90, 3 => 90, 4 => 75, 7 => 75, 8 => 50, 30 => 50] as $days => $expected) {
            $this->assertSame($expected, $calculator->percentage($days));
        }
        $this->assertSame(1, $calculator->lateDays('2026-11-20', '2026-11-20 18:30:00'));
    }

    public function test_unequal_weights_roll_up_consistently_to_manager_hof_and_md_without_reducing_completion(): void
    {
        $this->at('2026-11-20T12:00:00Z');
        $first = $this->submit($this->employee, $this->tasks[0]);
        $this->review($this->tasks[0], $first);
        $this->at('2026-11-28T12:00:00Z');
        $second = $this->submit($this->other, $this->tasks[1]);
        $this->review($this->tasks[1], $second);
        $this->actingAs($this->manager)->getJson('/api/delivery/workspace')->assertOk()->assertJsonPath('managed.0.timeliness.score', 85)
            ->assertJsonPath('managed.0.confirmed_progress', 100)->assertJsonPath('personal_scores.2026.timeliness.score', 85);
        $this->actingAs($this->hof)->getJson('/api/hof/workspace')->assertOk()->assertJsonPath('activities.0.timeliness.score', 85);
        $md = User::factory()->create(['system_role' => 'md', 'is_active' => true]);
        $this->actingAs($md)->getJson('/api/md/workspace?year=2026')->assertOk()->assertJsonPath('activities.0.timeliness.score', 85)->assertJsonPath('activities.0.approved', 100);
        $this->getJson('/api/display/workspace?year=2026')->assertOk()->assertJsonPath('activities.0.timeliness.score', 85);
    }

    private function correctedWork(string $type, int $expected): void
    {
        $this->at('2026-11-20T12:00:00Z');
        $id = $this->submit($this->employee, $this->tasks[0]);
        $this->at('2026-11-25T12:00:00Z');
        $this->review($this->tasks[0], $id, 'needs_changes', $type);
        $this->at('2026-11-28T12:00:00Z');
        $id = $this->submit($this->employee, $this->tasks[0]);
        $this->review($this->tasks[0], $id);
        $this->actingAs($this->employee)->getJson('/api/delivery/workspace')->assertOk()->assertJsonPath('tasks.0.timeliness.score', $expected);
    }

    public function test_incomplete_work_uses_the_resubmission_date(): void
    {
        $this->correctedWork('incomplete', 50);
    }

    public function test_clarification_preserves_original_submission_date(): void
    {
        $this->correctedWork('clarification', 100);
    }

    public function test_pending_score_freezes_at_submission_and_approved_deadline_extension_is_authorized_and_audited(): void
    {
        $task = $this->tasks[0];
        $this->at('2026-11-24T12:00:00Z');
        $this->actingAs($this->employee)->getJson('/api/delivery/workspace')->assertOk()->assertJsonPath('tasks.0.timeliness.estimate', 75)->assertJsonPath('tasks.0.timeliness.score', null);
        $id = $this->submit($this->employee, $task);
        $this->at('2026-12-01T12:00:00Z');
        $this->getJson('/api/delivery/workspace')->assertOk()->assertJsonPath('tasks.0.timeliness.estimate', 75);
        $payload = ['version' => 2, 'due_date' => '2026-11-25', 'reason' => 'Approved supplier access delay.'];
        $this->putJson('/api/delivery/tasks/'.$task.'/deadline', $payload)->assertForbidden();
        $this->actingAs($this->manager)->putJson('/api/delivery/tasks/'.$task.'/deadline', [...$payload, 'version' => 1])->assertConflict();
        $this->putJson('/api/delivery/tasks/'.$task.'/deadline', $payload)->assertOk();
        $this->assertDatabaseHas('admin_activity', ['action' => 'task.deadline_extended', 'actor_id' => $this->manager->id]);
        $this->review($task, $id);
        $this->actingAs($this->employee)->getJson('/api/delivery/workspace')->assertOk()->assertJsonPath('tasks.0.timeliness.score', 100);
        $this->actingAs($this->manager)->putJson('/api/delivery/tasks/'.$task.'/deadline', [...$payload, 'version' => 4, 'due_date' => '2026-12-02'])->assertConflict();
    }

    public function test_no_deadline_is_excluded_and_legacy_evidence_is_labelled(): void
    {
        DB::table('delivery_tasks')->where('id', $this->tasks[0])->update(['due_date' => null]);
        DB::table('work_assignments')->where('id', $this->assignment)->update(['due_date' => null]);
        $this->actingAs($this->employee)->getJson('/api/delivery/workspace')->assertOk()->assertJsonPath('tasks.0.timeliness.estimate', null)->assertJsonPath('tasks.0.timeliness.scheduled_weight', 0);
        DB::table('delivery_tasks')->where('id', $this->tasks[0])->update(['due_date' => '2026-11-20', 'status' => 'approved', 'confirmed_progress' => 100]);
        DB::table('task_submissions')->insert(['task_id' => $this->tasks[0], 'submitted_by' => $this->employee->id, 'evidence' => 'Historical approved work', 'decision' => 'approved', 'submitted_at' => '2026-11-24 12:00:00']);
        $this->getJson('/api/delivery/workspace')->assertOk()->assertJsonPath('tasks.0.timeliness.score', 75)->assertJsonPath('tasks.0.timeliness.legacy', true);
    }

    public function test_nested_subordinates_roll_up_with_their_actual_weights_and_inherited_deadline(): void
    {
        $department = DB::table('departments')->where('code', 'IT')->value('id');
        $junior = User::factory()->create(['system_role' => 'member', 'is_active' => true]);
        DB::table('department_memberships')->insert(['user_id' => $junior->id, 'department_id' => $department, 'responsibility' => 'employee']);
        DB::table('reporting_relationships')->insert(['user_id' => $junior->id, 'manager_id' => $this->employee->id, 'department_id' => $department]);
        $this->actingAs($this->employee)->putJson('/api/delivery/tasks/'.$this->tasks[0].'/tasks', ['version' => 1, 'tasks' => [
            ['title' => 'Own portion', 'assignee_id' => $this->employee->id, 'weight' => .5],
            ['title' => 'Subordinate portion', 'assignee_id' => $junior->id, 'weight' => .5],
        ]])->assertOk();
        $children = DB::table('delivery_tasks')->where('parent_task_id', $this->tasks[0])->orderBy('id')->pluck('id')->all();
        $this->at('2026-11-20T12:00:00Z');
        $id = $this->submit($this->employee, $children[0]);
        $this->review($children[0], $id);
        $id = $this->submit($this->other, $this->tasks[1]);
        $this->review($this->tasks[1], $id);
        $this->at('2026-11-28T12:00:00Z');
        $id = $this->submit($junior, $children[1]);
        $this->review($children[1], $id, reviewer: $this->employee);
        $this->actingAs($this->manager)->getJson('/api/delivery/workspace')->assertOk()->assertJsonPath('managed.0.timeliness.score', 82.5)
            ->assertJsonPath('managed.0.tasks.0.timeliness.score', 75)->assertJsonPath('managed.0.confirmed_progress', 100);
        $this->actingAs($junior)->getJson('/api/delivery/workspace')->assertOk()->assertJsonPath('tasks.0.timeliness.score', 50)->assertJsonPath('tasks.0.timeliness.due_date', '2026-11-20');
    }

    public function test_hof_self_confirmation_has_the_same_timing_rules_and_clients_cannot_forge_submission_time(): void
    {
        $assignment = DB::table('work_assignments')->find($this->assignment);
        $this->actingAs($this->hof)->postJson('/api/hof/assignments', ['plan_id' => $assignment->plan_id, 'activity_code' => 'ACT',
            'source_revision' => 2, 'version' => $assignment->version, 'assignee_id' => $this->hof->id, 'due_date' => '2026-11-20'])->assertOk();
        $generation = DB::table('work_assignments')->where('id', $this->assignment)->value('delivery_generation');
        $task = DB::table('delivery_tasks')->where('assignment_id', $this->assignment)->where('generation', $generation)->first();
        $this->at('2026-11-28T12:00:00Z');
        $this->postJson('/api/delivery/tasks/'.$task->id.'/submit', ['version' => $task->version, 'evidence' => 'HOF confirmed completed work.',
            'timeliness_submitted_at' => '2026-11-19 12:00:00', 'timeliness_due_date' => '2026-12-01'])->assertOk();
        $this->getJson('/api/delivery/workspace')->assertOk()->assertJsonPath('tasks.0.confirmed_progress', 100)->assertJsonPath('tasks.0.timeliness.score', 50);
    }
}
