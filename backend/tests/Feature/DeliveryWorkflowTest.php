<?php

namespace Tests\Feature;

use App\Http\Controllers\NotificationController;
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

    public function test_editor_can_delete_a_published_plan_with_linked_work_after_confirming_current_details(): void
    {
        [$parent] = $this->allocate();
        $this->actingAs($this->employee)->putJson('/api/delivery/tasks/'.$parent.'/tasks', [
            'version' => 1, 'tasks' => [['title' => 'Detailed installation', 'assignee_id' => $this->employee->id, 'weight' => 1]],
        ])->assertOk();
        $child = (int) DB::table('delivery_tasks')->where('parent_task_id', $parent)->value('id');
        $this->actingAs($this->hof)->postJson('/api/hof/kpi-assignments', [
            'plan_id' => $this->plan, 'kpi_code' => 'KPI-IT', 'source_revision' => 2, 'version' => 0, 'assignee_id' => $this->manager->id,
            'proposed_personal_weight' => 0.5,
        ])->assertOk();
        DB::table('monthly_kpi_reviewers')->insert(['plan_id' => $this->plan, 'kpi_code' => 'KPI-IT', 'reviewer_id' => $this->hof->id]);
        DB::table('monthly_kpi_submissions')->insert([
            'plan_id' => $this->plan, 'kpi_code' => 'KPI-IT', 'month' => 1, 'attempt' => 1,
            'source_revision' => 2, 'target_value' => 100, 'target_unit' => 'percent', 'direction' => 'higher',
            'actual_value' => 80, 'evidence' => 'Historical result.', 'submitted_by' => $this->manager->id,
            'status' => 'approved', 'created_at' => now(),
        ]);
        DB::table('personal_weight_proposals')->insert([
            'owner_id' => $this->manager->id, 'actor_id' => $this->hof->id, 'source_key' => 'unrelated-source', 'weight' => 0.2,
        ]);
        NotificationController::send($this->manager->id, 'unrelated-test', 'assignment', 'Another plan notification.', ['workspace' => 'delivery', 'page' => 'tasks']);
        $url = '/api/planning/plans/'.$this->plan;
        $this->getJson($url.'/deletion')->assertForbidden();
        $this->actingAs($this->editor);
        $unrelated = $this->postJson('/api/planning/plans', [
            'code' => 'OTHER-PLAN', 'title' => 'Other plan', 'owner_id' => $this->user('EMP002')->id, 'year' => 2026, 'kpas' => [],
        ])->assertCreated()->json('id');
        $preview = $this->getJson($url.'/deletion')->assertOk()->assertJsonPath('counts.tasks', 4)
            ->assertJsonPath('counts.monthly_results', 1)->json();
        $submission = $this->submitTask($this->employee, $child);
        $this->actingAs($this->editor)->deleteJson($url, ['revision' => $preview['revision'], 'deletion_token' => $preview['deletion_token']])->assertConflict();
        $this->actingAs($this->manager)->postJson('/api/delivery/submissions/'.$submission.'/review', ['version' => 2, 'decision' => 'approved'])->assertOk();
        $this->actingAs($this->editor);
        $preview = $this->getJson($url.'/deletion')->assertOk()->assertJsonPath('counts.submissions', 1)->json();
        $confirmation = ['revision' => $preview['revision'], 'deletion_token' => $preview['deletion_token']];
        $this->actingAs($this->hof)->deleteJson($url, $confirmation)->assertForbidden();
        $this->actingAs($this->editor)->deleteJson($url, $confirmation)->assertOk();
        $this->getJson($url)->assertNotFound();
        $this->assertDatabaseHas('performance_plans', ['id' => $unrelated]);
        foreach (['work_assignments', 'kpi_delegations', 'monthly_kpi_submissions', 'monthly_kpi_reviewers'] as $table) {
            $this->assertDatabaseMissing($table, ['plan_id' => $this->plan]);
        }
        $this->assertDatabaseCount('delivery_tasks', 0);
        $this->assertDatabaseCount('task_submissions', 0);
        $this->assertDatabaseHas('personal_weight_proposals', ['source_key' => 'unrelated-source']);
        $this->assertDatabaseCount('personal_weight_proposals', 1);
        $this->assertDatabaseHas('work_notifications', ['event_key' => 'unrelated-test']);
        $this->assertDatabaseCount('work_notifications', 1);
        $this->assertDatabaseHas('admin_activity', ['action' => 'plan.removed', 'actor_id' => $this->editor->id]);
        $this->assertDatabaseHas('users', ['id' => $this->hof->id]);
        $this->assertDatabaseHas('reporting_relationships', ['user_id' => $this->employee->id, 'manager_id' => $this->manager->id]);
    }

    public function test_reporting_tree_weights_roll_up_and_personal_performance_rebalances(): void
    {
        $department = DB::table('work_assignments')->where('id', $this->assignment)->value('department_id');
        $junior = User::factory()->create(['system_role' => 'member', 'is_active' => true]);
        DB::table('department_memberships')->insert(['user_id' => $junior->id, 'department_id' => $department, 'responsibility' => 'employee']);
        DB::table('reporting_relationships')->insert(['user_id' => $junior->id, 'manager_id' => $this->employee->id, 'department_id' => $department]);
        $this->putJson('/api/delivery/assignments/'.$this->assignment.'/tasks', [
            'version' => 1, 'tasks' => [
                ['title' => 'Team delivery', 'assignee_id' => $this->employee->id, 'weight' => 0.7],
                ['title' => 'Manager coordination', 'assignee_id' => $this->manager->id, 'weight' => 0.3],
            ],
        ])->assertOk();
        $parent = DB::table('delivery_tasks')->where('assignment_id', $this->assignment)->where('assignee_id', $this->employee->id)->first();
        $own = DB::table('delivery_tasks')->where('assignment_id', $this->assignment)->where('assignee_id', $this->manager->id)->first();
        $branch = ['version' => 1, 'tasks' => [
            ['title' => 'Technical work', 'assignee_id' => $junior->id, 'weight' => 0.8],
            ['title' => 'Validation', 'assignee_id' => $this->employee->id, 'weight' => 0.2],
        ]];
        $this->actingAs($this->otherEmployee)->putJson('/api/delivery/tasks/'.$parent->id.'/tasks', $branch)->assertForbidden();
        $this->actingAs($this->employee)->putJson('/api/delivery/tasks/'.$parent->id.'/tasks', [
            ...$branch, 'tasks' => [...$branch['tasks'], ['title' => 'Extra', 'assignee_id' => $junior->id, 'weight' => 0.1]],
        ])->assertUnprocessable();
        $this->putJson('/api/delivery/tasks/'.$parent->id.'/tasks', $branch)->assertOk();
        $this->getJson('/api/delivery/workspace')->assertJsonPath('tasks.0.has_children', true);
        $this->postJson('/api/delivery/tasks/'.$parent->id.'/submit', ['version' => 2, 'evidence' => 'Cannot bypass delegated work.'])->assertConflict();
        $child = DB::table('delivery_tasks')->where('parent_task_id', $parent->id)->where('assignee_id', $junior->id)->first();
        $validation = DB::table('delivery_tasks')->where('parent_task_id', $parent->id)->where('assignee_id', $this->employee->id)->first();
        $submission = $this->submitTask($junior, (int) $child->id);
        $this->assertDatabaseHas('work_assignments', ['id' => $this->assignment, 'confirmed_progress' => 0]);
        $this->actingAs($this->employee)->postJson('/api/delivery/submissions/'.$submission.'/review', ['version' => 2, 'decision' => 'approved'])->assertOk();
        $this->assertDatabaseHas('delivery_tasks', ['id' => $parent->id, 'confirmed_progress' => 80]);
        $this->assertDatabaseHas('work_assignments', ['id' => $this->assignment, 'confirmed_progress' => 56]);
        $this->getJson('/api/allocations/people/'.$this->employee->id.'/2026')
            ->assertJsonCount(1, 'live_score.entries')->assertJsonPath('live_score.confirmed_completion', 80);
        $this->actingAs($this->manager)->getJson('/api/allocations/people/'.$this->manager->id.'/2026')
            ->assertJsonPath('live_score.entries.0.weight', 1)->assertJsonPath('live_score.confirmed_completion', 56);
        $submission = $this->submitTask($this->employee, (int) $validation->id);
        $this->actingAs($this->manager)->postJson('/api/delivery/submissions/'.$submission.'/review', ['version' => 2, 'decision' => 'approved'])->assertOk();
        $this->assertDatabaseHas('work_assignments', ['id' => $this->assignment, 'confirmed_progress' => 70]);
        $submission = $this->submitTask($this->manager, (int) $own->id);
        $this->actingAs($this->hof)->postJson('/api/delivery/submissions/'.$submission.'/review', ['version' => 2, 'decision' => 'approved'])->assertOk();
        $this->assertDatabaseHas('work_assignments', ['id' => $this->assignment, 'confirmed_progress' => 100]);
        $this->actingAs($this->manager)->getJson('/api/allocations/people/'.$this->manager->id.'/2026')
            ->assertJsonPath('live_score.confirmed_completion', 100);
        $this->actingAs($this->hof)->postJson('/api/hof/assignments', [
            'plan_id' => $this->plan, 'activity_code' => 'ACT-DEPLOY', 'source_revision' => 2,
            'version' => 0, 'assignee_id' => $this->manager->id,
        ])->assertOk();
        $this->actingAs($this->manager)->getJson('/api/allocations/people/'.$this->manager->id.'/2026')
            ->assertJsonPath('live_score.entries.0.weight', 0.25)
            ->assertJsonPath('live_score.entries.1.weight', 0.75)
            ->assertJsonPath('live_score.confirmed_completion', 25);
        $md = User::factory()->create(['system_role' => 'md', 'is_active' => true]);
        $this->actingAs($md)->getJson('/api/md/workspace?year=2026')->assertJsonPath('activities.0.approved', 100);
        DB::table('reporting_relationships')->where('user_id', $junior->id)->delete();
        $this->getJson('/api/md/workspace?year=2026')->assertJsonPath('activities.0.approved', 0);
        $executiveRows = $this->actingAs($md)->getJson('/api/md/workspace?year=2026')->assertOk()->json('activities');
        $taskDetail = collect($executiveRows)->flatMap(fn ($row) => $row['tasks'])->firstWhere('id', $child->id);
        $this->assertEquals(0.8, $taskDetail['weight']);
        $this->assertEquals(0.56, $taskDetail['activity_weight']);
        $version = DB::table('work_assignments')->where('id', $this->assignment)->value('version');
        $this->actingAs($this->hof)->postJson('/api/hof/assignments', [
            'plan_id' => $this->plan, 'activity_code' => 'ACT-SPEC', 'source_revision' => 2,
            'version' => $version, 'assignee_id' => $this->manager->id,
        ])->assertOk();
        $this->assertDatabaseHas('work_assignments', ['id' => $this->assignment, 'delivery_generation' => 2, 'confirmed_progress' => 0]);
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
        NotificationController::remind($this->employee);
        $this->actingAs($this->employee)->getJson('/api/notifications')->assertJsonPath('items.0.type', 'overdue');
        $count = DB::table('work_notifications')->where('type', 'overdue')->count();
        $id = DB::table('work_notifications')->where('type', 'overdue')->value('id');
        $this->postJson('/api/notifications/'.$id.'/read')->assertOk();
        $this->getJson('/api/notifications')->assertJsonPath('items.0.read_at', DB::table('work_notifications')->where('id', $id)->value('read_at'));
        $this->assertDatabaseCount('work_notifications', 3 + $count);
        $this->travel(1)->days();
        NotificationController::remind($this->employee);
        $this->getJson('/api/notifications')->assertOk();
        $this->assertEquals($count + 1, DB::table('work_notifications')->where('type', 'overdue')->count());
        $submission = $this->submitTask($this->employee, $task);
        $this->actingAs($this->manager)->postJson('/api/delivery/submissions/'.$submission.'/review', ['version' => 2, 'decision' => 'approved'])->assertOk();
        $count = DB::table('work_notifications')->where('type', 'overdue')->count();
        $this->travel(1)->days();
        NotificationController::remind($this->employee);
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
        NotificationController::remind($this->employee);
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
        $this->assertDatabaseCount('delivery_tasks', 4);
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

    public function test_negative_and_excessive_task_weights_do_not_change_the_saved_breakdown(): void
    {
        foreach ([-0.1, 1.5] as $weight) {
            $payload = $this->breakdownPayload();
            $payload['tasks'][0]['weight'] = $weight;
            $this->putJson('/api/delivery/assignments/'.$this->assignment.'/tasks', $payload)->assertUnprocessable();
            $this->assertDatabaseCount('delivery_tasks', 0);
            $this->assertDatabaseHas('work_assignments', ['id' => $this->assignment, 'version' => 1, 'confirmed_progress' => 0]);
        }
    }

    public function test_replaying_an_approval_cannot_double_count_work_or_change_a_decision(): void
    {
        [$task] = $this->allocate();
        $submission = $this->submitTask($this->employee, $task);
        $url = '/api/delivery/submissions/'.$submission.'/review';
        $this->actingAs($this->manager)->postJson($url, ['version' => 2, 'decision' => 'approved'])->assertOk();
        $version = (int) DB::table('delivery_tasks')->where('id', $task)->value('version');
        $this->postJson($url, ['version' => 2, 'decision' => 'approved'])->assertConflict();
        $this->postJson($url, ['version' => $version, 'decision' => 'needs_changes', 'review_note' => 'Replayed decision'])->assertConflict();
        $this->assertDatabaseHas('task_submissions', ['id' => $submission, 'decision' => 'approved']);
        $this->assertDatabaseHas('work_assignments', ['id' => $this->assignment, 'confirmed_progress' => 30]);
        $this->assertDatabaseHas('delivery_tasks', ['id' => $task, 'version' => $version, 'confirmed_progress' => 100]);
    }

    public function test_notification_actions_follow_work_state_and_target_exact_submission(): void
    {
        [$task] = $this->allocate();
        $feed = $this->actingAs($this->employee)->getJson('/api/notifications?view=actions&category=assignments')->assertOk()->assertJsonCount(1, 'items');
        $feed->assertJsonPath('items.0.target.task_id', $task)->assertJsonPath('items.0.action_required', true);
        $id = $feed->json('items.0.id');
        $this->postJson('/api/notifications/'.$id.'/read')->assertOk();
        $this->getJson('/api/notifications?view=actions&category=assignments')->assertJsonCount(1, 'items');
        $this->postJson('/api/delivery/tasks/'.$task.'/submit', ['version' => 1, 'evidence' => 'Requirements documented and checked.'])->assertOk();
        $submission = (int) DB::table('task_submissions')->where('task_id', $task)->value('id');
        $this->actingAs($this->manager)->getJson('/api/notifications?view=actions&category=reviews')
            ->assertJsonCount(1, 'items')->assertJsonPath('items.0.target.task_id', $task)->assertJsonPath('items.0.target.submission_id', $submission);
        $this->postJson('/api/delivery/submissions/'.$submission.'/review', ['version' => 2, 'decision' => 'approved'])->assertOk();
        $this->getJson('/api/notifications?view=actions&category=reviews')->assertJsonCount(0, 'items');
        $this->actingAs($this->employee)->getJson('/api/notifications?view=actions&category=assignments')->assertJsonCount(0, 'items');
        $this->getJson('/api/notifications?category=decisions')->assertJsonPath('items.0.work_state', 'approved')->assertJsonPath('items.0.action_required', false);
    }

    public function test_due_soon_reminders_are_deduplicated_and_stop_after_approval(): void
    {
        [$task] = $this->allocate();
        DB::table('delivery_tasks')->where('id', $task)->update(['due_date' => now('Asia/Colombo')->addDay()->toDateString()]);
        NotificationController::remind($this->employee);
        $feed = $this->actingAs($this->employee)->getJson('/api/notifications?category=deadlines')->assertOk()->assertJsonCount(1, 'items');
        $feed->assertJsonPath('items.0.type', 'due_soon');
        $this->getJson('/api/notifications?category=deadlines')->assertJsonCount(1, 'items');
        $this->postJson('/api/delivery/tasks/'.$task.'/submit', ['version' => 1, 'evidence' => 'Work delivered.'])->assertOk();
        $submission = (int) DB::table('task_submissions')->where('task_id', $task)->value('id');
        $this->actingAs($this->manager)->postJson('/api/delivery/submissions/'.$submission.'/review', ['version' => 2, 'decision' => 'approved'])->assertOk();
        $count = DB::table('work_notifications')->where('user_id', $this->employee->id)->count();
        $this->travel(1)->days();
        NotificationController::remind($this->employee);
        $this->actingAs($this->employee)->getJson('/api/notifications')->assertOk();
        $this->assertEquals($count, DB::table('work_notifications')->where('user_id', $this->employee->id)->count());
    }

    public function test_polling_is_read_only_and_reuses_task_state_within_one_feed(): void
    {
        [$task] = $this->allocate();
        for ($i = 0; $i < 35; $i++) {
            NotificationController::send($this->employee->id, 'repeat-task:'.$i, 'assignment', 'Task update', ['workspace' => 'delivery', 'page' => 'tasks', 'task_id' => $task]);
        }
        $before = DB::table('work_notifications')->count();
        DB::enableQueryLog();
        $this->actingAs($this->employee)->getJson('/api/notifications?limit=60')->assertOk()->assertJsonCount(36, 'items');
        $queries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'task_submissions'));
        DB::disableQueryLog();
        $this->assertCount(1, $queries);
        $this->assertDatabaseCount('work_notifications', $before);
    }
}
