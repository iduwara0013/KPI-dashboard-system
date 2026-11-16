<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class KpiHandoffTest extends TestCase
{
    use RefreshDatabase;

    private User $hof;

    private User $manager;

    private User $next;

    private User $third;

    private User $worker;

    private User $editor;

    private int $department;

    private int $plan;

    private int $delegation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->hof = User::where('employee_number', 'EMP001')->firstOrFail();
        $this->manager = User::where('employee_number', 'EMP004')->firstOrFail();
        $this->editor = User::where('employee_number', 'EMP011')->firstOrFail();
        $this->grantPlanningDepartments($this->editor);
        $this->department = (int) DB::table('departments')->where('code', 'IT')->value('id');
        $this->next = $this->report($this->manager, 'manager');
        $this->third = $this->report($this->next, 'manager');
        $this->worker = $this->report($this->third, 'employee');
        $this->actingAs($this->editor);
        $this->plan = $this->postJson('/api/planning/plans', ['code' => 'KPI-CHAIN', 'title' => 'Manager KPI chain', 'owner_id' => $this->hof->id, 'year' => 2027,
            'kpas' => [['code' => 'AREA', 'title' => 'IT delivery', 'department_id' => $this->department, 'weight' => 1, 'kpis' => [
                ['code' => 'NETWORK', 'title' => 'Network improvement', 'weight' => .7, 'target_value' => 1, 'target_unit' => 'count', 'frequency' => 'On completion', 'direction' => 'higher',
                    'activities' => [['code' => 'INSTALL', 'title' => 'Install equipment', 'weight' => .7], ['code' => 'DOCUMENT', 'title' => 'Document delivery', 'weight' => .3]]],
                ['code' => 'RETAINED', 'title' => 'Management reporting', 'weight' => .3, 'target_value' => 1, 'target_unit' => 'count', 'frequency' => 'On completion', 'direction' => 'higher',
                    'activities' => [['code' => 'REPORT', 'title' => 'Management report', 'weight' => 1]]],
            ]]]])->assertCreated()->json('id');
        $this->postJson('/api/planning/plans/'.$this->plan.'/publish', ['revision' => 1])->assertOk();
        foreach (['NETWORK', 'RETAINED'] as $code) {
            $this->actingAs($this->hof)->postJson('/api/hof/kpi-assignments', ['plan_id' => $this->plan, 'kpi_code' => $code, 'source_revision' => 2, 'version' => 0, 'assignee_id' => $this->manager->id])->assertOk();
        }
        $this->delegation = (int) DB::table('kpi_delegations')->where('kpi_code', 'NETWORK')->value('id');
    }

    private function report(User $manager, string $role): User
    {
        $user = User::factory()->create(['system_role' => 'member', 'is_active' => true]);
        DB::table('department_memberships')->insert(['user_id' => $user->id, 'department_id' => $this->department, 'responsibility' => $role]);
        DB::table('reporting_relationships')->insert(['user_id' => $user->id, 'manager_id' => $manager->id, 'department_id' => $this->department]);

        return $user;
    }

    private function forward(User $actor, User $recipient, string $kind, int $id): int
    {
        $this->actingAs($actor)->postJson('/api/delivery/kpi-handoffs', ['source_kind' => $kind, 'source_id' => $id, 'version' => 1, 'assignee_id' => $recipient->id, 'due_date' => '2027-12-01'])->assertOk();

        return (int) DB::table('kpi_handoffs')->where('assigner_id', $actor->id)->latest('id')->value('id');
    }

    public function test_full_kpi_reaches_each_manager_as_one_linked_kpa_and_rolls_up_once(): void
    {
        $first = $this->forward($this->manager, $this->next, 'root', $this->delegation);
        $received = $this->actingAs($this->next)->getJson('/api/delivery/workspace')->assertOk()->assertJsonCount(1, 'delegated_kpis')->assertJsonCount(2, 'managed')->assertJsonCount(0, 'tasks')->json();
        $this->assertSame('Network improvement', $received['delegated_kpis'][0]['kpi_title']);
        $this->assertTrue($received['delegated_kpis'][0]['can_forward']);
        $this->assertTrue($received['managed'][0]['task_branch']);
        $this->assertSame('handoff:'.$first, $received['managed'][0]['linked_kpa_key']);
        $second = $this->forward($this->next, $this->third, 'handoff', $first);
        $third = $this->actingAs($this->third)->getJson('/api/delivery/workspace')->assertOk()->assertJsonCount(1, 'delegated_kpis')->assertJsonCount(2, 'managed')->assertJsonCount(0, 'tasks')->json();
        $activity = collect($third['managed'])->firstWhere('activity_code', 'INSTALL');
        $this->putJson('/api/delivery/tasks/'.$activity['id'].'/tasks', ['version' => $activity['version'], 'tasks' => [
            ['title' => 'Technical delivery', 'assignee_id' => $this->worker->id, 'weight' => .7],
            ['title' => 'Manager verification', 'assignee_id' => $this->third->id, 'weight' => .3],
        ]])->assertOk();
        $leaf = DB::table('delivery_tasks')->where('parent_task_id', $activity['id'])->where('assignee_id', $this->worker->id)->first();
        $this->actingAs($this->worker)->postJson('/api/delivery/tasks/'.$leaf->id.'/submit', ['version' => $leaf->version, 'evidence' => 'Installation completed and verified.'])->assertOk();
        $submission = DB::table('task_submissions')->where('task_id', $leaf->id)->first();
        $leaf = DB::table('delivery_tasks')->find($leaf->id);
        $this->actingAs($this->manager)->postJson('/api/delivery/submissions/'.$submission->id.'/review', ['version' => $leaf->version, 'decision' => 'approved'])->assertForbidden();
        $this->actingAs($this->third)->postJson('/api/delivery/submissions/'.$submission->id.'/review', ['version' => $leaf->version, 'decision' => 'approved'])->assertOk();
        foreach ([$this->next, $this->third] as $person) {
            $this->actingAs($person)->getJson('/api/allocations/people/'.$person->id.'/2027')->assertOk()
                ->assertJsonCount(1, 'live_score.entries')->assertJsonPath('live_score.confirmed_completion', 49)
                ->assertJsonPath('live_score.entries.0.kind', 'Linked KPA · delegated KPI');
        }
        $this->actingAs($this->manager)->getJson('/api/allocations/people/'.$this->manager->id.'/2027')->assertOk()
            ->assertJsonCount(2, 'live_score.entries')->assertJsonPath('live_score.confirmed_completion', 34.3);
        $this->actingAs($this->hof)->getJson('/api/hof/workspace')->assertOk()->assertJsonPath('kpis.0.confirmed_progress', 49);
        $md = User::factory()->create(['system_role' => 'md', 'is_active' => true]);
        $this->actingAs($md)->getJson('/api/md/workspace?year=2027')->assertOk()->assertJsonPath('activities.0.approved', 70);
        $this->assertDatabaseCount('kpi_delegations', 2);
        $this->assertDatabaseCount('kpi_handoffs', 2);
        $this->assertDatabaseCount('kpi_handoff_activities', 4);
        $this->assertDatabaseHas('kpi_handoffs', ['id' => $second, 'parent_id' => $first]);
        $this->assertDatabaseHas('admin_activity', ['action' => 'kpi.forwarded', 'actor_id' => $this->next->id]);
        DB::table('department_memberships')->where('user_id', $this->third->id)->where('department_id', $this->department)->update(['responsibility' => 'employee']);
        $this->actingAs($this->next)->getJson('/api/delivery/workspace')->assertOk()->assertJsonPath('delegated_kpis.0.valid', false)->assertJsonPath('delegated_kpis.0.confirmed_progress', 0);
        $this->actingAs($this->manager)->getJson('/api/allocations/people/'.$this->manager->id.'/2027')->assertOk()->assertJsonPath('live_score.confirmed_completion', 0);
        $this->actingAs($this->hof)->getJson('/api/hof/workspace')->assertOk()->assertJsonPath('kpis.0.confirmed_progress', 0);
    }

    public function test_only_received_kpis_and_direct_reporting_managers_can_be_selected(): void
    {
        $body = ['source_kind' => 'root', 'source_id' => $this->delegation, 'version' => 1, 'assignee_id' => $this->next->id];
        $this->actingAs($this->next)->postJson('/api/delivery/kpi-handoffs', $body)->assertForbidden();
        foreach ([$this->manager->id, $this->third->id, $this->worker->id] as $invalid) {
            $this->actingAs($this->manager)->postJson('/api/delivery/kpi-handoffs', [...$body, 'assignee_id' => $invalid])->assertUnprocessable();
        }
        $this->postJson('/api/delivery/kpi-handoffs', [...$body, 'version' => 5])->assertConflict();
        $this->assertDatabaseCount('kpi_handoffs', 0);
        $this->assertDatabaseCount('delivery_tasks', 0);
        $this->forward($this->manager, $this->next, 'root', $this->delegation);
        $this->actingAs($this->manager)->postJson('/api/delivery/kpi-handoffs', $body)->assertConflict();
    }

    public function test_existing_activity_work_is_not_replaced_and_the_whole_operation_is_atomic(): void
    {
        $assignment = DB::table('work_assignments')->where('activity_code', 'DOCUMENT')->first();
        $this->actingAs($this->manager)->putJson('/api/delivery/assignments/'.$assignment->id.'/tasks', ['version' => $assignment->version,
            'tasks' => [['title' => 'Retained documentation', 'assignee_id' => $this->manager->id, 'weight' => 1]]])->assertOk();
        $this->postJson('/api/delivery/kpi-handoffs', ['source_kind' => 'root', 'source_id' => $this->delegation, 'version' => 1, 'assignee_id' => $this->next->id])->assertConflict();
        $this->assertDatabaseCount('kpi_handoffs', 0);
        $this->assertDatabaseCount('delivery_tasks', 1);
        $this->assertDatabaseHas('delivery_tasks', ['title' => 'Retained documentation', 'is_active' => true]);
    }

    public function test_an_explicitly_empty_handoff_deadline_is_preserved(): void
    {
        $this->actingAs($this->hof)->postJson('/api/hof/kpi-assignments', ['plan_id' => $this->plan, 'kpi_code' => 'NETWORK', 'source_revision' => 2,
            'version' => 1, 'assignee_id' => $this->manager->id, 'due_date' => '2027-12-01'])->assertOk();
        $this->actingAs($this->manager)->postJson('/api/delivery/kpi-handoffs', ['source_kind' => 'root', 'source_id' => $this->delegation,
            'version' => 2, 'assignee_id' => $this->next->id, 'due_date' => null])->assertOk();
        $this->assertSame(2, DB::table('delivery_tasks')->where('assignee_id', $this->next->id)->whereNull('due_date')->count());
        $this->assertSame(2, DB::table('work_assignments')->where('delegation_id', $this->delegation)->whereDate('due_date', '2027-12-01')->count());
    }

    public function test_individual_activity_overrides_must_be_reviewed_before_forwarding_full_kpi(): void
    {
        $assignment = DB::table('work_assignments')->where('activity_code', 'INSTALL')->first();
        $this->actingAs($this->hof)->postJson('/api/hof/assignments', ['plan_id' => $this->plan, 'activity_code' => 'INSTALL', 'source_revision' => 2,
            'version' => $assignment->version, 'assignee_id' => $this->manager->id])->assertOk();
        $this->actingAs($this->manager)->postJson('/api/delivery/kpi-handoffs', ['source_kind' => 'root', 'source_id' => $this->delegation, 'version' => 1, 'assignee_id' => $this->next->id])->assertConflict();
        $this->assertDatabaseCount('kpi_handoffs', 0);
    }

    public function test_forwarded_activity_ownership_cannot_be_changed_through_generic_task_endpoint(): void
    {
        $this->forward($this->manager, $this->next, 'root', $this->delegation);
        $assignment = DB::table('work_assignments')->where('activity_code', 'INSTALL')->first();
        $this->actingAs($this->manager)->putJson('/api/delivery/assignments/'.$assignment->id.'/tasks', ['version' => $assignment->version,
            'tasks' => [['title' => 'Bypass handoff', 'assignee_id' => $this->manager->id, 'weight' => 1]]])->assertConflict();
        DB::table('reporting_relationships')->where('user_id', $this->next->id)->delete();
        $this->actingAs($this->next)->getJson('/api/delivery/workspace')->assertOk()->assertJsonPath('delegated_kpis.0.valid', false);
        $this->getJson('/api/allocations/people/'.$this->next->id.'/2027')->assertOk()->assertJsonPath('live_score.current', false);
    }

    public function test_previous_generation_handoffs_are_not_counted_after_hof_reassignment(): void
    {
        $this->forward($this->manager, $this->next, 'root', $this->delegation);
        $this->actingAs($this->hof)->postJson('/api/hof/kpi-assignments', ['plan_id' => $this->plan, 'kpi_code' => 'NETWORK', 'source_revision' => 2,
            'version' => 1, 'assignee_id' => $this->hof->id])->assertOk();
        $this->actingAs($this->next)->getJson('/api/delivery/workspace')->assertOk()->assertJsonCount(0, 'delegated_kpis')->assertJsonCount(0, 'managed');
    }

    public function test_plan_deletion_removes_the_complete_handoff_chain_with_its_tasks(): void
    {
        $first = $this->forward($this->manager, $this->next, 'root', $this->delegation);
        $this->forward($this->next, $this->third, 'handoff', $first);
        $preview = $this->actingAs($this->editor)->getJson('/api/planning/plans/'.$this->plan.'/deletion')->assertOk()->json();
        $this->deleteJson('/api/planning/plans/'.$this->plan, ['revision' => 2, 'deletion_token' => $preview['deletion_token']])->assertOk();
        $this->assertDatabaseCount('kpi_handoffs', 0);
        $this->assertDatabaseCount('kpi_handoff_activities', 0);
        $this->assertDatabaseCount('delivery_tasks', 0);
    }
}
