<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeliveryWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $hof;

    private User $manager;

    private User $employee;

    private User $otherEmployee;

    private User $editor;

    private int $plan;

    private int $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->hof = $this->user('EMP001');
        $this->manager = $this->user('EMP004');
        $this->employee = $this->user('EMP007');
        $this->otherEmployee = $this->user('EMP008');
        $this->editor = $this->user('EMP011');
        $department = DB::table('departments')->where('code', 'IT')->value('id');
        $this->actingAs($this->editor);
        $payload = ['code' => 'DELIVERY-2026', 'title' => 'Delivery test plan', 'owner_id' => $this->hof->id, 'year' => 2026,
            'kpas' => [['code' => 'KPA-IT', 'title' => 'Infrastructure', 'department_id' => $department, 'weight' => 1,
                'kpis' => [['code' => 'KPI-IT', 'title' => 'Project completion', 'weight' => 1, 'target_value' => 100, 'target_unit' => 'percent', 'frequency' => 'Quarterly', 'direction' => 'higher',
                    'activities' => [['code' => 'ACT-SPEC', 'title' => 'Prepare technical specifications', 'weight' => 0.25], ['code' => 'ACT-DEPLOY', 'title' => 'Deploy equipment', 'weight' => 0.75]]]]]]];
        $this->plan = $this->postJson('/api/planning/plans', $payload)->assertCreated()->json('id');
        $this->postJson('/api/planning/plans/'.$this->plan.'/publish', ['revision' => 1])->assertOk();
        $this->actingAs($this->hof)->postJson('/api/hof/assignments', ['plan_id' => $this->plan, 'activity_code' => 'ACT-SPEC', 'source_revision' => 2, 'version' => 0, 'assignee_id' => $this->manager->id])->assertOk();
        $this->assignment = (int) DB::table('work_assignments')->value('id');
        $this->actingAs($this->manager);
    }

    private function user(string $number): User
    {
        return User::where('employee_number', $number)->firstOrFail();
    }

    private function breakdownPayload(): array
    {
        return ['version' => 1, 'tasks' => [
            ['title' => 'Gather requirements', 'assignee_id' => $this->employee->id, 'weight' => 0.3, 'due_date' => '2026-12-01'],
            ['title' => 'Draft specifications', 'assignee_id' => $this->otherEmployee->id, 'weight' => 0.5],
            ['title' => 'Prepare cost estimates', 'assignee_id' => $this->manager->id, 'weight' => 0.2],
        ]];
    }

    private function allocate(): array
    {
        $this->actingAs($this->manager)->putJson('/api/delivery/assignments/'.$this->assignment.'/tasks', $this->breakdownPayload())->assertOk();

        return DB::table('delivery_tasks')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function submitTask(User $owner, int $task, int $version = 1): int
    {
        $this->actingAs($owner)->postJson('/api/delivery/tasks/'.$task.'/submit', ['version' => $version, 'evidence' => 'Completed deliverable and verified required details.', 'evidence_url' => 'https://example.test/evidence'])->assertOk();

        return (int) DB::table('task_submissions')->where('task_id', $task)->orderByDesc('id')->value('id');
    }

    public function test_manager_and_employee_login_and_workspace_isolation(): void
    {
        $this->postJson('/api/logout')->assertOk();
        foreach ([$this->manager, $this->employee] as $user) {
            $response = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'Pulse-Local-2026!'])->assertOk();
            $this->assertContains($user->id === $this->manager->id ? 'manager' : 'employee', $response->json('user.workspaces'));
            $this->getJson('/api/delivery/workspace')->assertOk();
            $this->getJson('/api/admin/workspace')->assertForbidden();
            $this->getJson('/api/planning/workspace')->assertForbidden();
            $this->postJson('/api/logout')->assertOk();
        }
        $this->getJson('/api/delivery/workspace')->assertUnauthorized();
        foreach ([$this->editor, $this->user('SYS001')] as $user) {
            $this->actingAs($user)->getJson('/api/delivery/workspace')->assertForbidden();
        }
        $this->actingAs($this->manager)->getJson('/api/delivery/workspace')->assertJsonCount(1, 'managed')->assertJsonCount(2, 'employees');
        $this->actingAs($this->user('EMP006'))->getJson('/api/delivery/workspace')->assertJsonCount(0, 'managed');
    }

    public function test_task_allocation_requires_100_percent_and_same_department_reporting_employees(): void
    {
        $invalid = $this->breakdownPayload();
        $invalid['tasks'][0]['weight'] = 0.2;
        $this->putJson('/api/delivery/assignments/'.$this->assignment.'/tasks', $invalid)->assertUnprocessable();
        $invalid = $this->breakdownPayload();
        $invalid['tasks'][0]['assignee_id'] = $this->user('EMP009')->id;
        $this->putJson('/api/delivery/assignments/'.$this->assignment.'/tasks', $invalid)->assertUnprocessable();
        $this->assertDatabaseCount('delivery_tasks', 0);
        $this->actingAs($this->employee)->putJson('/api/delivery/assignments/'.$this->assignment.'/tasks', $this->breakdownPayload())->assertForbidden();
        $tasks = $this->allocate();
        $this->assertDatabaseHas('delivery_tasks', ['id' => $tasks[0], 'reviewer_id' => $this->manager->id]);
        $this->assertDatabaseHas('delivery_tasks', ['id' => $tasks[2], 'reviewer_id' => $this->hof->id]);
        $this->getJson('/api/delivery/workspace')->assertJsonCount(1, 'tasks')->assertJsonPath('managed.0.allocation_ready', true);
        $this->actingAs($this->employee)->getJson('/api/delivery/workspace')->assertJsonCount(1, 'tasks')->assertJsonCount(0, 'managed');
    }

    public function test_reported_progress_does_not_confirm_completion_and_locks_breakdown(): void
    {
        [$task] = $this->allocate();
        $this->actingAs($this->employee)->putJson('/api/delivery/tasks/'.$task.'/progress', ['version' => 1, 'reported_progress' => 40, 'progress_note' => 'Requirements gathering in progress.'])->assertOk();
        $this->assertDatabaseHas('work_assignments', ['id' => $this->assignment, 'reported_progress' => 12, 'confirmed_progress' => 0]);
        $this->putJson('/api/delivery/tasks/'.$task.'/progress', ['version' => 1, 'reported_progress' => 50, 'progress_note' => 'Stale update'])->assertConflict();
        $this->putJson('/api/delivery/tasks/'.$task.'/progress', ['version' => 2, 'reported_progress' => 101, 'progress_note' => 'Invalid update'])->assertUnprocessable();
        $this->actingAs($this->otherEmployee)->putJson('/api/delivery/tasks/'.$task.'/progress', ['version' => 2, 'reported_progress' => 50, 'progress_note' => 'Not my task'])->assertForbidden();
        $payload = $this->breakdownPayload();
        $payload['version'] = 3;
        $this->actingAs($this->manager)->putJson('/api/delivery/assignments/'.$this->assignment.'/tasks', $payload)->assertConflict();
    }

    public function test_employee_submission_approval_rolls_up_only_approved_weight(): void
    {
        [$task] = $this->allocate();
        $submission = $this->submitTask($this->employee, $task);
        $this->assertDatabaseHas('work_assignments', ['id' => $this->assignment, 'reported_progress' => 30, 'confirmed_progress' => 0]);
        $this->postJson('/api/delivery/submissions/'.$submission.'/review', ['version' => 2, 'decision' => 'approved'])->assertForbidden();
        $this->actingAs($this->manager)->getJson('/api/delivery/workspace')->assertJsonCount(1, 'reviews')->assertJsonCount(1, 'submissions');
        $this->postJson('/api/delivery/submissions/'.$submission.'/review', ['version' => 2, 'decision' => 'approved', 'review_note' => 'Requirements verified.'])->assertOk();
        $this->assertDatabaseHas('work_assignments', ['id' => $this->assignment, 'confirmed_progress' => 30]);
        $this->actingAs($this->hof)->getJson('/api/hof/workspace')->assertJsonPath('activities.0.assignment.confirmed_progress', 30);
        $this->actingAs($this->manager)->postJson('/api/delivery/submissions/'.$submission.'/review', ['version' => 3, 'decision' => 'approved'])->assertConflict();
        $this->actingAs($this->employee)->putJson('/api/delivery/tasks/'.$task.'/progress', ['version' => 3, 'reported_progress' => 20, 'progress_note' => 'Cannot edit approved work'])->assertConflict();
    }

    public function test_notifications_cover_assignment_submission_resubmission_and_approval_without_failed_writes(): void
    {
        $this->actingAs($this->manager)->getJson('/api/notifications')->assertJsonPath('items.0.type', 'assignment');
        $before = DB::table('work_notifications')->count();
        $invalid = $this->breakdownPayload();
        $invalid['tasks'][1]['assignee_id'] = $this->user('EMP009')->id;
        $this->putJson('/api/delivery/assignments/'.$this->assignment.'/tasks', $invalid)->assertUnprocessable();
        $this->assertDatabaseCount('work_notifications', $before);
        [$task] = $this->allocate();
        $this->actingAs($this->employee)->getJson('/api/notifications')->assertJsonPath('items.0.type', 'assignment')->assertJsonPath('items.0.target.page', 'tasks');
        $submission = $this->submitTask($this->employee, $task);
        $this->actingAs($this->manager)->getJson('/api/notifications')->assertJsonPath('items.0.type', 'submission')->assertJsonPath('items.0.target.page', 'reviews');
        $this->postJson('/api/delivery/submissions/'.$submission.'/review', ['version' => 2, 'decision' => 'needs_changes', 'review_note' => 'Add validation.'])->assertOk();
        $this->actingAs($this->employee)->getJson('/api/notifications')->assertJsonPath('items.0.type', 'needs_changes');
        $submission = $this->submitTask($this->employee, $task, 3);
        $this->actingAs($this->manager)->postJson('/api/delivery/submissions/'.$submission.'/review', ['version' => 4, 'decision' => 'approved'])->assertOk();
        $this->actingAs($this->employee)->getJson('/api/notifications')->assertJsonPath('items.0.type', 'approved');
        $count = DB::table('work_notifications')->count();
        $this->actingAs($this->manager)->postJson('/api/delivery/submissions/'.$submission.'/review', ['version' => 5, 'decision' => 'approved'])->assertConflict();
        $this->assertDatabaseCount('work_notifications', $count);
    }

    public function test_overdue_reminders_deduplicate_per_day_and_ignore_approved_and_retired_work(): void
    {
        $this->travelTo(now()->setDate(2026, 12, 3)->startOfDay());
        [$task] = $this->allocate();
        $this->actingAs($this->employee)->getJson('/api/notifications')->assertJsonPath('items.0.type', 'overdue');
        $count = DB::table('work_notifications')->where('type', 'overdue')->count();
        $id = DB::table('work_notifications')->where('type', 'overdue')->value('id');
        $this->postJson('/api/notifications/'.$id.'/read')->assertOk();
        $this->getJson('/api/notifications')->assertJsonPath('items.0.read_at', DB::table('work_notifications')->where('id', $id)->value('read_at'));
        $this->assertDatabaseCount('work_notifications', 3 + $count);
        $this->travel(1)->days();
        $this->getJson('/api/notifications')->assertOk();
        $this->assertEquals($count + 1, DB::table('work_notifications')->where('type', 'overdue')->count());
        $submission = $this->submitTask($this->employee, $task);
        $this->actingAs($this->manager)->postJson('/api/delivery/submissions/'.$submission.'/review', ['version' => 2, 'decision' => 'approved'])->assertOk();
        $count = DB::table('work_notifications')->where('type', 'overdue')->count();
        $this->travel(1)->days();
        $this->actingAs($this->employee)->getJson('/api/notifications')->assertOk();
        $this->assertEquals($count, DB::table('work_notifications')->where('type', 'overdue')->count());
        $this->artisan('notifications:overdue')->assertSuccessful();
    }

    public function test_retired_tasks_do_not_send_overdue_reminders_to_previous_owner(): void
    {
        $this->allocate();
        $version = DB::table('work_assignments')->where('id', $this->assignment)->value('version');
        $this->actingAs($this->hof)->postJson('/api/hof/assignments', ['plan_id' => $this->plan, 'activity_code' => 'ACT-SPEC', 'source_revision' => 2, 'version' => $version, 'assignee_id' => $this->hof->id])->assertOk();
        $this->travelTo(now()->setDate(2026, 12, 5)->startOfDay());
        $this->actingAs($this->employee)->getJson('/api/notifications')->assertOk();
        $this->assertEquals(0, DB::table('work_notifications')->where('user_id', $this->employee->id)->where('type', 'overdue')->count());
    }

    public function test_changes_require_comments_and_resubmission_retains_history(): void
    {
        [$task] = $this->allocate();
        $submission = $this->submitTask($this->employee, $task);
        $this->actingAs($this->manager)->postJson('/api/delivery/submissions/'.$submission.'/review', ['version' => 2, 'decision' => 'needs_changes'])->assertUnprocessable();
        $this->postJson('/api/delivery/submissions/'.$submission.'/review', ['version' => 2, 'decision' => 'needs_changes', 'review_note' => 'Include the security requirements.'])->assertOk();
        $this->assertDatabaseHas('work_assignments', ['id' => $this->assignment, 'confirmed_progress' => 0]);
        $newSubmission = $this->submitTask($this->employee, $task, 3);
        $this->assertNotSame($submission, $newSubmission);
        $this->actingAs($this->manager)->postJson('/api/delivery/submissions/'.$submission.'/review', ['version' => 4, 'decision' => 'approved'])->assertConflict();
        $this->postJson('/api/delivery/submissions/'.$newSubmission.'/review', ['version' => 4, 'decision' => 'approved'])->assertOk();
        $this->assertDatabaseCount('task_submissions', 2);
        $this->assertDatabaseHas('task_submissions', ['id' => $submission, 'decision' => 'needs_changes']);
        $this->actingAs($this->employee)->getJson('/api/delivery/workspace')->assertJsonCount(2, 'submissions');
    }

    public function test_manager_own_tasks_require_hof_review_and_all_tasks_complete_parent(): void
    {
        $tasks = $this->allocate();
        foreach ([[$this->employee, $this->manager, $tasks[0]], [$this->otherEmployee, $this->manager, $tasks[1]], [$this->manager, $this->hof, $tasks[2]]] as [$owner, $reviewer, $task]) {
            $submission = $this->submitTask($owner, $task);
            $this->postJson('/api/delivery/submissions/'.$submission.'/review', ['version' => 2, 'decision' => 'approved'])->assertForbidden();
            $this->actingAs($reviewer)->postJson('/api/delivery/submissions/'.$submission.'/review', ['version' => 2, 'decision' => 'approved'])->assertOk();
        }
        $this->assertDatabaseHas('work_assignments', ['id' => $this->assignment, 'confirmed_progress' => 100, 'reported_progress' => 100]);
        $this->actingAs($this->manager)->getJson('/api/delivery/workspace')->assertJsonPath('managed.0.confirmed_progress', 100);
    }

    public function test_reassignment_archives_old_task_cycle_and_resets_confirmed_progress(): void
    {
        [$task] = $this->allocate();
        $submission = $this->submitTask($this->employee, $task);
        $this->actingAs($this->manager)->postJson('/api/delivery/submissions/'.$submission.'/review', ['version' => 2, 'decision' => 'approved'])->assertOk();
        $version = DB::table('work_assignments')->where('id', $this->assignment)->value('version');
        $this->actingAs($this->hof)->postJson('/api/hof/assignments', ['plan_id' => $this->plan, 'activity_code' => 'ACT-SPEC', 'source_revision' => 2, 'version' => $version, 'assignee_id' => $this->hof->id])->assertOk();
        $this->assertDatabaseHas('work_assignments', ['id' => $this->assignment, 'delivery_generation' => 2, 'confirmed_progress' => 0]);
        $this->actingAs($this->employee)->getJson('/api/delivery/workspace')->assertJsonCount(0, 'tasks')->assertJsonCount(0, 'submissions');
        $this->putJson('/api/delivery/tasks/'.$task.'/progress', ['version' => 3, 'reported_progress' => 40, 'progress_note' => 'Old task'])->assertConflict();
        $this->assertDatabaseCount('delivery_tasks', 3);
        $this->assertDatabaseCount('task_submissions', 1);
    }

    public function test_published_scope_changes_block_reviews_and_suppress_confirmed_rollup(): void
    {
        [$task] = $this->allocate();
        $submission = $this->submitTask($this->employee, $task);
        $this->actingAs($this->editor);
        $draft = $this->getJson('/api/planning/plans/'.$this->plan)->json('plan');
        $draft['kpas'][0]['kpis'][0]['activities'][0]['title'] = 'New scope for specifications';
        $this->putJson('/api/planning/plans/'.$this->plan, $draft)->assertOk();
        $this->postJson('/api/planning/plans/'.$this->plan.'/publish', ['revision' => 3])->assertOk();
        $this->actingAs($this->manager)->postJson('/api/delivery/submissions/'.$submission.'/review', ['version' => 2, 'decision' => 'approved'])->assertConflict();
        $this->getJson('/api/delivery/workspace')->assertJsonPath('managed.0.valid', false)->assertJsonCount(0, 'reviews');
        $this->actingAs($this->hof)->getJson('/api/hof/workspace')->assertJsonPath('activities.0.source_changed', true)->assertJsonPath('activities.0.assignment.confirmed_progress', 0);
    }

    public function test_submission_evidence_and_reviewer_scope_are_validated(): void
    {
        [$task] = $this->allocate();
        $this->actingAs($this->employee)->postJson('/api/delivery/tasks/'.$task.'/submit', ['version' => 1, 'evidence' => ''])->assertUnprocessable();
        $this->postJson('/api/delivery/tasks/'.$task.'/submit', ['version' => 1, 'evidence' => 'Completed work', 'evidence_url' => 'javascript:alert(1)'])->assertUnprocessable();
        $submission = $this->submitTask($this->employee, $task);
        $this->actingAs($this->user('EMP006'))->postJson('/api/delivery/submissions/'.$submission.'/review', ['version' => 2, 'decision' => 'approved'])->assertForbidden();
        $this->manager->update(['is_active' => false]);
        $this->actingAs($this->employee)->getJson('/api/delivery/workspace')->assertJsonCount(0, 'tasks');
    }
}
