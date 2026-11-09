<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TvDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_display_requires_login_and_restricts_department_access(): void
    {
        $this->seed();
        $this->getJson('/api/display/workspace')->assertUnauthorized();
        foreach (['EMP007', 'EMP011', 'SYS001'] as $number) {
            $this->actingAs(User::where('employee_number', $number)->firstOrFail())->getJson('/api/display/workspace')->assertForbidden();
        }
        $manager = User::where('employee_number', 'EMP004')->firstOrFail();
        $this->actingAs($manager)->getJson('/api/display/workspace?year=2026')->assertOk()
            ->assertJsonCount(1, 'departments')->assertJsonCount(0, 'activities')
            ->assertJsonPath('scope', 'Your assigned work and reporting team');
        $hof = User::where('employee_number', 'EMP001')->firstOrFail();
        $response = $this->actingAs($hof)->getJson('/api/display/workspace?year=2026')->assertOk()->assertJsonCount(2, 'departments')->assertJsonCount(0, 'activities');
        $this->assertSame('Your published HOF responsibilities', $response->json('scope'));
        $this->getJson('/api/display/workspace?department=999')->assertForbidden();
        $md = User::factory()->create(['system_role' => 'md', 'is_active' => true]);
        $this->actingAs($md)->getJson('/api/display/workspace?year=2026')->assertOk()->assertJsonCount(4, 'departments');
        $this->getJson('/api/display/workspace?year=1900')->assertUnprocessable();
        $md->update(['is_active' => false]);
        $this->actingAs($md)->getJson('/api/display/workspace')->assertForbidden();
    }

    private function delegatedWork(): array
    {
        $this->seed();
        $hof = User::where('employee_number', 'EMP001')->firstOrFail();
        $manager = User::where('employee_number', 'EMP004')->firstOrFail();
        $employee = User::where('employee_number', 'EMP007')->firstOrFail();
        $editor = User::where('employee_number', 'EMP011')->firstOrFail();
        $department = (int) DB::table('departments')->where('code', 'IT')->value('id');
        $peer = User::factory()->create(['system_role' => 'member', 'is_active' => true]);
        $lead = User::factory()->create(['system_role' => 'member', 'is_active' => true]);
        $junior = User::factory()->create(['system_role' => 'member', 'is_active' => true]);
        foreach ([[$peer, 'manager', $hof], [$lead, 'manager', $manager], [$junior, 'employee', $lead]] as [$person, $role, $supervisor]) {
            DB::table('department_memberships')->insert(['user_id' => $person->id, 'department_id' => $department, 'responsibility' => $role]);
            DB::table('reporting_relationships')->insert(['user_id' => $person->id, 'department_id' => $department, 'manager_id' => $supervisor->id]);
        }
        $plan = $this->actingAs($editor)->postJson('/api/planning/plans', [
            'code' => 'TV-MANAGER-2026', 'title' => 'Manager TV test', 'year' => 2026, 'owner_id' => $hof->id,
            'kpas' => [['code' => 'IT', 'title' => 'IT delivery', 'department_id' => $department, 'weight' => 1,
                'kpis' => [['code' => 'PROJECT', 'title' => 'Project completion', 'weight' => 1,
                    'target_value' => 1, 'target_unit' => 'count', 'frequency' => 'On completion', 'direction' => 'higher',
                    'activities' => [
                        ['code' => 'A', 'title' => 'Manager activity', 'weight' => 0.7],
                        ['code' => 'B', 'title' => 'Other manager activity', 'weight' => 0.3],
                    ]]]]],
        ])->assertCreated()->json('id');
        $this->postJson('/api/planning/plans/'.$plan.'/publish', ['revision' => 1])->assertOk();
        foreach ([['A', $manager], ['B', $peer]] as [$code, $person]) {
            $this->actingAs($hof)->postJson('/api/hof/assignments', [
                'plan_id' => $plan, 'activity_code' => $code, 'source_revision' => 2, 'version' => 0, 'assignee_id' => $person->id,
            ])->assertOk();
        }
        $assignment = (int) DB::table('work_assignments')->where('plan_id', $plan)->where('activity_code', 'A')->value('id');
        $this->actingAs($manager)->putJson('/api/delivery/assignments/'.$assignment.'/tasks', [
            'version' => 1, 'tasks' => [
                ['title' => 'Installation', 'assignee_id' => $employee->id, 'weight' => 0.7],
                ['title' => 'Technical branch', 'assignee_id' => $lead->id, 'weight' => 0.3],
            ],
        ])->assertOk();
        $branch = DB::table('delivery_tasks')->where('assignment_id', $assignment)->where('assignee_id', $lead->id)->first();
        $this->actingAs($lead)->putJson('/api/delivery/tasks/'.$branch->id.'/tasks', [
            'version' => 1, 'tasks' => [
                ['title' => 'Junior work', 'assignee_id' => $junior->id, 'weight' => 0.8],
                ['title' => 'Lead checks', 'assignee_id' => $lead->id, 'weight' => 0.2],
            ],
        ])->assertOk();
        foreach ([[$employee, $manager], [$junior, $lead]] as [$person, $reviewer]) {
            $task = DB::table('delivery_tasks')->where('assignment_id', $assignment)->where('assignee_id', $person->id)->first();
            $this->actingAs($person)->postJson('/api/delivery/tasks/'.$task->id.'/submit', ['version' => 1, 'evidence' => 'Private completed work evidence.'])->assertOk();
            $submission = DB::table('task_submissions')->where('task_id', $task->id)->value('id');
            $this->actingAs($reviewer)->postJson('/api/delivery/submissions/'.$submission.'/review', ['version' => 2, 'decision' => 'approved'])->assertOk();
        }

        return compact('hof', 'manager', 'peer', 'lead', 'junior', 'department', 'plan', 'assignment');
    }

    public function test_manager_display_includes_only_own_work_and_current_reporting_team(): void
    {
        $work = $this->delegatedWork();
        $data = $this->actingAs($work['manager'])->getJson('/api/display/workspace?year=2026')
            ->assertOk()->assertJsonCount(1, 'activities')->assertJsonPath('activities.0.activity', 'Manager activity')
            ->assertJsonPath('activities.0.approved', 94)->assertJsonPath('activities.0.weight', 0.7)
            ->assertJsonMissingPath('activities.0.tasks')->assertJsonMissingPath('activities.0.evidence')
            ->assertJsonPath('years.0', 2026)->json();
        $this->assertNotContains($work['peer']->id, array_column($data['team'], 'id'));
        $this->assertContains($work['junior']->id, array_column($data['team'], 'id'));
        $otherDepartment = DB::table('departments')->where('code', 'FIN')->value('id');
        $this->getJson('/api/display/workspace?department='.$otherDepartment)->assertForbidden();
        $this->actingAs($work['hof'])->postJson('/api/hof/assignments', [
            'plan_id' => $work['plan'], 'activity_code' => 'A', 'source_revision' => 2,
            'version' => DB::table('work_assignments')->where('id', $work['assignment'])->value('version'),
            'assignee_id' => $work['peer']->id,
        ])->assertOk();
        $this->actingAs($work['manager'])->getJson('/api/display/workspace?year=2026')->assertOk()
            ->assertJsonCount(0, 'activities')->assertJsonCount(0, 'years');
    }

    public function test_nested_manager_display_uses_branch_progress_and_excludes_sibling_work(): void
    {
        $work = $this->delegatedWork();
        $data = $this->actingAs($work['lead'])->getJson('/api/display/workspace?year=2026')
            ->assertOk()->assertJsonCount(1, 'activities')->assertJsonPath('activities.0.activity', 'Technical branch')
            ->assertJsonPath('activities.0.approved', 80)->assertJsonMissingPath('activities.0.evidence')->json();
        $this->assertEqualsWithDelta(0.21, $data['activities'][0]['weight'], 0.00001);
        $this->assertNotContains($work['manager']->id, array_column($data['team'], 'id'));
        $this->assertNotContains($work['peer']->id, array_column($data['team'], 'id'));
        $this->assertSame(1, collect($data['team'])->firstWhere('id', $work['junior']->id)['approved_tasks']);
        $work['lead']->update(['is_active' => false]);
        $this->getJson('/api/display/workspace?year=2026')->assertForbidden();
    }
}
