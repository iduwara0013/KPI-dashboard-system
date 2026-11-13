<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RequireDeliveryAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DeliveryController extends Controller
{
    private function membership(int $user, int $department, string $role): bool
    {
        return DB::table('department_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->join('departments as d', 'd.id', '=', 'm.department_id')
            ->where('m.user_id', $user)->where('m.department_id', $department)->where('m.responsibility', $role)
            ->where('u.is_active', true)->where('u.system_role', 'member')->where('d.is_active', true)->exists();
    }

    private function reports(int $user, int $manager, int $department): bool
    {
        return DB::table('reporting_relationships')->where('user_id', $user)->where('manager_id', $manager)->where('department_id', $department)->exists();
    }

    public function employeeEligible(int $user, object $assignment): bool
    {
        return $user === (int) $assignment->assignee_id || ($this->activeMember($user, (int) $assignment->department_id) && $this->reports($user, (int) $assignment->assignee_id, (int) $assignment->department_id));
    }

    private function activeMember(int $user, int $department): bool
    {
        return DB::table('department_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->join('departments as d', 'd.id', '=', 'm.department_id')
            ->where('m.user_id', $user)->where('m.department_id', $department)->whereIn('m.responsibility', ['hof', 'manager', 'employee'])
            ->where('u.is_active', true)->where('u.system_role', 'member')->where('d.is_active', true)->exists();
    }

    public function taskEligible(object $task, object $assignment): bool
    {
        if (! $task->is_active || (int) $task->generation !== (int) $assignment->delivery_generation) {
            return false;
        }
        if ($task->parent_task_id === null) {
            if ((int) $assignment->owner_id === (int) $assignment->assignee_id && (int) $task->assignee_id === (int) $assignment->owner_id && ! $this->membership((int) $assignment->owner_id, (int) $assignment->department_id, 'hof')) {
                if (! $this->activeMember((int) $task->reviewer_id, (int) $assignment->department_id) || ! $this->reports((int) $assignment->owner_id, (int) $task->reviewer_id, (int) $assignment->department_id)) {
                    return false;
                }
            }

            return $this->employeeEligible((int) $task->assignee_id, $assignment);
        }
        $parent = DB::table('delivery_tasks')->find($task->parent_task_id);

        return $parent && (int) $parent->id < (int) $task->id
            && (int) $parent->assignment_id === (int) $task->assignment_id
            && (int) $task->manager_id === (int) $parent->assignee_id
            && $this->taskEligible($parent, $assignment)
            && ((int) $task->assignee_id === (int) $parent->assignee_id
                || ($this->activeMember((int) $task->assignee_id, (int) $assignment->department_id)
                    && $this->reports((int) $task->assignee_id, (int) $parent->assignee_id, (int) $assignment->department_id)));
    }

    public function selfConfirmable(object $task, object $assignment): bool
    {
        return (int) $task->assignee_id === (int) $assignment->owner_id
            && $this->membership((int) $task->assignee_id, (int) $assignment->department_id, 'hof');
    }

    public function context(object $assignment, ?object $plan = null): array
    {
        $plan ??= DB::table('performance_plans')->find($assignment->plan_id);
        $activity = $plan ? collect(app(HofWorkController::class)->activities($plan))->firstWhere('activity_code', $assignment->activity_code) : null;
        $valid = $activity && (int) $plan->owner_id === (int) $assignment->owner_id
            && ($this->membership((int) $assignment->owner_id, (int) $assignment->department_id, 'hof') || ((int) $assignment->owner_id === (int) $assignment->assignee_id && $this->activeMember((int) $assignment->owner_id, (int) $assignment->department_id)))
            && app(HofWorkController::class)->recipientRole((int) $assignment->owner_id, (int) $assignment->assignee_id, (int) $assignment->department_id) === $assignment->recipient_role
            && $assignment->source_signature === $activity['source_signature'];
        if ($valid) {
            $valid = ! DB::table('delivery_tasks')->where('assignment_id', $assignment->id)
                ->where('generation', $assignment->delivery_generation)->where('is_active', true)->get()
                ->contains(fn ($task) => ! $this->taskEligible($task, $assignment));
        }

        return ['activity' => $activity, 'valid' => (bool) $valid];
    }

    private function currentTasks(object $assignment): Collection
    {
        return DB::table('delivery_tasks')->where('assignment_id', $assignment->id)->where('generation', $assignment->delivery_generation)->where('is_active', true)->whereNull('parent_task_id')->orderBy('id')->get();
    }

    private function allocationReady(object $assignment): bool
    {
        $tasks = $this->currentTasks($assignment);

        return $tasks->isNotEmpty() && abs($tasks->sum('weight') - 1) < 0.00005;
    }

    private function lockAssignment(int $id): array
    {
        $reference = DB::table('work_assignments')->find($id);
        abort_unless($reference, 404);
        $plan = DB::table('performance_plans')->where('id', $reference->plan_id)->lockForUpdate()->first();
        $assignment = DB::table('work_assignments')->where('id', $id)->lockForUpdate()->first();
        abort_unless($this->context($assignment, $plan)['valid'], 409, 'The activity or reporting relationship changed. The HOF must review its assignment.');

        return [$assignment, $plan];
    }

    public function workspace(Request $request): JsonResponse
    {
        $user = $request->user()->id;
        $managed = [];
        $own = [];
        $reviews = [];
        $taskIds = [];
        $candidateIds = DB::table('delivery_tasks')->where('is_active', true)
            ->where(fn ($query) => $query->where('assignee_id', $user)->orWhere('reviewer_id', $user))->pluck('assignment_id');
        $assignments = DB::table('work_assignments')->where(fn ($query) => $query->where('assignee_id', $user)->orWhereIn('id', $candidateIds))->get();
        foreach ($assignments as $assignment) {
            $context = $this->context($assignment);
            $activity = $context['activity'];
            $tasks = $this->currentTasks($assignment);
            $ready = $this->allocationReady($assignment);
            $timeliness = app(TimelinessController::class)->forAssignment($assignment);
            $parent = [
                'id' => (int) $assignment->id, 'version' => (int) $assignment->version, 'generation' => (int) $assignment->delivery_generation,
                'owner_id' => (int) $assignment->owner_id, 'manager_id' => (int) $assignment->assignee_id,
                'department_id' => (int) $assignment->department_id, 'activity' => $activity['activity'] ?? $assignment->activity_code,
                'activity_code' => $assignment->activity_code, 'kpa_title' => $activity['kpa_title'] ?? '', 'kpi_title' => $activity['kpi_title'] ?? '',
                'year' => $activity['year'] ?? null, 'personal_weight' => $activity['personal_weight'] ?? 0,
                'due_date' => $assignment->due_date ? substr((string) $assignment->due_date, 0, 10) : null,
                'timeliness' => $context['valid'] ? $timeliness['summary'] : null,
                'instructions' => $assignment->instructions, 'valid' => $context['valid'], 'allocation_ready' => $ready,
                'confirmed_progress' => $context['valid'] && $ready ? (float) $assignment->confirmed_progress : 0,
                'reported_progress' => (float) $assignment->reported_progress,
                'recipient_role' => app(HofWorkController::class)->recipientRole((int) $assignment->owner_id, (int) $assignment->assignee_id, (int) $assignment->department_id),
                'ownership_mode' => $assignment->ownership_mode,
                'delegation_id' => $assignment->delegation_id,
            ];
            $allTasks = DB::table('delivery_tasks')->where('assignment_id', $assignment->id)
                ->where('generation', $assignment->delivery_generation)->where('is_active', true)->orderBy('id')->get();
            $serialized = $allTasks->map(function ($task) use ($assignment, $allTasks, $timeliness, $user): array {
                $children = $allTasks->where('parent_task_id', $task->id)->values();
                $selfConfirm = $this->selfConfirmable($task, $assignment);

                return [...(array) $task, 'weight' => (float) $task->weight, 'reported_progress' => (float) $task->reported_progress,
                    'confirmed_progress' => (float) $task->confirmed_progress,
                    'timeliness' => $timeliness['tasks'][$task->id] ?? null,
                    'can_extend_deadline' => $children->isEmpty() && $task->status !== 'approved' && (((int) $task->reviewer_id === $user && (int) $task->assignee_id !== $user) || $selfConfirm && (int) $task->assignee_id === $user),
                    'self_confirm' => $selfConfirm, 'reviewer_id' => $selfConfirm ? null : (int) $task->reviewer_id,
                    'children' => $children->map(fn ($child) => (object) [...(array) $child, 'timeliness' => $timeliness['tasks'][$child->id] ?? null]), 'has_children' => $children->isNotEmpty(),
                    'breakdown_locked' => (float) $task->reported_progress > 0
                        || DB::table('task_submissions')->whereIn('task_id', $children->pluck('id')->push($task->id))->exists(),
                    'due_date' => $task->due_date ? substr((string) $task->due_date, 0, 10) : null,
                    'assignee_eligible' => $this->taskEligible($task, $assignment),
                ];
            });
            $hasReports = DB::table('reporting_relationships')->where('manager_id', $user)->where('department_id', $assignment->department_id)->exists();
            if ((int) $assignment->assignee_id === $user && ($this->membership($user, (int) $assignment->department_id, 'manager') || ($context['valid'] && $hasReports))) {
                $locked = $allTasks->contains(fn ($task) => (float) $task->reported_progress > 0)
                    || DB::table('task_submissions')->whereIn('task_id', $allTasks->pluck('id'))->exists()
                    || $allTasks->whereNotNull('parent_task_id')->isNotEmpty();
                $managed[] = [...$parent, 'tasks' => $serialized->whereNull('parent_task_id')->values(), 'breakdown_locked' => $locked];
                $taskIds = [...$taskIds, ...$allTasks->pluck('id')->all()];
            }
            if (! $context['valid']) {
                continue;
            }
            foreach ($serialized as $task) {
                if ((int) $task['assignee_id'] === $user && $task['assignee_eligible']) {
                    $own[] = [...$task, 'parent' => $parent];
                    $taskIds[] = $task['id'];
                }
                if (! $task['has_children'] && (int) $task['reviewer_id'] === $user && (int) $task['assignee_id'] !== $user && $task['assignee_eligible'] && $task['status'] === 'submitted') {
                    $reviews[] = [...$task, 'parent' => $parent];
                    $taskIds[] = $task['id'];
                }
            }
        }
        $departments = DB::table('department_memberships as m')->join('departments as d', 'd.id', '=', 'm.department_id')
            ->where('m.user_id', $user)->where('d.is_active', true)->get(['d.id', 'd.name', 'd.code', 'm.responsibility']);
        $employees = DB::table('reporting_relationships as r')->join('department_memberships as m', function ($join): void {
            $join->on('m.user_id', '=', 'r.user_id')->on('m.department_id', '=', 'r.department_id');
        })->join('users as u', 'u.id', '=', 'r.user_id')->join('departments as d', 'd.id', '=', 'r.department_id')
            ->where('r.manager_id', $user)->whereIn('m.responsibility', ['hof', 'manager', 'employee'])->where('u.is_active', true)->where('u.system_role', 'member')->where('d.is_active', true)
            ->orderBy('u.name')->get(['u.id', 'u.name', 'u.employee_number', 'u.designation', 'r.department_id']);
        $submissions = DB::table('task_submissions')->whereIn('task_id', array_unique($taskIds))->orderByDesc('id')->get()->map(function ($submission) {
            $submission->retain_timeliness = (bool) $submission->retain_timeliness;

            return $submission;
        });
        $peopleIds = collect($managed)->flatMap(fn ($parent) => collect($parent['tasks'])->pluck('assignee_id')->push($parent['owner_id']))
            ->merge(collect($own)->pluck('reviewer_id'))->merge(collect($own)->pluck('manager_id'))
            ->merge(collect($reviews)->pluck('assignee_id'))->unique();
        $delegatedKpis = app(KpiAssignmentController::class)->forRecipient($user);
        $peopleIds = $peopleIds->merge(collect($delegatedKpis)->flatMap(fn ($kpi) => collect($kpi['activities'])->map(fn ($row) => $row['assignment']?->assignee_id)->push($kpi['delegation']->owner_id)))->filter()->unique();
        $years = collect($managed)->pluck('year')->merge(collect($own)->pluck('parent.year'))
            ->merge(collect($delegatedKpis)->pluck('year'))->filter()->unique();
        $personalScores = $years->mapWithKeys(fn ($year) => [
            (string) $year => app(PersonalAllocationController::class)->liveScore($user, (int) $year),
        ]);

        $plannedWork = [];
        foreach (DB::table('performance_plans')->where('owner_id', $user)->whereNotNull('published_revision')->get() as $plan) {
            foreach (app(HofWorkController::class)->activities($plan) as $activity) {
                if ($this->membership($user, $activity['department_id'], 'hof') || ! $this->activeMember($user, $activity['department_id'])) {
                    continue;
                }
                $assignment = DB::table('work_assignments')->where('plan_id', $plan->id)->where('activity_code', $activity['activity_code'])->first();
                if (! $assignment || ! $this->context($assignment)['valid']) {
                    $plannedWork[] = [...$activity, 'version' => (int) ($assignment->version ?? 0)];
                }
            }
        }
        $reviewers = DB::table('reporting_relationships as r')->join('users as u', 'u.id', '=', 'r.manager_id')
            ->where('r.user_id', $user)->where('u.is_active', true)->where('u.system_role', 'member')
            ->get(['u.id', 'u.name', 'r.department_id'])->filter(fn ($person) => $this->activeMember((int) $person->id, (int) $person->department_id))->values();

        return response()->json([
            'planned_work' => $plannedWork, 'plan_reviewers' => $reviewers,
            'roles' => RequireDeliveryAccess::roles($request->user()), 'managed' => $managed, 'tasks' => $own, 'reviews' => $reviews,
            'employees' => $employees, 'departments' => $departments, 'submissions' => $submissions,
            'people' => DB::table('users')->whereIn('id', $peopleIds)->get(['id', 'name', 'employee_number']),
            'delegated_kpis' => $delegatedKpis,
            'personal_scores' => $personalScores,
        ]);
    }

    public function startPersonalWork(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer'], 'activity_code' => ['required', 'string', 'max:64'],
            'source_revision' => ['required', 'integer', 'min:1'], 'version' => ['required', 'integer', 'min:0'],
            'reviewer_id' => ['required', 'integer'],
        ]);
        DB::transaction(function () use ($request, $data): void {
            $plan = DB::table('performance_plans')->where('id', $data['plan_id'])->lockForUpdate()->first();
            $owner = $request->user()->id;
            abort_unless($plan && (int) $plan->owner_id === $owner, 403, 'Only the personal plan owner can start this work.');
            abort_unless((int) $plan->published_revision === (int) $data['source_revision'], 409, 'The published plan changed. Refresh first.');
            $activity = collect(app(HofWorkController::class)->activities($plan))->firstWhere('activity_code', $data['activity_code']);
            abort_unless($activity && $this->activeMember($owner, $activity['department_id']) && ! $this->membership($owner, $activity['department_id'], 'hof'), 422, 'Choose a current personal activity. HOFs assign work from their HOF workspace.');
            $reviewer = (int) $data['reviewer_id'];
            abort_unless($reviewer !== $owner && $this->activeMember($reviewer, $activity['department_id']) && $this->reports($owner, $reviewer, $activity['department_id']), 422, 'Choose an active reporting manager in this department.');
            $existing = DB::table('work_assignments')->where('plan_id', $plan->id)->where('activity_code', $activity['activity_code'])->lockForUpdate()->first();
            abort_unless((int) ($existing->version ?? 0) === (int) $data['version'], 409, 'This work changed. Refresh first.');
            abort_if($existing && $this->context($existing)['valid'], 409, 'This activity is already active. Open its tasks instead.');
            $assignment = app(HofWorkController::class)->writeAssignment($request, $plan, $activity, $owner, ['due_date' => $activity['due_date'], 'instructions' => $activity['notes']]);
            DB::table('delivery_tasks')->where('assignment_id', $assignment->id)->where('generation', $assignment->delivery_generation)->where('is_active', true)->whereNull('parent_task_id')->update(['reviewer_id' => $reviewer]);
        });

        return response()->json(['message' => 'Personal activity started. Your reporting manager will review completed work.']);
    }

    public function breakdown(Request $request, int $assignment): JsonResponse
    {
        return $this->saveBreakdown($request, $assignment);
    }

    public function breakdownTask(Request $request, int $task): JsonResponse
    {
        $reference = DB::table('delivery_tasks')->find($task);
        abort_unless($reference, 404);

        return $this->saveBreakdown($request, (int) $reference->assignment_id, $task);
    }

    private function saveBreakdown(Request $request, int $assignment, ?int $parentTask = null): JsonResponse
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'], 'tasks' => ['required', 'array', 'min:1', 'max:50'],
            'tasks.*.id' => ['nullable', 'integer', 'min:1'], 'tasks.*.title' => ['required', 'string', 'max:500'],
            'tasks.*.assignee_id' => ['required', 'integer'], 'tasks.*.weight' => ['required', 'numeric', 'between:0.0001,1'],
            'tasks.*.due_date' => ['nullable', 'date_format:Y-m-d'], 'tasks.*.instructions' => ['nullable', 'string', 'max:2000'],
        ]);
        $weights = array_map(fn ($row) => round((float) $row['weight'], 4), $data['tasks']);
        if (abs(array_sum($weights) - 1) > 0.00005) {
            throw ValidationException::withMessages(['tasks' => 'Task weights within this activity must total 100%.']);
        }
        DB::transaction(function () use ($request, $data, $weights, $assignment, $parentTask): void {
            [$parent] = $this->lockAssignment($assignment);
            $branch = $parentTask ? DB::table('delivery_tasks')->where('id', $parentTask)->lockForUpdate()->first() : null;
            abort_unless(! $parentTask || ($branch && $this->taskEligible($branch, $parent)), 409, 'This task is no longer current.');
            $actor = $request->user()->id;
            abort_unless((int) ($branch?->assignee_id ?? $parent->assignee_id) === $actor, 403, 'Only the person responsible for this work can divide it.');
            abort_unless((int) ($branch?->version ?? $parent->version) === (int) $data['version'], 409, 'This work changed. Refresh before saving.');
            abort_if($branch && ((float) $branch->reported_progress > 0 || DB::table('task_submissions')->where('task_id', $branch->id)->exists()), 409, 'Work that has started cannot be divided again.');
            $existing = DB::table('delivery_tasks')->where('assignment_id', $assignment)->where('generation', $parent->delivery_generation)
                ->where('is_active', true)->where('parent_task_id', $parentTask)->get()->keyBy('id');
            abort_if(DB::table('delivery_tasks')->whereIn('parent_task_id', $existing->keys())->where('is_active', true)->exists(), 409, 'A team member has already divided this work. Update that branch directly.');
            abort_if($existing->contains(fn ($task) => (float) $task->reported_progress > 0) || DB::table('task_submissions')->whereIn('task_id', $existing->keys())->exists(), 409, 'The task breakdown is locked after work starts. Ask the HOF to review the assignment if its scope must change.');
            $seen = [];
            foreach ($data['tasks'] as $index => $task) {
                $id = isset($task['id']) ? (int) $task['id'] : null;
                abort_if($id && (! $existing->has($id) || in_array($id, $seen, true)), 422, 'An existing task must belong to this activity and appear only once.');
                $assignee = (int) $task['assignee_id'];
                if ($assignee !== $actor && (! $this->activeMember($assignee, (int) $parent->department_id) || ! $this->reports($assignee, $actor, (int) $parent->department_id))) {
                    throw ValidationException::withMessages(['tasks' => 'Choose yourself or an active person reporting directly to you in this department.']);
                }
                $ancestor = $branch;
                while ($ancestor) {
                    abort_if($assignee !== $actor && (int) $ancestor->assignee_id === $assignee, 422, 'Work cannot be delegated back to an earlier owner.');
                    $ancestor = $ancestor->parent_task_id ? DB::table('delivery_tasks')->find($ancestor->parent_task_id) : null;
                }
                $attributes = [
                    'title' => $task['title'], 'assignee_id' => (int) $task['assignee_id'],
                    'reviewer_id' => $assignee === $actor ? ($branch?->reviewer_id ?? ((int) $parent->owner_id === $actor && ! $this->membership($actor, (int) $parent->department_id, 'hof') ? (DB::table('delivery_tasks')->where('assignment_id', $parent->id)->where('generation', $parent->delivery_generation)->whereNull('parent_task_id')->where('assignee_id', $actor)->orderBy('id')->value('reviewer_id') ?? $parent->owner_id) : $parent->owner_id)) : $actor,
                    'weight' => $weights[$index], 'due_date' => $task['due_date'] ?? null, 'instructions' => $task['instructions'] ?? null,
                    'updated_at' => now(),
                ];
                if ($id) {
                    DB::table('delivery_tasks')->where('id', $id)->update([...$attributes, 'version' => $existing[$id]->version + 1]);
                } else {
                    $id = DB::table('delivery_tasks')->insertGetId([...$attributes, 'parent_task_id' => $parentTask, 'assignment_id' => $parent->id, 'generation' => $parent->delivery_generation, 'manager_id' => $actor, 'created_at' => now()]);
                }
                $seen[] = $id;
                if ((int) $task['assignee_id'] !== $request->user()->id && (! isset($task['id']) || (int) $existing[$id]->assignee_id !== (int) $task['assignee_id'] || $existing[$id]->title !== $task['title'] || $existing[$id]->due_date != ($task['due_date'] ?? null))) {
                    NotificationController::send((int) $task['assignee_id'], 'task-assigned:'.$id.':'.($existing[$id]->version ?? 0), 'assignment', 'Task assigned or updated: '.$task['title'], ['workspace' => 'delivery', 'page' => 'tasks', 'search' => $task['title']]);
                }
            }
            DB::table('delivery_tasks')->where('assignment_id', $parent->id)->where('generation', $parent->delivery_generation)->where('parent_task_id', $parentTask)->whereNotIn('id', $seen)->update(['is_active' => false, 'updated_at' => now()]);
            $this->rollup($parent);
            $this->audit($request, 'tasks.allocated', 'Activity '.$parent->activity_code, ['parent_task_id' => $parentTask, 'tasks' => $data['tasks'], 'generation' => $parent->delivery_generation]);
        });

        return response()->json(['message' => 'Task breakdown saved.']);
    }

    private function lockTask(int $taskId): array
    {
        $reference = DB::table('delivery_tasks')->find($taskId);
        abort_unless($reference, 404);
        [$assignment] = $this->lockAssignment((int) $reference->assignment_id);
        $task = DB::table('delivery_tasks')->where('id', $taskId)->lockForUpdate()->first();
        abort_unless($task->is_active && (int) $task->generation === (int) $assignment->delivery_generation, 409, 'This task belongs to an earlier assignment cycle. Refresh your workspace.');
        abort_unless($this->allocationReady($assignment), 409, 'The manager must complete the 100% task allocation first.');
        abort_unless($this->taskEligible($task, $assignment), 409, 'The reporting relationship changed. Contact the person who assigned this work.');

        return [$task, $assignment];
    }

    public function progress(Request $request, int $task): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'reported_progress' => ['required', 'numeric', 'between:0,100'], 'progress_note' => ['required', 'string', 'max:2000']]);
        DB::transaction(function () use ($request, $data, $task): void {
            [$record, $assignment] = $this->lockTask($task);
            abort_if(DB::table('delivery_tasks')->where('parent_task_id', $task)->where('is_active', true)->exists(), 409, 'Progress for divided work is calculated from its child tasks.');
            abort_unless((int) $record->assignee_id === $request->user()->id, 403, 'You can update only your own tasks.');
            abort_unless((int) $record->version === (int) $data['version'], 409, 'This task changed. Refresh before saving.');
            abort_if(in_array($record->status, ['submitted', 'approved'], true), 409, 'Submitted or approved work cannot be edited.');
            DB::table('delivery_tasks')->where('id', $task)->update(['reported_progress' => round((float) $data['reported_progress'], 2), 'progress_note' => $data['progress_note'], 'status' => $record->status === 'needs_changes' ? 'needs_changes' : 'in_progress', 'version' => $record->version + 1, 'updated_at' => now()]);
            $this->rollup($assignment);
            $this->audit($request, 'task.progress', 'Task '.$task, $data);
        });

        return response()->json(['message' => 'Reported progress saved. Record completion evidence when this work is finished.']);
    }

    public function submit(Request $request, int $task): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'evidence' => ['required', 'string', 'max:5000'], 'evidence_url' => ['nullable', 'url:http,https', 'max:2000']]);
        $selfConfirmed = DB::transaction(function () use ($request, $data, $task): bool {
            [$record, $assignment] = $this->lockTask($task);
            abort_if(DB::table('delivery_tasks')->where('parent_task_id', $task)->where('is_active', true)->exists(), 409, 'Complete the child tasks to finish this work.');
            abort_unless((int) $record->assignee_id === $request->user()->id, 403, 'You can submit only your own tasks.');
            abort_unless((int) $record->version === (int) $data['version'], 409, 'This task changed. Refresh before submitting.');
            $selfConfirm = $this->selfConfirmable($record, $assignment);
            abort_if($record->status === 'approved' || ($record->status === 'submitted' && ! $selfConfirm), 409, 'This task is already submitted or approved.');
            if ($selfConfirm) {
                DB::table('task_submissions')->where('task_id', $task)->where('decision', 'pending')->update(['decision' => 'superseded']);
            }
            $previous = DB::table('task_submissions')->where('task_id', $task)->orderByDesc('id')->first();
            $basis = $previous && $previous->decision === 'needs_changes' && $previous->retain_timeliness
                ? ($previous->timeliness_submitted_at ?? $previous->submitted_at) : now();
            $submissionId = DB::table('task_submissions')->insertGetId([
                'task_id' => $task, 'submitted_by' => $request->user()->id, 'evidence' => $data['evidence'],
                'evidence_url' => $data['evidence_url'] ?? null, 'submitted_at' => now(),
                'timeliness_submitted_at' => $basis, 'timeliness_due_date' => app(TimelinessController::class)->effectiveDue($record, $assignment),
                'decision' => $selfConfirm ? 'approved' : 'pending',
                'reviewed_by' => null, 'reviewed_at' => $selfConfirm ? now() : null,
                'review_note' => $selfConfirm ? 'Completion confirmed by the HOF.' : null,
            ]);
            DB::table('delivery_tasks')->where('id', $task)->update([
                'status' => $selfConfirm ? 'approved' : 'submitted', 'reported_progress' => 100,
                'confirmed_progress' => $selfConfirm ? 100 : 0,
                'version' => $record->version + 1, 'updated_at' => now(),
            ]);
            $this->rollup($assignment);
            $this->audit($request, $selfConfirm ? 'task.confirmed' : 'task.submitted', 'Task '.$task, ['evidence' => $data['evidence'], 'evidence_url' => $data['evidence_url'] ?? null]);
            if (! $selfConfirm) {
                NotificationController::send((int) $record->reviewer_id, 'submitted:'.$task.':'.$record->version, 'submission', 'Work awaits your review: '.$record->title, ['workspace' => 'delivery', 'page' => 'reviews', 'task_id' => $task, 'submission_id' => $submissionId, 'search' => $record->title]);
            }

            return $selfConfirm;
        });

        return response()->json(['message' => $selfConfirmed ? 'HOF completion confirmed. Weighted progress recalculated.' : 'Work submitted for review.']);
    }

    public function review(Request $request, int $submission): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'decision' => ['required', Rule::in(['approved', 'needs_changes'])], 'review_note' => ['required_if:decision,needs_changes', 'nullable', 'string', 'max:2000'], 'correction_type' => ['sometimes', Rule::in(['incomplete', 'clarification'])]]);
        DB::transaction(function () use ($request, $data, $submission): void {
            $reference = DB::table('task_submissions')->find($submission);
            abort_unless($reference, 404);
            [$task, $assignment] = $this->lockTask((int) $reference->task_id);
            abort_if($this->selfConfirmable($task, $assignment), 403, 'HOF work is confirmed by its owner.');
            abort_unless((int) $task->reviewer_id === $request->user()->id && (int) $task->assignee_id !== $request->user()->id, 403, 'Only the designated reviewer can review this work. Self-approval is not allowed.');
            $latest = DB::table('task_submissions')->where('task_id', $task->id)->orderByDesc('id')->first();
            abort_unless((int) $task->version === (int) $data['version'] && $task->status === 'submitted' && (int) $latest->id === $submission && $latest->decision === 'pending', 409, 'This submission has changed or has already been reviewed.');
            DB::table('task_submissions')->where('id', $submission)->update(['decision' => $data['decision'], 'retain_timeliness' => $data['decision'] === 'needs_changes' && ($data['correction_type'] ?? 'incomplete') === 'clarification', 'reviewed_by' => $request->user()->id, 'review_note' => $data['review_note'] ?? null, 'reviewed_at' => now()]);
            DB::table('delivery_tasks')->where('id', $task->id)->update(['status' => $data['decision'], 'confirmed_progress' => $data['decision'] === 'approved' ? 100 : 0, 'version' => $task->version + 1, 'updated_at' => now()]);
            $this->rollup($assignment);
            $this->audit($request, 'task.reviewed', 'Task '.$task->id, ['submission_id' => $submission, ...$data]);
            NotificationController::send((int) $task->assignee_id, 'reviewed:'.$submission, $data['decision'], ($data['decision'] === 'approved' ? 'Work approved: ' : 'Changes requested: ').$task->title, ['workspace' => 'delivery', 'page' => 'tasks', 'search' => $task->title]);
        });

        return response()->json(['message' => 'Review decision saved.']);
    }

    public function extendDeadline(Request $request, int $task): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'due_date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:1000']]);
        DB::transaction(function () use ($request, $task, $data): void {
            [$record, $assignment] = $this->lockTask($task);
            $selfConfirm = $this->selfConfirmable($record, $assignment);
            abort_unless(((int) $record->reviewer_id === $request->user()->id && (int) $record->assignee_id !== $request->user()->id)
                || ($selfConfirm && (int) $record->assignee_id === $request->user()->id), 403, 'Only the assigning reviewer can approve a deadline extension.');
            abort_unless((int) $record->version === (int) $data['version'], 409, 'This task changed. Refresh before extending its deadline.');
            abort_if($record->status === 'approved', 409, 'Completed work keeps its recorded deadline and timeliness.');
            abort_if(DB::table('delivery_tasks')->where('parent_task_id', $task)->where('is_active', true)->exists(), 409, 'Extend deadlines on individual subtasks.');
            $previous = app(TimelinessController::class)->effectiveDue($record, $assignment);
            if (! $previous || $data['due_date'] <= $previous) {
                throw ValidationException::withMessages(['due_date' => 'Choose a date later than the current deadline.']);
            }
            DB::table('delivery_tasks')->where('id', $task)->update(['due_date' => $data['due_date'], 'version' => $record->version + 1, 'updated_at' => now()]);
            DB::table('task_submissions')->where('task_id', $task)->where('decision', 'pending')->update(['timeliness_due_date' => $data['due_date']]);
            $this->audit($request, 'task.deadline_extended', 'Task '.$task, ['previous_due_date' => $previous, 'due_date' => $data['due_date'], 'reason' => $data['reason']]);
            NotificationController::send((int) $record->assignee_id, 'deadline:'.$task.':'.$record->version, 'deadline', 'Deadline extended: '.$record->title, ['workspace' => 'delivery', 'page' => 'tasks', 'task_id' => $task]);
        });

        return response()->json(['message' => 'Deadline extension approved and recorded.']);
    }

    private function rollup(object $assignment): void
    {
        $allTasks = DB::table('delivery_tasks')->where('assignment_id', $assignment->id)
            ->where('generation', $assignment->delivery_generation)->where('is_active', true)->orderByDesc('id')->get();
        foreach ($allTasks as $task) {
            $children = $allTasks->where('parent_task_id', $task->id);
            if ($children->isEmpty()) {
                continue;
            }
            $ready = abs($children->sum('weight') - 1) < 0.00005
                && ! $children->contains(fn ($child) => ! $this->taskEligible($child, $assignment));
            $task->reported_progress = $ready ? round($children->sum(fn ($child) => (float) $child->weight * (float) $child->reported_progress), 2) : 0;
            $task->confirmed_progress = $ready ? round($children->sum(fn ($child) => (float) $child->weight * (float) $child->confirmed_progress), 2) : 0;
            DB::table('delivery_tasks')->where('id', $task->id)->update([
                'reported_progress' => $task->reported_progress, 'confirmed_progress' => $task->confirmed_progress,
                'status' => $task->confirmed_progress === 100.0 ? 'approved' : 'in_progress',
                'version' => $task->version + 1, 'updated_at' => now(),
            ]);
        }
        $tasks = $this->currentTasks($assignment);
        $ready = $tasks->isNotEmpty() && abs($tasks->sum('weight') - 1) < 0.00005;
        DB::table('work_assignments')->where('id', $assignment->id)->update([
            'reported_progress' => $ready ? round($tasks->sum(fn ($task) => (float) $task->weight * (float) $task->reported_progress), 2) : 0,
            'confirmed_progress' => $ready ? round($tasks->sum(fn ($task) => (float) $task->weight * (float) $task->confirmed_progress), 2) : 0,
            'version' => $assignment->version + 1, 'updated_at' => now(),
        ]);
    }

    private function audit(Request $request, string $action, string $subject, array $after): void
    {
        DB::table('admin_activity')->insert(['actor_id' => $request->user()->id, 'action' => $action, 'subject' => $subject, 'after_data' => json_encode($after), 'created_at' => now()]);
    }
}
