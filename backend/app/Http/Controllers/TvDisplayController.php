<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TvDisplayController extends Controller
{
    public function workspace(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->is_active, 403);
        $md = $user->system_role === 'md';
        $departments = DB::table('departments')->where('is_active', true)->when(! $md, fn ($query) => $query->whereIn('id', DB::table('department_memberships')->where('user_id', $user->id)->where('responsibility', 'hof')->pluck('department_id')))->orderBy('name')->get(['id', 'name', 'code']);
        abort_unless($md || ($user->system_role === 'member' && $departments->isNotEmpty()), 403, 'TV display is available to MD and HOF accounts.');
        $data = $request->validate(['year' => ['nullable', 'integer', 'between:2000,2100'], 'department' => ['nullable', 'integer']]);
        $year = (int) ($data['year'] ?? now('Asia/Colombo')->year);
        if (isset($data['department'])) {
            abort_unless($departments->contains('id', $data['department']), 403);
        }
        $ids = $departments->pluck('id')->all();
        $rows = collect(app(MdDashboardController::class)->activities($year, $md ? null : $user->id))->filter(fn ($row) => in_array($row['department_id'], $ids) && (! isset($data['department']) || $row['department_id'] === (int) $data['department']))->map(fn ($row) => collect($row)->only(['key', 'department_id', 'kpa', 'kpi', 'activity', 'weight', 'approved', 'reported', 'due_date', 'overdue', 'pending', 'needs_review'])->all() + ['assigned' => $row['assignee'] !== null])->values();
        $chart = app(OrganizationChartController::class)->show($request)->getData(true);
        $members = collect($chart['people'])->filter(fn ($person) => in_array($person['department_id'], $ids) && (! isset($data['department']) || $person['department_id'] === (int) $data['department']));
        $byKey = $rows->keyBy('key');
        $planIds = $rows->map(fn ($row) => (int) explode(':', $row['key'], 2)[0])->unique();
        $tasks = DB::table('delivery_tasks as t')->join('work_assignments as a', 'a.id', '=', 't.assignment_id')->whereIn('a.plan_id', $planIds)->whereColumn('t.generation', 'a.delivery_generation')->where('t.is_active', true)->select('t.*', 'a.plan_id', 'a.activity_code', 'a.department_id', 'a.due_date as parent_due')->get()->filter(fn ($task) => $byKey->has($task->plan_id.':'.$task->activity_code) && ! $byKey[$task->plan_id.':'.$task->activity_code]['needs_review']);
        $team = $members->map(function ($person) use ($tasks): array {
            $owned = $tasks->filter(fn ($task) => (int) $task->assignee_id === (int) $person['id'] && (int) $task->department_id === (int) $person['department_id']);

            return [...$person, 'assigned_tasks' => $owned->count(), 'approved_tasks' => $owned->where('status', 'approved')->count(), 'pending_tasks' => $owned->where('status', 'submitted')->count(),
                'overdue_tasks' => $owned->filter(fn ($task) => $task->status !== 'approved' && ($task->due_date ?: $task->parent_due) && substr((string) ($task->due_date ?: $task->parent_due), 0, 10) < now('Asia/Colombo')->toDateString())->count()];
        })->values();

        return response()->json([
            'year' => $year, 'departments' => $departments, 'activities' => $rows, 'as_of' => now()->toIso8601String(),
            'scope' => $md ? 'Published department work' : 'Your published HOF responsibilities',
            'team' => $team,
            'years' => DB::table('performance_plans')->when(! $md, fn ($query) => $query->where('owner_id', $user->id))->whereNotNull('published_revision')->distinct()->orderByDesc('year')->pluck('year'),
        ]);
    }
}
