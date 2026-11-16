<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RequirePlanningAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlanWorkController extends Controller
{
    public function workspace(Request $request): JsonResponse
    {
        abort_unless(RequirePlanningAccess::canEdit($request->user()), 403);
        $departments = RequirePlanningAccess::departmentIds($request->user());
        $owners = RequirePlanningAccess::ownerIds($request->user());
        $people = DB::table('department_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->whereIn('m.department_id', $departments)->where('u.is_active', true)->where('u.system_role', 'member')
            ->get(['u.id', 'u.name', 'u.employee_number', 'u.designation', 'm.department_id', 'm.responsibility']);
        $reporting = DB::table('reporting_relationships')->whereIn('department_id', $departments)->get(['department_id', 'manager_id', 'user_id']);
        $rows = [];
        $kpis = [];
        $tasks = [];
        foreach (DB::table('performance_plans')->whereIn('owner_id', $owners)->whereNotNull('published_revision')->get() as $plan) {
            $canEdit = RequirePlanningAccess::canManagePlan($request->user(), (int) $plan->id);
            $planRows = array_values(array_filter(app(KpiAssignmentController::class)->rowsForPlan($plan), fn ($row) => $departments->contains((int) $row['department_id'])));
            $delegations = DB::table('kpi_delegations')->where('plan_id', $plan->id)->whereIn('department_id', $departments)->get();
            foreach ($planRows as $row) {
                $hof = $people->contains(fn ($person) => (int) $person->id === (int) $plan->owner_id && (int) $person->department_id === (int) $row['department_id'] && $person->responsibility === 'hof');
                $assignment = $row['assignment'];
                $delegation = $delegations->firstWhere('kpi_code', $row['kpi_code']);
                $rows[] = [...$row, 'owner_id' => (int) $plan->owner_id, 'delegation' => $delegation, 'can_edit' => $canEdit && $hof];
                if (! $assignment) {
                    continue;
                }
                $current = DB::table('delivery_tasks')->where('assignment_id', $assignment->id)->where('generation', $assignment->delivery_generation)->where('is_active', true)->orderBy('id')->get();
                $valid = app(DeliveryController::class)->context($assignment)['valid'];
                $protected = DB::table('work_replacements')->whereIn('task_id', $current->pluck('id'))->pluck('task_id');
                $started = DB::table('task_submissions')->whereIn('task_id', $current->pluck('id'))->pluck('task_id');
                $rootTasks = $current->whereNull('parent_task_id')->values();
                $parent = ['id' => (int) $assignment->id, 'assignment_id' => (int) $assignment->id, 'version' => (int) $assignment->version, 'activity' => $row['activity'], 'department_id' => $row['department_id'],
                    'responsible_id' => (int) $assignment->assignee_id, 'due_date' => $assignment->due_date ? substr((string) $assignment->due_date, 0, 10) : null,
                    'tasks' => $rootTasks, 'can_edit' => $canEdit && $valid,
                    'breakdown_locked' => $current->contains(fn ($task) => (float) $task->reported_progress > 0) || $started->isNotEmpty() || $protected->isNotEmpty() || $current->whereNotNull('parent_task_id')->isNotEmpty()];
                $tasks[] = [...$parent, 'task_branch' => false];
                foreach ($current as $task) {
                    $children = $current->where('parent_task_id', $task->id)->values();
                    $contribution = (float) $task->weight * $row['personal_weight'];
                    $ancestor = $task;
                    $ancestors = [(int) $task->id];
                    while ($ancestor->parent_task_id !== null) {
                        $ancestor = $current->firstWhere('id', $ancestor->parent_task_id);
                        if (! $ancestor || in_array((int) $ancestor->id, $ancestors, true)) {
                            break;
                        }
                        $ancestors[] = (int) $ancestor->id;
                        $contribution *= (float) $ancestor->weight;
                    }
                    $descendants = [$task->id];
                    for ($index = 0; $index < count($descendants); $index++) {
                        foreach ($current->where('parent_task_id', $descendants[$index]) as $child) {
                            if (! in_array($child->id, $descendants)) {
                                $descendants[] = $child->id;
                            }
                        }
                    }
                    $locked = $current->whereIn('id', $descendants)->contains(fn ($item) => (float) $item->reported_progress > 0)
                        || $started->intersect($descendants)->isNotEmpty() || $protected->intersect($descendants)->isNotEmpty()
                        || $current->whereIn('parent_task_id', $children->pluck('id'))->isNotEmpty();
                    $tasks[] = [...$parent, 'id' => (int) $task->id, 'task_branch' => true, 'activity' => $task->title, 'version' => (int) $task->version,
                        'responsible_id' => (int) $task->assignee_id, 'reviewer_id' => (int) $task->reviewer_id, 'parent_task_id' => $task->parent_task_id,
                        'weight' => (float) $task->weight, 'personal_weight' => round($contribution, 8), 'confirmed_progress' => $valid ? app(WorkChangeController::class)->effectiveProgress($task) : 0, 'status' => app(WorkChangeController::class)->displayStatus($task), 'tasks' => $children, 'breakdown_locked' => $locked,
                        'due_date' => $task->due_date ? substr((string) $task->due_date, 0, 10) : null];
                }
            }
            foreach (app(KpiAssignmentController::class)->summaries($planRows, $delegations) as $row) {
                $hof = $people->contains(fn ($person) => (int) $person->id === (int) $plan->owner_id && (int) $person->department_id === (int) $row['department_id'] && $person->responsibility === 'hof');
                $kpis[] = [...$row, 'owner_id' => (int) $plan->owner_id, 'can_edit' => $canEdit && $hof];
            }
        }

        return response()->json(['activities' => $rows, 'kpis' => $kpis, 'breakdowns' => $tasks, 'people' => $people, 'reporting' => $reporting,
            'departments' => DB::table('departments')->whereIn('id', $departments)->get(['id', 'name', 'code'])]);
    }
}
