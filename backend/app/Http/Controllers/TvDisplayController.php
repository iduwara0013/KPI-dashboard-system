<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TvDisplayController extends Controller
{
    public function workspace(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->is_active, 403);
        $md = $user->system_role === 'md';
        $memberships = DB::table('department_memberships')->where('user_id', $user->id)->whereIn('responsibility', ['hof', 'manager'])->get();
        $departments = DB::table('departments')->where('is_active', true)
            ->when(! $md, fn ($query) => $query->whereIn('id', $memberships->pluck('department_id')))
            ->orderBy('name')->get(['id', 'name', 'code']);
        abort_unless($md || ($user->system_role === 'member' && $departments->isNotEmpty()), 403, 'TV display is available to MD, HOF and manager accounts.');
        $data = $request->validate(['year' => ['nullable', 'integer', 'between:2000,2100'], 'department' => ['nullable', 'integer']]);
        $year = (int) ($data['year'] ?? now('Asia/Colombo')->year);
        if (isset($data['department'])) {
            abort_unless($departments->contains('id', $data['department']), 403);
        }
        $ids = $departments->pluck('id')->all();
        $hofIds = array_values(array_intersect($ids, $memberships->where('responsibility', 'hof')->pluck('department_id')->all()));
        $managerIds = array_values(array_intersect($ids, $memberships->where('responsibility', 'manager')->pluck('department_id')->all()));
        $rows = collect(app(MdDashboardController::class)->activities($year, $md ? null : $user->id))
            ->filter(fn ($row) => in_array($row['department_id'], $md ? $ids : $hofIds))
            ->map(fn ($row) => collect($row)->only(['key', 'department_id', 'kpa', 'kpi', 'activity', 'weight', 'timeliness', 'approved', 'reported', 'due_date', 'overdue', 'pending', 'needs_review'])->all() + ['assigned' => $row['assignee'] !== null])->values();
        $managerAssignments = $this->managerAssignments($user->id, $managerIds);
        $managerWork = $this->managerWork($user->id, $managerAssignments->where('year', $year));
        $rows = $rows->merge($managerWork['rows'])->filter(fn ($row) => ! isset($data['department']) || $row['department_id'] === (int) $data['department'])->values();
        $chart = app(OrganizationChartController::class)->show($request)->getData(true);
        $managerTeam = [];
        foreach ($managerIds as $department) {
            $descendants = [$user->id];
            do {
                $count = count($descendants);
                foreach ($chart['links'] as $link) {
                    if ((int) $link['department_id'] === (int) $department && in_array($link['manager_id'], $descendants)) {
                        $descendants[] = $link['user_id'];
                    }
                }
                $descendants = array_unique($descendants);
            } while (count($descendants) !== $count);
            $managerTeam[$department] = $descendants;
        }
        $members = collect($chart['people'])->filter(fn ($person) => in_array($person['department_id'], $ids)
            && (! isset($data['department']) || (int) $person['department_id'] === (int) $data['department'])
            && ($md || in_array($person['department_id'], $hofIds)
                || in_array($person['id'], $managerTeam[$person['department_id']] ?? [])
                || $person['responsibility'] === 'hof'));
        $byKey = $rows->keyBy('key');
        $planIds = $rows->map(fn ($row) => (int) explode(':', $row['key'], 2)[0])->unique();
        $tasks = DB::table('delivery_tasks as t')->join('work_assignments as a', 'a.id', '=', 't.assignment_id')
            ->whereIn('a.plan_id', $planIds)->whereColumn('t.generation', 'a.delivery_generation')->where('t.is_active', true)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('delivery_tasks as child')
                ->whereColumn('child.parent_task_id', 't.id')->whereColumn('child.generation', 't.generation')->where('child.is_active', true))
            ->select('t.*', 'a.plan_id', 'a.activity_code', 'a.department_id', 'a.due_date as parent_due')->get()
            ->filter(fn ($task) => ($byKey->has($task->plan_id.':'.$task->activity_code)
                    && ! $byKey[$task->plan_id.':'.$task->activity_code]['needs_review'])
                || in_array($task->id, $managerWork['task_ids']));
        $team = $members->map(function ($person) use ($tasks): array {
            $owned = $tasks->filter(fn ($task) => (int) $task->assignee_id === (int) $person['id'] && (int) $task->department_id === (int) $person['department_id']);

            return [...$person, 'assigned_tasks' => $owned->count(), 'approved_tasks' => $owned->where('status', 'approved')->count(), 'pending_tasks' => $owned->where('status', 'submitted')->count(),
                'overdue_tasks' => $owned->filter(fn ($task) => $task->status !== 'approved' && ($task->due_date ?: $task->parent_due) && substr((string) ($task->due_date ?: $task->parent_due), 0, 10) < now('Asia/Colombo')->toDateString())->count()];
        })->values();

        return response()->json([
            'year' => $year, 'departments' => $departments, 'activities' => $rows, 'as_of' => now()->toIso8601String(),
            'scope' => $md ? 'Published department work' : ($managerIds === [] ? 'Your published HOF responsibilities'
                : ($hofIds === [] ? 'Your assigned work and reporting team' : 'Your HOF responsibilities and assigned team work')),
            'team' => $team,
            'years' => DB::table('performance_plans')->when(! $md, fn ($query) => $query->where(
                fn ($scope) => $scope->where('owner_id', $user->id)->orWhereIn('id', $managerAssignments->pluck('plan_id'))
            ))
                ->whereNotNull('published_revision')->distinct()->orderByDesc('year')->pluck('year'),
        ]);
    }

    private function managerAssignments(int $user, array $departments): Collection
    {
        return DB::table('work_assignments as a')->join('performance_plans as p', 'p.id', '=', 'a.plan_id')
            ->whereIn('a.department_id', $departments)->whereNotNull('p.published_revision')
            ->where(fn ($query) => $query->where('a.assignee_id', $user)->orWhereExists(
                fn ($tasks) => $tasks->selectRaw('1')->from('delivery_tasks as t')
                    ->whereColumn('t.assignment_id', 'a.id')->whereColumn('t.generation', 'a.delivery_generation')
                    ->where('t.assignee_id', $user)->where('t.is_active', true)
            ))->orderBy('a.id')->get(['a.*', 'p.year']);
    }

    private function managerWork(int $user, Collection $assignments): array
    {
        $rows = [];
        $taskIds = [];
        foreach ($assignments as $assignment) {
            $context = app(DeliveryController::class)->context($assignment);
            $activity = $context['activity'];
            if (! $activity) {
                continue;
            }
            $tasks = DB::table('delivery_tasks')->where('assignment_id', $assignment->id)
                ->where('generation', $assignment->delivery_generation)->where('is_active', true)->orderBy('id')->get()->keyBy('id');
            $branches = (int) $assignment->assignee_id === $user ? collect([null]) : $tasks->where('assignee_id', $user);
            foreach ($branches as $branch) {
                $weight = $activity['personal_weight'];
                $ancestor = $branch;
                $duplicate = false;
                while ($ancestor) {
                    $weight *= (float) $ancestor->weight;
                    $parent = $ancestor->parent_task_id ? $tasks->get($ancestor->parent_task_id) : null;
                    if ($parent && ((int) $parent->id >= (int) $ancestor->id || (int) $parent->assignee_id === $user)) {
                        $duplicate = true;
                        break;
                    }
                    $ancestor = $parent;
                }
                if ($duplicate) {
                    continue;
                }
                $branchIds = $branch ? [$branch->id] : $tasks->keys()->all();
                foreach ($tasks as $task) {
                    if (in_array($task->parent_task_id, $branchIds)) {
                        $branchIds[] = $task->id;
                    }
                }
                $scoped = $tasks->whereIn('id', $branchIds);
                $ready = $branch !== null || ($scoped->isNotEmpty() && abs($scoped->whereNull('parent_task_id')->sum('weight') - 1) < 0.00005);
                $confirmed = $context['valid'] && $ready ? (float) ($branch?->confirmed_progress ?? $assignment->confirmed_progress) : 0;
                $due = $branch?->due_date ?? $assignment->due_date ?? $activity['due_date'];
                $due = $due ? substr((string) $due, 0, 10) : null;
                $rows[] = [
                    'key' => $assignment->plan_id.':'.$assignment->activity_code.($branch ? ':task-'.$branch->id : ''),
                    'department_id' => (int) $assignment->department_id, 'kpa' => $activity['kpa_title'],
                    'kpi' => $activity['kpi_title'], 'activity' => $branch?->title ?? $activity['activity'],
                    'weight' => $weight, 'approved' => $confirmed,
                    'timeliness' => $context['valid'] && $ready ? ($branch ? (app(TimelinessController::class)->forAssignment($assignment)['tasks'][$branch->id] ?? null) : app(TimelinessController::class)->forAssignment($assignment)['summary']) : null,
                    'reported' => (float) ($branch?->reported_progress ?? $assignment->reported_progress),
                    'due_date' => $due, 'overdue' => $confirmed < 100 && $due && $due < now('Asia/Colombo')->toDateString(),
                    'pending' => $context['valid'] && $ready ? $scoped->where('status', 'submitted')->count() : 0,
                    'needs_review' => ! $context['valid'] || ($scoped->isNotEmpty() && ! $ready), 'assigned' => true,
                ];
                if ($context['valid'] && $ready) {
                    $taskIds = [...$taskIds, ...$branchIds];
                }
            }
        }

        return ['rows' => $rows, 'task_ids' => array_unique($taskIds)];
    }
}
