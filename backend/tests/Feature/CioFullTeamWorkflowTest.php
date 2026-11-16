<?php

namespace Tests\Feature;

use App\Http\Controllers\PersonalAllocationController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CioFullTeamWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private array $people = [];

    private User $editor;

    private function divide(int $assignment, array $tasks, ?int $branch = null): void
    {
        $record = DB::table($branch ? 'delivery_tasks' : 'work_assignments')->find($branch ?? $assignment);
        $payload = array_map(fn ($task) => ['title' => $task['title'], 'assignee_id' => $this->people[$task['epf']]->id,
            'weight' => $task['weight'], 'due_date' => '2027-12-10'], $tasks);
        $path = $branch ? 'tasks/'.$branch : 'assignments/'.$assignment;
        $this->actingAs($this->editor)->putJson('/api/planning/work/'.$path.'/tasks', ['version' => $record->version, 'tasks' => $payload])->assertOk();
        foreach ($tasks as $task) {
            if (empty($task['children'])) {
                continue;
            }
            $child = DB::table('delivery_tasks')->where('assignment_id', $assignment)->where('is_active', true)
                ->where('parent_task_id', $branch)->where('title', $task['title'])->first();
            $this->divide($assignment, $task['children'], (int) $child->id);
        }
    }

    private function complete(int $id): void
    {
        $task = DB::table('delivery_tasks')->find($id);
        if ($task->status === 'approved') {
            return;
        }
        $assignee = User::findOrFail($task->assignee_id);
        $this->actingAs($assignee)->postJson('/api/delivery/tasks/'.$id.'/submit', ['version' => $task->version,
            'evidence' => 'Completed agreed deliverable; verification evidence is available.'])->assertOk();
        $submission = DB::table('task_submissions')->where('task_id', $id)->orderByDesc('id')->first();
        if ($submission->decision === 'pending') {
            $reviewer = User::findOrFail($task->reviewer_id);
            $version = DB::table('delivery_tasks')->where('id', $id)->value('version');
            $this->actingAs($reviewer)->postJson('/api/delivery/submissions/'.$submission->id.'/review', ['version' => $version, 'decision' => 'approved'])->assertOk();
        }
    }

    private function score(string $epf): float
    {
        return app(PersonalAllocationController::class)->liveScore($this->people[$epf]->id, 2027)['confirmed_completion'];
    }

    public function test_every_connected_person_receives_work_and_all_branches_roll_up_without_double_counting(): void
    {
        $this->seed();
        // Bulk operations exercise scoring and authorization; rate limits are tested separately.
        $this->withoutMiddleware(ThrottleRequests::class);
        $team = json_decode(file_get_contents(base_path('tests/Fixtures/cio-team-workflow.json')), true);
        $document = json_decode(file_get_contents(base_path('tests/Fixtures/cio-workflow-example.json')), true);
        $department = (int) DB::table('departments')->where('code', 'IT')->value('id');
        foreach ($team['people'] as $person) {
            $user = User::factory()->create(['name' => $person['name'], 'employee_number' => $person['epf'], 'system_role' => 'member', 'is_active' => true]);
            $this->people[$person['epf']] = $user;
            DB::table('department_memberships')->insert(['user_id' => $user->id, 'department_id' => $department, 'responsibility' => $person['role'], 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ($team['people'] as $person) {
            if ($person['reports_to']) {
                DB::table('reporting_relationships')->insert(['user_id' => $this->people[$person['epf']]->id, 'manager_id' => $this->people[$person['reports_to']]->id,
                    'department_id' => $department, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $this->editor = User::where('employee_number', 'EMP011')->firstOrFail();
        $this->grantPlanningDepartments($this->editor, [$department]);
        $document['owner_id'] = $this->people['14673']->id;
        $document['code'] = 'CIO-ALL-TEAM-2027';
        foreach ($document['kpas'] as &$kpa) {
            $kpa['department_id'] = $department;
        }
        unset($kpa);
        $plan = $this->actingAs($this->editor)->postJson('/api/planning/plans', $document)->assertCreated()->json('id');
        $this->postJson('/api/planning/plans/'.$plan.'/publish', ['revision' => 1])->assertOk();
        foreach ($team['kpi_owners'] as $code => $epf) {
            $this->actingAs($this->people['14673'])->postJson('/api/hof/kpi-assignments', ['plan_id' => $plan, 'kpi_code' => $code,
                'source_revision' => 2, 'version' => 0, 'assignee_id' => $this->people[$epf]->id])->assertOk();
        }
        $assignments = DB::table('work_assignments')->where('plan_id', $plan)->get();
        $this->assertCount(42, $assignments);
        foreach ($assignments as $assignment) {
            $special = $team['specials'][$assignment->activity_code] ?? null;
            if ($special) {
                $this->divide((int) $assignment->id, $special['tasks']);
            } elseif ((int) $assignment->assignee_id !== $this->people['14673']->id) {
                $epf = User::findOrFail($assignment->assignee_id)->employee_number;
                $this->divide((int) $assignment->id, [['epf' => $epf, 'title' => $assignment->activity_code.' retained delivery', 'weight' => 1]]);
            }
        }
        $tasks = DB::table('delivery_tasks')->whereIn('assignment_id', $assignments->pluck('id'))->where('is_active', true)->get();
        $this->assertCount(62, $tasks);
        $leaves = $tasks->reject(fn ($task) => $tasks->contains('parent_task_id', $task->id));
        $this->assertCount(58, $leaves);
        $this->assertCount(20, $leaves->pluck('assignee_id')->unique());
        $this->assertEquals(0, $this->score('14673'));
        foreach (['17536', '14994', '17611'] as $epf) {
            $leaf = $leaves->firstWhere('assignee_id', $this->people[$epf]->id);
            $this->complete((int) $leaf->id);
            $this->assertEquals(100, $this->score($epf));
        }
        foreach (['14673' => 7.876, '11434' => 2.94, '16502' => 16, '16503' => 3, '16917' => 80, '14631' => 56] as $epf => $score) {
            $this->assertEqualsWithDelta($score, $this->score((string) $epf), .0001);
        }
        $md = User::factory()->create(['system_role' => 'md', 'is_active' => true]);
        $executive = $this->actingAs($md)->getJson('/api/md/workspace?year=2027')->assertOk()->json('activities');
        $this->assertCount(42, $executive);
        $this->assertEqualsWithDelta(7.876, array_sum(array_map(fn ($row) => $row['weight'] * $row['approved'], $executive)), .0001);
        $specialIds = $assignments->whereIn('activity_code', array_keys($team['specials']))->pluck('id');
        foreach ($leaves->whereIn('assignment_id', $specialIds) as $leaf) {
            $this->complete((int) $leaf->id);
        }
        foreach (['14673' => 15.5, '11434' => 15, '16502' => 20, '16503' => 15] as $epf => $score) {
            $this->assertEqualsWithDelta($score, $this->score((string) $epf), .0001);
        }
        foreach ($leaves as $leaf) {
            $this->complete((int) $leaf->id);
        }
        foreach ($this->people as $epf => $user) {
            $this->assertEquals(100, $this->score((string) $epf), 'Final completion for '.$epf);
        }
        $this->assertEquals(42, DB::table('work_assignments')->where('plan_id', $plan)->where('confirmed_progress', 100)->count());
        $this->actingAs($this->editor)->getJson('/api/planning/work')->assertOk()->assertJsonCount(10, 'kpis')->assertJsonCount(42, 'activities');
        $this->assertEquals(58, DB::table('task_submissions')->whereIn('task_id', $leaves->pluck('id'))->where('decision', 'approved')->count());
    }
}
