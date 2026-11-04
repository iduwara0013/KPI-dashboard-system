<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HofWorkTest extends TestCase
{
    use RefreshDatabase;

    private User $hof;

    private User $editor;

    private User $manager;

    private int $department;

    private int $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->hof = User::where('employee_number', 'EMP001')->firstOrFail();
        $this->editor = User::where('system_role', 'plan_editor')->firstOrFail();
        $this->manager = User::where('employee_number', 'EMP004')->firstOrFail();
        $this->department = (int) DB::table('departments')->where('code', 'IT')->value('id');
        $this->actingAs($this->editor);
        $payload = ['code' => 'HOF-2026', 'title' => 'HOF delivery plan', 'year' => 2026, 'owner_id' => $this->hof->id,
            'kpas' => [['code' => 'KPA-IT', 'title' => 'Infrastructure', 'department_id' => $this->department, 'weight' => 1,
                'kpis' => [['code' => 'KPI-PROJECT', 'title' => 'Project completion', 'weight' => 1, 'target_value' => 100,
                    'target_unit' => 'percent', 'direction' => 'higher', 'frequency' => 'Quarterly', 'activities' => [
                        ['code' => 'ACT-SPEC', 'title' => 'Prepare specifications', 'weight' => 0.25, 'due_date' => '2026-12-01'],
                        ['code' => 'ACT-DEPLOY', 'title' => 'Deploy infrastructure', 'weight' => 0.75],
                    ]]]]]];
        $this->plan = $this->postJson('/api/planning/plans', $payload)->assertCreated()->json('id');
        $this->postJson('/api/planning/plans/'.$this->plan.'/publish', ['revision' => 1])->assertOk();
        $this->actingAs($this->hof);
    }

    private function assignment(array $overrides = []): array
    {
        return array_replace(['plan_id' => $this->plan, 'activity_code' => 'ACT-SPEC', 'source_revision' => 2,
            'version' => 0, 'assignee_id' => $this->manager->id, 'due_date' => '2026-12-01', 'instructions' => 'Deliver approved specifications.'], $overrides);
    }

    public function test_only_active_hofs_can_access_work_endpoints(): void
    {
        foreach ([$this->editor, $this->manager, User::where('system_role', 'super_admin')->firstOrFail()] as $user) {
            $this->actingAs($user)->getJson('/api/hof/workspace')->assertForbidden();
            $this->postJson('/api/hof/assignments', $this->assignment())->assertForbidden();
            $this->putJson('/api/hof/assignments/1/progress', [])->assertForbidden();
        }
        $this->hof->update(['is_active' => false]);
        $this->actingAs($this->hof)->getJson('/api/hof/workspace')->assertForbidden();
        $this->postJson('/api/logout')->assertOk();
        $this->getJson('/api/hof/workspace')->assertUnauthorized();
        $this->postJson('/api/hof/assignments', $this->assignment())->assertUnauthorized();
    }

    public function test_dashboard_uses_published_activities_and_scopes_managers_and_team(): void
    {
        $response = $this->getJson('/api/hof/workspace')->assertOk()->assertJsonCount(2, 'activities')
            ->assertJsonCount(2, 'departments')->assertJsonCount(2, 'managers')->assertJsonCount(5, 'team')
            ->assertJsonPath('activities.0.personal_weight', 0.25)->assertJsonPath('activities.0.assignment', null);
        $this->assertNotContains('Tharindu Senanayake', array_column($response->json('managers'), 'name'));
        $this->assertNotContains('Amali De Silva', array_column($response->json('team'), 'name'));
        $this->actingAs($this->editor);
        $draft = $this->getJson('/api/planning/plans/'.$this->plan)->json('plan');
        $draft['kpas'][0]['kpis'][0]['activities'][0]['title'] = 'Private unpublished activity title';
        $this->putJson('/api/planning/plans/'.$this->plan, $draft)->assertOk();
        $this->actingAs($this->hof)->getJson('/api/hof/workspace')->assertJsonPath('activities.0.activity', 'Prepare specifications');
        $other = User::where('employee_number', 'EMP002')->firstOrFail();
        $this->actingAs($other)->getJson('/api/hof/workspace')->assertJsonCount(0, 'activities')->assertJsonCount(1, 'departments');
        $this->postJson('/api/hof/assignments', $this->assignment())->assertForbidden();
    }

    public function test_hof_can_assign_to_same_department_reporting_manager_and_keep_own_work(): void
    {
        $this->postJson('/api/hof/assignments', $this->assignment())->assertOk();
        $this->postJson('/api/hof/assignments', $this->assignment(['activity_code' => 'ACT-DEPLOY', 'assignee_id' => $this->hof->id]))->assertOk();
        $this->assertDatabaseHas('work_assignments', ['plan_id' => $this->plan, 'activity_code' => 'ACT-SPEC', 'assignee_id' => $this->manager->id, 'reported_progress' => 0]);
        $this->getJson('/api/hof/workspace')->assertJsonPath('activities.0.assignment.due_date', '2026-12-01')
            ->assertJsonPath('activities.1.assignment.assignee_id', $this->hof->id)->assertJsonPath('activities.0.source_changed', false);
        $this->assertDatabaseHas('admin_activity', ['actor_id' => $this->hof->id, 'action' => 'work.assigned', 'subject' => 'ACT-SPEC']);
    }

    public function test_cross_department_and_non_reporting_recipients_are_rejected(): void
    {
        foreach (['EMP005', 'EMP006', 'EMP009'] as $number) {
            $assignee = User::where('employee_number', $number)->firstOrFail();
            $this->postJson('/api/hof/assignments', $this->assignment(['assignee_id' => $assignee->id]))->assertUnprocessable();
        }
        DB::table('reporting_relationships')->where('user_id', $this->manager->id)->delete();
        $this->postJson('/api/hof/assignments', $this->assignment())->assertUnprocessable();
        $this->assertDatabaseCount('work_assignments', 0);
    }

    public function test_own_progress_remains_reported_and_cannot_update_delegated_work(): void
    {
        $this->postJson('/api/hof/assignments', $this->assignment(['assignee_id' => $this->hof->id]))->assertOk();
        $id = DB::table('work_assignments')->value('id');
        $this->putJson('/api/hof/assignments/'.$id.'/progress', ['version' => 1, 'reported_progress' => 100, 'progress_note' => 'Specifications prepared.'])->assertOk();
        $this->getJson('/api/hof/workspace')->assertJsonPath('activities.0.assignment.reported_progress', 100);
        $this->assertDatabaseHas('work_assignments', ['id' => $id, 'version' => 2]);
        $this->putJson('/api/hof/assignments/'.$id.'/progress', ['version' => 1, 'reported_progress' => 20, 'progress_note' => 'Stale update'])->assertConflict();
        $this->postJson('/api/hof/assignments', $this->assignment(['version' => 2]))->assertOk();
        $this->assertDatabaseHas('work_assignments', ['id' => $id, 'assignee_id' => $this->manager->id, 'reported_progress' => 0, 'progress_note' => null]);
        $this->putJson('/api/hof/assignments/'.$id.'/progress', ['version' => 3, 'reported_progress' => 80, 'progress_note' => 'Not own work'])->assertForbidden();
        $this->assertDatabaseHas('admin_activity', ['action' => 'work.progress_updated', 'actor_id' => $this->hof->id]);
    }

    public function test_assignment_conflicts_and_invalid_input_do_not_change_existing_work(): void
    {
        $this->postJson('/api/hof/assignments', $this->assignment())->assertOk();
        $this->postJson('/api/hof/assignments', $this->assignment())->assertConflict();
        $this->postJson('/api/hof/assignments', $this->assignment(['version' => 1, 'source_revision' => 1]))->assertConflict();
        $this->postJson('/api/hof/assignments', $this->assignment(['activity_code' => 'ACT-UNKNOWN']))->assertUnprocessable();
        $this->postJson('/api/hof/assignments', $this->assignment(['due_date' => 'not-a-date']))->assertUnprocessable();
        $this->assertDatabaseCount('work_assignments', 1);
    }

    public function test_unpublished_work_and_out_of_range_progress_cannot_be_saved(): void
    {
        DB::table('performance_plans')->where('id', $this->plan)->update(['published_revision' => null, 'status' => 'draft']);
        $this->getJson('/api/hof/workspace')->assertJsonCount(0, 'activities');
        $this->postJson('/api/hof/assignments', $this->assignment())->assertConflict();
        DB::table('performance_plans')->where('id', $this->plan)->update(['published_revision' => 2, 'status' => 'published']);
        $this->postJson('/api/hof/assignments', $this->assignment(['assignee_id' => $this->hof->id]))->assertOk();
        $id = DB::table('work_assignments')->value('id');
        foreach ([-1, 101] as $progress) {
            $this->putJson('/api/hof/assignments/'.$id.'/progress', ['version' => 1, 'reported_progress' => $progress, 'progress_note' => 'Invalid update'])->assertUnprocessable();
        }
        $this->putJson('/api/hof/assignments/'.$id.'/progress', ['version' => 1, 'reported_progress' => 20, 'progress_note' => ''])->assertUnprocessable();
        $this->assertDatabaseHas('work_assignments', ['id' => $id, 'reported_progress' => 0, 'version' => 1]);
    }

    public function test_changed_published_work_requires_review_and_retired_assignments_are_preserved(): void
    {
        $this->postJson('/api/hof/assignments', $this->assignment(['assignee_id' => $this->hof->id]))->assertOk();
        $assignment = DB::table('work_assignments')->value('id');
        $this->putJson('/api/hof/assignments/'.$assignment.'/progress', ['version' => 1, 'reported_progress' => 50, 'progress_note' => 'Draft prepared'])->assertOk();
        $this->actingAs($this->editor);
        $draft = $this->getJson('/api/planning/plans/'.$this->plan)->json('plan');
        $draft['kpas'][0]['kpis'][0]['activities'][0]['title'] = 'Prepare revised specifications';
        $this->putJson('/api/planning/plans/'.$this->plan, $draft)->assertOk();
        $this->postJson('/api/planning/plans/'.$this->plan.'/publish', ['revision' => 3])->assertOk();
        $this->actingAs($this->hof)->getJson('/api/hof/workspace')->assertJsonPath('activities.0.source_changed', true);
        $this->putJson('/api/hof/assignments/'.$assignment.'/progress', ['version' => 2, 'reported_progress' => 75, 'progress_note' => 'New update'])->assertConflict();
        $this->postJson('/api/hof/assignments', $this->assignment(['assignee_id' => $this->hof->id, 'source_revision' => 4, 'version' => 2]))->assertOk();
        $this->assertDatabaseHas('work_assignments', ['id' => $assignment, 'reported_progress' => 0]);
        $this->actingAs($this->editor);
        $draft = $this->getJson('/api/planning/plans/'.$this->plan)->json('plan');
        array_shift($draft['kpas'][0]['kpis'][0]['activities']);
        $draft['kpas'][0]['kpis'][0]['activities'][0]['weight'] = 1;
        $this->putJson('/api/planning/plans/'.$this->plan, $draft)->assertOk();
        $this->postJson('/api/planning/plans/'.$this->plan.'/publish', ['revision' => 5])->assertOk();
        $this->actingAs($this->hof)->getJson('/api/hof/workspace')->assertJsonCount(1, 'activities')->assertJsonCount(1, 'retired_assignments');
        $this->assertDatabaseCount('work_assignments', 1);
        $this->postJson('/api/hof/assignments', $this->assignment(['source_revision' => 6, 'version' => 3]))->assertUnprocessable();
    }

    public function test_reporting_changes_flag_previous_manager_and_block_future_assignment(): void
    {
        $this->postJson('/api/hof/assignments', $this->assignment())->assertOk();
        $this->manager->update(['is_active' => false]);
        $this->getJson('/api/hof/workspace')->assertJsonPath('activities.0.assignee_eligible', false)->assertJsonCount(1, 'managers');
        $this->postJson('/api/hof/assignments', $this->assignment(['version' => 1]))->assertUnprocessable();
        $this->postJson('/api/hof/assignments', $this->assignment(['version' => 1, 'assignee_id' => $this->hof->id]))->assertOk();
        $this->getJson('/api/hof/workspace')->assertJsonPath('activities.0.assignee_eligible', true);
    }
}
