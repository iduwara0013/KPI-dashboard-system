<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkChangeTest extends TestCase
{
    use RefreshDatabase;

    private User $hof;

    private User $manager;

    private User $employee;

    private User $other;

    private User $editor;

    private int $department;

    private int $plan;

    private int $assignment;

    private int $task;

    private int $extra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->travelTo(new \DateTimeImmutable('2026-11-20T12:00:00Z'));
        $this->hof = User::where('employee_number', 'EMP001')->firstOrFail();
        $this->manager = User::where('employee_number', 'EMP004')->firstOrFail();
        $this->employee = User::where('employee_number', 'EMP007')->firstOrFail();
        $this->other = User::where('employee_number', 'EMP008')->firstOrFail();
        $this->editor = User::where('employee_number', 'EMP011')->firstOrFail();
        $this->department = (int) DB::table('departments')->where('code', 'IT')->value('id');
        $this->grantPlanningDepartments($this->editor);
        $this->plan = $this->actingAs($this->editor)->postJson('/api/planning/plans', ['code' => 'CHANGE-2026', 'title' => 'Original KPI plan', 'owner_id' => $this->hof->id, 'year' => 2026,
            'kpas' => [['code' => 'KPA', 'title' => 'Infrastructure', 'department_id' => $this->department, 'weight' => 1, 'kpis' => [['code' => 'KPI', 'title' => 'Network outcome', 'weight' => 1,
                'target_value' => 1, 'target_unit' => 'count', 'direction' => 'higher', 'frequency' => 'On completion', 'activities' => [['code' => 'ACT', 'title' => 'Original maintenance', 'weight' => 1]]]]]]])->assertCreated()->json('id');
        $this->postJson('/api/planning/plans/'.$this->plan.'/publish', ['revision' => 1])->assertOk();
        $this->actingAs($this->hof)->postJson('/api/hof/assignments', ['plan_id' => $this->plan, 'activity_code' => 'ACT', 'source_revision' => 2, 'version' => 0, 'assignee_id' => $this->manager->id, 'due_date' => '2026-11-10'])->assertOk();
        $this->assignment = (int) DB::table('work_assignments')->value('id');
        $this->actingAs($this->manager)->putJson('/api/delivery/assignments/'.$this->assignment.'/tasks', ['version' => 1, 'tasks' => [
            ['title' => 'Original employee work', 'assignee_id' => $this->employee->id, 'weight' => .7, 'due_date' => '2026-11-10'],
            ['title' => 'Other employee work', 'assignee_id' => $this->other->id, 'weight' => .3, 'due_date' => '2026-11-10'],
        ]])->assertOk();
        $this->task = (int) DB::table('delivery_tasks')->where('assignee_id', $this->employee->id)->value('id');
        $this->extra = $this->assignExtra($this->employee);
    }

    private function assignExtra(User $owner, int $year = 2026): int
    {
        return $this->actingAs($this->manager)->postJson('/api/extra-work/assignments', ['title' => 'Urgent recovery '.$year, 'department_id' => $this->department, 'assignee_id' => $owner->id,
            'year' => $year, 'weight' => 100, 'priority' => 'urgent', 'due_date' => '2026-11-30'])->assertCreated()->json('id');
    }

    private function requestChange(float $fraction = 1): int
    {
        return $this->actingAs($this->employee)->postJson('/api/work-changes', ['task_id' => $this->task, 'task_version' => DB::table('delivery_tasks')->where('id', $this->task)->value('version'),
            'extra_assignment_id' => $this->extra, 'requested_fraction' => $fraction, 'reason' => 'Urgent service recovery prevents both responsibilities being delivered on time.'])->assertCreated()->json('id');
    }

    private function decision(int $id, string $decision = 'replace', array $extra = []): void
    {
        $row = DB::table('work_change_requests')->find($id);
        $this->actingAs($this->manager)->postJson('/api/work-changes/'.$id.'/decide', [...[
            'version' => $row->version, 'task_version' => DB::table('delivery_tasks')->where('id', $this->task)->value('version'),
            'extra_version' => DB::table('extra_work_assignments')->where('id', $this->extra)->value('version'), 'decision' => $decision, 'decision_note' => 'Supervisor capacity decision recorded.', 'fraction' => $row->requested_fraction,
        ], ...$extra])->assertOk();
    }

    private function completeExtra(): void
    {
        $task = DB::table('extra_work_tasks')->where('assignment_id', $this->extra)->first();
        $this->actingAs($this->employee)->postJson('/api/extra-work/tasks/'.$task->id.'/submit', ['version' => $task->version, 'evidence' => 'Recovery completed and independently verified.'])->assertOk();
        $submission = (int) DB::table('extra_work_submissions')->where('task_id', $task->id)->max('id');
        $this->actingAs($this->manager)->postJson('/api/extra-work/submissions/'.$submission.'/review', ['version' => $task->version + 1, 'decision' => 'approved'])->assertOk();
    }

    public function test_both_continue_by_default_and_pending_or_declined_requests_do_not_change_scores(): void
    {
        $this->completeExtra();
        $this->assertSame(0.0, (float) DB::table('work_assignments')->where('id', $this->assignment)->value('confirmed_progress'));
        $request = $this->requestChange();
        $this->assertDatabaseCount('work_replacements', 0);
        $notifications = $this->actingAs($this->manager)->getJson('/api/notifications')->assertOk()->json('items');
        $pendingNotification = collect($notifications)->first(fn ($item) => ($item['target']['work_change_id'] ?? null) === $request);
        $this->assertTrue($pendingNotification['action_required']);
        $notification = DB::table('work_notifications')->where('user_id', $this->manager->id)->where('event_key', 'work-change:'.$request.':requested')->first();
        $this->assertNotNull($notification);
        $this->decision($request, 'continue_both');
        $decidedNotifications = $this->actingAs($this->manager)->getJson('/api/notifications')->assertOk()->json('items');
        $this->assertFalse(collect($decidedNotifications)->first(fn ($item) => ($item['target']['work_change_id'] ?? null) === $request)['action_required']);
        $this->assertDatabaseCount('work_replacements', 0);
        $this->assertSame(0.0, (float) DB::table('work_assignments')->where('id', $this->assignment)->value('confirmed_progress'));
        $this->actingAs($this->employee)->getJson('/api/extra-work/workspace')->assertJsonCount(1, 'personal_sources')->assertJsonPath('personal_sources.0.confirmed_progress', 100);
        $next = $this->requestChange();
        $this->decision($next, 'rejected');
        $this->assertDatabaseCount('work_replacements', 0);
        $last = $this->requestChange();
        $this->actingAs($this->employee)->postJson('/api/work-changes/'.$last.'/cancel')->assertOk();
        $this->assertDatabaseHas('work_change_requests', ['id' => $last, 'decision' => 'cancelled']);
    }

    public function test_full_replacement_rolls_live_approved_work_to_all_views_without_changing_kpi_targets_or_double_counting(): void
    {
        $originalItems = DB::table('performance_plan_items')->get()->toJson();
        $request = $this->requestChange();
        $this->decision($request);
        $this->completeExtra();
        $this->assertSame(70.0, (float) DB::table('work_assignments')->where('id', $this->assignment)->value('confirmed_progress'));
        $this->assertSame(0.0, (float) DB::table('delivery_tasks')->where('id', $this->task)->value('confirmed_progress'));
        $this->assertSame($originalItems, DB::table('performance_plan_items')->get()->toJson());
        $this->assertDatabaseCount('monthly_kpi_submissions', 0);
        $state = $this->actingAs($this->employee)->getJson('/api/delivery/workspace')->assertOk();
        $state->assertJsonPath('tasks.0.confirmed_progress', 100)->assertJsonPath('tasks.0.timeliness.score', 100)->assertJsonPath('personal_scores.2026.confirmed_completion', 100);
        $this->actingAs($this->employee)->getJson('/api/extra-work/workspace')->assertOk()->assertJsonCount(0, 'personal_sources')->assertJsonPath('assignments.0.used_for_replacement', true);
        $this->postJson('/api/delivery/tasks/'.$this->task.'/submit', ['version' => 2, 'evidence' => 'Trying to count original work again'])->assertConflict();
        $this->putJson('/api/delivery/tasks/'.$this->task.'/progress', ['version' => 2, 'reported_progress' => 100, 'progress_note' => 'Duplicate'])->assertConflict();
        $this->actingAs($this->manager)->getJson('/api/delivery/workspace')->assertJsonPath('managed.0.confirmed_progress', 70);
        $this->actingAs($this->hof)->getJson('/api/hof/workspace')->assertJsonPath('activities.0.assignment.confirmed_progress', 70);
        $md = User::factory()->create(['system_role' => 'md', 'is_active' => true]);
        $mdState = $this->actingAs($md)->getJson('/api/md/workspace?year=2026')->assertOk()->json();
        $this->assertStringContainsString('Original employee work', json_encode($mdState));
        $mdTask = collect($mdState['activities'][0]['tasks'])->firstWhere('id', $this->task);
        $this->assertSame(100, $mdTask['confirmed_progress']);
        $this->assertSame('approved', $mdTask['status']);
        $tv = $this->getJson('/api/display/workspace?year=2026')->assertOk()->json();
        $this->assertStringContainsString('Shanika Dias', json_encode($tv));
        $this->actingAs($this->editor)->getJson('/api/planning/plans/'.$this->plan.'/deletion')->assertConflict();
        $this->assertDatabaseHas('admin_activity', ['action' => 'work_change.decided']);
    }

    public function test_reporting_changes_cannot_leave_stale_replacement_credit_in_supervisor_views(): void
    {
        $request = $this->requestChange();
        $this->decision($request);
        $this->completeExtra();
        DB::table('reporting_relationships')->where('user_id', $this->employee->id)->where('manager_id', $this->manager->id)->where('department_id', $this->department)->delete();
        $this->actingAs($this->hof)->getJson('/api/hof/workspace')->assertOk()->assertJsonPath('activities.0.assignment.confirmed_progress', 0);
    }

    public function test_partial_replacement_keeps_the_remaining_original_share_and_preserves_evidence(): void
    {
        $request = $this->requestChange(.3);
        $this->decision($request);
        $this->completeExtra();
        $this->actingAs($this->employee)->getJson('/api/delivery/workspace')->assertJsonPath('tasks.0.confirmed_progress', 30);
        $task = DB::table('delivery_tasks')->find($this->task);
        $this->actingAs($this->employee)->postJson('/api/delivery/tasks/'.$this->task.'/submit', ['version' => $task->version, 'evidence' => 'Remaining original maintenance completed.'])->assertOk();
        $submission = (int) DB::table('task_submissions')->where('task_id', $this->task)->max('id');
        $this->actingAs($this->manager)->postJson('/api/delivery/submissions/'.$submission.'/review', ['version' => $task->version + 1, 'decision' => 'approved'])->assertOk();
        $this->actingAs($this->employee)->getJson('/api/delivery/workspace')->assertJsonPath('tasks.0.confirmed_progress', 100)->assertJsonPath('tasks.0.native_confirmed_progress', 100)->assertJsonPath('tasks.0.timeliness.score', 65);
        $this->assertDatabaseCount('task_submissions', 1);
        $this->assertDatabaseCount('extra_work_submissions', 1);
        $this->assertSame(.3, (float) DB::table('work_replacements')->value('fraction'));
    }

    public function test_reviewer_can_extend_deadline_or_reassign_unstarted_original_work_without_substitution(): void
    {
        $request = $this->requestChange();
        $this->decision($request, 'extend_deadline', ['due_date' => '2026-12-10']);
        $this->assertSame('2026-12-10', substr(DB::table('delivery_tasks')->where('id', $this->task)->value('due_date'), 0, 10));
        $this->assertDatabaseCount('work_replacements', 0);
        $next = $this->requestChange();
        $this->decision($next, 'reassign', ['assignee_id' => $this->other->id]);
        $this->assertDatabaseHas('delivery_tasks', ['id' => $this->task, 'assignee_id' => $this->other->id]);
        $this->assertDatabaseCount('work_replacements', 0);
        $this->actingAs($this->other)->getJson('/api/delivery/workspace')->assertOk()->assertJsonCount(2, 'tasks');
    }

    public function test_requests_enforce_original_reviewer_same_person_year_scope_versions_and_read_only_access(): void
    {
        $payload = ['task_id' => $this->task, 'task_version' => 1, 'extra_assignment_id' => $this->extra, 'requested_fraction' => 1, 'reason' => 'Capacity conflict'];
        $this->actingAs($this->other)->postJson('/api/work-changes', $payload)->assertForbidden();
        $wrongPerson = $this->assignExtra($this->other);
        $this->actingAs($this->employee)->postJson('/api/work-changes', [...$payload, 'extra_assignment_id' => $wrongPerson])->assertUnprocessable();
        $wrongYear = $this->assignExtra($this->employee, 2027);
        $this->actingAs($this->employee)->postJson('/api/work-changes', [...$payload, 'extra_assignment_id' => $wrongYear])->assertUnprocessable();
        $request = $this->requestChange();
        $this->actingAs($this->employee)->postJson('/api/work-changes', $payload)->assertConflict();
        $decision = ['version' => 1, 'task_version' => 1, 'extra_version' => 1, 'decision' => 'replace', 'fraction' => 1, 'decision_note' => 'Test'];
        $this->postJson('/api/work-changes/'.$request.'/decide', $decision)->assertForbidden();
        $this->actingAs($this->other)->postJson('/api/work-changes/'.$request.'/cancel')->assertForbidden();
        $this->actingAs($this->manager)->postJson('/api/work-changes/'.$request.'/decide', [...$decision, 'task_version' => 99])->assertConflict();
        $finance = User::where('employee_number', 'EMP006')->firstOrFail();
        $this->actingAs($finance)->getJson('/api/work-changes/workspace')->assertOk()->assertJsonCount(0, 'requests')->assertJsonCount(0, 'original_tasks');
        $md = User::factory()->create(['system_role' => 'md', 'is_active' => true]);
        $this->actingAs($md)->getJson('/api/work-changes/workspace')->assertOk()->assertJsonCount(1, 'requests')->assertJsonPath('read_only', true)->assertJsonPath('requests.0.can_decide', false);
        $this->postJson('/api/work-changes/'.$request.'/decide', $decision)->assertForbidden();
        $this->decision($request);
        $this->actingAs($this->manager)->postJson('/api/work-changes/'.$request.'/decide', $decision)->assertConflict();
        $this->assertSame(1, DB::table('work_replacements')->count());
    }
}
