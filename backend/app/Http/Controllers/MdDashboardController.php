<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MdDashboardController extends Controller
{
    public function workspace(Request $request): JsonResponse
    {
        abort_unless($request->user()?->is_active && $request->user()->system_role === 'md', 403, 'An active MD account is required.');
        $data = $request->validate(['year' => ['nullable', 'integer', 'between:2000,2100']]);
        $year = (int) ($data['year'] ?? now()->year);
        $rows = $this->activities($year);

        return response()->json([
            'year' => $year, 'as_of' => now()->toIso8601String(), 'activities' => $rows,
            'years' => DB::table('performance_plans')->whereNotNull('published_revision')->distinct()->orderByDesc('year')->pluck('year'),
            'departments' => DB::table('departments')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'hofs' => DB::table('department_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')->join('departments as d', 'd.id', '=', 'm.department_id')->where('m.responsibility', 'hof')->where('u.is_active', true)->where('u.system_role', 'member')->where('d.is_active', true)->select('u.id', 'u.name', 'u.designation', 'm.department_id')->orderBy('u.name')->get(),
        ]);
    }

    public function activities(int $year, ?int $owner = null): array
    {
        $people = DB::table('users')->get(['id', 'name', 'designation'])->keyBy('id');
        $rows = [];
        foreach (DB::table('performance_plans')->where('year', $year)->when($owner, fn ($query) => $query->where('owner_id', $owner))->whereNotNull('published_revision')->get() as $plan) {
            foreach (app(KpiAssignmentController::class)->rowsForPlan($plan) as $row) {
                $assignment = $row['assignment'];
                $valid = $assignment && app(DeliveryController::class)->context($assignment)['valid'];
                $tasks = $assignment ? DB::table('delivery_tasks')->where('assignment_id', $assignment->id)->where('generation', $assignment->delivery_generation)->where('is_active', true)->get() : collect();
                $ready = $tasks->isNotEmpty() && abs($tasks->whereNull('parent_task_id')->sum('weight') - 1) < 0.00005
                    && ! $tasks->contains(fn ($task) => ! app(DeliveryController::class)->taskEligible($task, $assignment));
                $approved = $valid && $ready ? (float) $assignment->confirmed_progress : 0;
                $due = $assignment?->due_date ? substr((string) $assignment->due_date, 0, 10) : $row['due_date'];
                $pending = $valid && $ready ? $tasks->where('status', 'submitted')->count() : 0;
                $rows[] = [
                    'key' => $plan->id.':'.$row['activity_code'], 'plan_code' => $plan->code,
                    'owner_id' => (int) $plan->owner_id, 'owner_name' => $people[$plan->owner_id]->name ?? 'Previous HOF',
                    'department_id' => $row['department_id'], 'kpa' => $row['kpa_title'], 'kpi' => $row['kpi_title'],
                    'activity' => $row['activity'], 'code' => $row['activity_code'], 'weight' => $row['personal_weight'],
                    'assignee' => $assignment ? ($people[$assignment->assignee_id]->name ?? 'Previous owner') : null,
                    'approved' => $approved, 'reported' => $assignment ? (float) $assignment->reported_progress : 0,
                    'due_date' => $due, 'overdue' => $approved < 100 && $due && $due < now()->toDateString(),
                    'pending' => $pending, 'needs_review' => (bool) ($assignment && (! $valid || ($tasks->isNotEmpty() && ! $ready))),
                    'tasks' => $tasks->map(fn ($task) => [
                        'id' => $task->id, 'parent_task_id' => $task->parent_task_id, 'title' => $task->title,
                        'owner' => $people[$task->assignee_id]->name ?? 'Previous owner',
                        'reviewer' => app(DeliveryController::class)->selfConfirmable($task, $assignment) ? 'HOF confirmation' : ($people[$task->reviewer_id]->name ?? 'Previous reviewer'),
                        'weight' => (float) $task->weight, 'activity_weight' => $this->activityWeight($task, $tasks->keyBy('id')), 'confirmed_progress' => (float) $task->confirmed_progress,
                        'status' => $task->status, 'due_date' => $task->due_date ? substr((string) $task->due_date, 0, 10) : null,
                    ])->all(),
                ];
            }
        }

        return $rows;
    }

    private function activityWeight(object $task, Collection $tasks): float
    {
        $weight = (float) $task->weight;
        while ($task->parent_task_id !== null) {
            $parent = $tasks->get($task->parent_task_id);
            if (! $parent || (int) $parent->id >= (int) $task->id) {
                return 0;
            }
            $weight *= (float) $parent->weight;
            $task = $parent;
        }

        return round($weight, 8);
    }
}
