<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RequirePlanningAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ExtraWorkController extends Controller
{
    private function member(int $user, int $department): ?object
    {
        return DB::table('department_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')->join('departments as d', 'd.id', '=', 'm.department_id')
            ->where('m.user_id', $user)->where('m.department_id', $department)->where('u.is_active', true)->where('u.system_role', 'member')->where('d.is_active', true)->first(['m.*']);
    }

    private function reports(int $user, int $manager, int $department): bool
    {
        return $this->member($user, $department) && $this->member($manager, $department)
            && DB::table('reporting_relationships')->where('user_id', $user)->where('manager_id', $manager)->where('department_id', $department)->exists();
    }

    private function canAssign(int $actor, int $assignee, int $department): bool
    {
        return $actor === $assignee ? $this->member($actor, $department)?->responsibility === 'hof' : $this->reports($assignee, $actor, $department);
    }

    public function valid(object $assignment): bool
    {
        return $this->canAssign((int) $assignment->owner_id, (int) $assignment->assignee_id, (int) $assignment->department_id);
    }

    private function eligible(object $task, object $assignment, Collection $tasks): bool
    {
        if (! $task->is_active || ! $this->valid($assignment)) {
            return false;
        }
        if ($task->parent_task_id === null) {
            return (int) $task->assignee_id === (int) $assignment->assignee_id && (int) $task->reviewer_id === (int) $assignment->owner_id;
        }
        $parent = $tasks->get($task->parent_task_id);

        return $parent && (int) $parent->id < (int) $task->id && $this->eligible($parent, $assignment, $tasks)
            && (int) $task->manager_id === (int) $parent->assignee_id
            && ((int) $task->assignee_id === (int) $parent->assignee_id || $this->reports((int) $task->assignee_id, (int) $parent->assignee_id, (int) $assignment->department_id));
    }

    private function selfConfirm(object $task, object $assignment): bool
    {
        return (int) $task->assignee_id === (int) $assignment->owner_id && $this->member((int) $task->assignee_id, (int) $assignment->department_id)?->responsibility === 'hof';
    }

    private function scope(Request $request): Collection
    {
        $user = $request->user();
        abort_unless($user?->is_active && in_array($user->system_role, ['member', 'md', 'plan_editor'], true), 403);

        return $user->system_role === 'md' ? DB::table('departments')->where('is_active', true)->pluck('id') : RequirePlanningAccess::departmentIds($user);
    }

    private function tasks(int $assignment): Collection
    {
        return DB::table('extra_work_tasks')->where('assignment_id', $assignment)->where('is_active', true)->orderBy('id')->get()->keyBy('id');
    }

    private function timing(object $assignment, Collection $tasks): array
    {
        return app(TimelinessController::class)->forAssignment((object) [...(array) $assignment, 'delivery_generation' => 1], $tasks,
            DB::table('extra_work_submissions')->whereIn('task_id', $tasks->keys())->get(), 'extra');
    }

    public function summarize(array $sources): array
    {
        $total = array_sum(array_column($sources, 'weight'));

        return ['confirmed_completion' => $total > 0 ? round(array_sum(array_map(fn ($row) => $row['weight'] * $row['confirmed_progress'], $sources)) / $total, 2) : null,
            'responsibility_count' => count($sources), 'completed_count' => count(array_filter($sources, fn ($row) => $row['confirmed_progress'] >= 100)),
            'timeliness' => app(TimelinessController::class)->combine($sources)];
    }

    private function ownedSources(int $user, object $assignment, Collection $tasks, array $timing): array
    {
        $sources = [];
        $valid = ! $tasks->contains(fn ($record) => ! $this->eligible($record, $assignment, $tasks));
        foreach ($tasks->where('assignee_id', $user) as $task) {
            $weight = (float) $assignment->weight * (float) $task->weight;
            $parent = $task->parent_task_id ? $tasks->get($task->parent_task_id) : null;
            $duplicate = false;
            $previous = (int) $task->id;
            while ($parent) {
                if ((int) $parent->id >= $previous) {
                    $duplicate = true;
                    break;
                }
                $previous = (int) $parent->id;
                if ((int) $parent->assignee_id === $user) {
                    $duplicate = true;
                    break;
                }
                $weight *= (float) $parent->weight;
                $parent = $parent->parent_task_id ? $tasks->get($parent->parent_task_id) : null;
            }
            if (! $duplicate && $this->eligible($task, $assignment, $tasks)) {
                $sources[] = ['assignment_id' => $assignment->id, 'task_id' => $task->id, 'title' => $task->title, 'year' => (int) $assignment->year, 'department_id' => (int) $assignment->department_id, 'weight' => $weight, 'confirmed_progress' => $valid ? (float) $task->confirmed_progress : 0, 'timeliness' => $valid ? ($timing['tasks'][$task->id] ?? null) : null];
            }
        }

        return $sources;
    }

    private function portfolio(int $user, int $year, int $department): array
    {
        $sources = [];
        foreach (DB::table('extra_work_assignments')->where('year', $year)->where('department_id', $department)->get() as $assignment) {
            $tasks = $this->tasks((int) $assignment->id);
            $sources = [...$sources, ...$this->ownedSources($user, $assignment, $tasks, $this->timing($assignment, $tasks))];
        }

        return $this->summarize($sources);
    }

    private function audit(Request $request, string $event, object $assignment, array $data): void
    {
        DB::table('admin_activity')->insert(['actor_id' => $request->user()->id, 'action' => 'extra_work.'.$event,
            'subject' => mb_substr($assignment->title, 0, 180), 'after_data' => json_encode($data), 'created_at' => now()]);
    }

    private function version(Request $request, object $assignment, string $event, ?string $reason, array $before): void
    {
        $current = DB::table('extra_work_assignments')->find($assignment->id);
        DB::table('extra_work_versions')->insert(['assignment_id' => $assignment->id, 'actor_id' => $request->user()->id, 'version' => $current->version,
            'event' => $event, 'reason' => $reason, 'snapshot' => json_encode(['assignment' => (array) $current, 'department_id' => $assignment->department_id, 'before' => $before,
                'after' => $this->portfolio((int) $assignment->assignee_id, (int) $assignment->year, (int) $assignment->department_id)]), 'created_at' => now()]);
        $this->audit($request, $event, $current, ['assignment_id' => $current->id, 'version' => $current->version, 'reason' => $reason]);
    }

    private function notify(int $user, string $event, string $type, string $message, int $task, ?int $submission = null): void
    {
        NotificationController::send($user, 'extra-'.$event.':'.$task.':'.now()->format('Uu'), $type, $message,
            ['workspace' => 'extra', 'page' => $type === 'submission' ? 'reviews' : 'tasks', 'extra_task_id' => $task, 'extra_submission_id' => $submission]);
    }

    public function workspace(Request $request): JsonResponse
    {
        $departments = $this->scope($request);
        $user = $request->user();
        $readOnly = $user->system_role !== 'member';
        $memberships = DB::table('department_memberships')->where('user_id', $user->id)->get();
        $hofDepartments = $memberships->where('responsibility', 'hof')->pluck('department_id');
        $participants = DB::table('extra_work_tasks')->where('is_active', true)->where(fn ($query) => $query->where('assignee_id', $user->id)->orWhere('reviewer_id', $user->id))->pluck('assignment_id');
        $assignments = DB::table('extra_work_assignments')->whereIn('department_id', $departments)
            ->when(! $readOnly, fn ($query) => $query->where(fn ($query) => $query->where('owner_id', $user->id)->orWhereIn('id', $participants)->orWhereIn('department_id', $hofDepartments)))
            ->orderByDesc('id')->get();
        $people = DB::table('users')->where('is_active', true)->get(['id', 'name', 'employee_number', 'designation']);
        $rows = $own = $reviews = $reviewHistory = $submissions = $sources = [];
        $personIds = collect([$user->id]);
        foreach ($assignments as $assignment) {
            $tasks = $this->tasks((int) $assignment->id);
            $personIds = $personIds->merge($tasks->pluck('assignee_id'))->merge($tasks->pluck('reviewer_id'));
            $timing = $this->timing($assignment, $tasks);
            $valid = $this->valid($assignment) && ! $tasks->contains(fn ($task) => ! $this->eligible($task, $assignment, $tasks));
            $parent = [...(array) $assignment, 'activity' => $assignment->title, 'kpa_title' => 'Extra work', 'kpi_title' => '', 'activity_code' => 'EXTRA-'.$assignment->id,
                'weight' => (float) $assignment->weight, 'confirmed_progress' => $valid ? (float) $assignment->confirmed_progress : 0,
                'reported_progress' => (float) $assignment->reported_progress, 'valid' => $valid, 'timeliness' => $valid ? $timing['summary'] : null,
                'can_view_history' => $readOnly || (int) $assignment->owner_id === (int) $user->id || (int) $assignment->assignee_id === (int) $user->id || $hofDepartments->contains($assignment->department_id),
                'can_change_weight' => ! $readOnly && (int) $assignment->owner_id === (int) $user->id];
            $rows[] = $parent;
            $sources = [...$sources, ...$this->ownedSources((int) $user->id, $assignment, $tasks, $timing)];
            $visibleIds = [];
            foreach ($tasks as $task) {
                $children = $tasks->where('parent_task_id', $task->id)->values();
                $selfConfirm = $this->selfConfirm($task, $assignment);
                $serialized = [...(array) $task, 'weight' => (float) $task->weight, 'confirmed_progress' => $valid ? (float) $task->confirmed_progress : 0,
                    'reported_progress' => (float) $task->reported_progress, 'self_confirm' => $selfConfirm,
                    'reviewer_id' => $selfConfirm ? null : (int) $task->reviewer_id, 'parent' => $parent, 'has_children' => $children->isNotEmpty(), 'children' => $children,
                    'timeliness' => $valid ? ($timing['tasks'][$task->id] ?? null) : null, 'assignee_eligible' => $valid,
                    'breakdown_locked' => (float) $task->reported_progress > 0 || DB::table('extra_work_submissions')->where('task_id', $task->id)->exists(),
                    'can_extend_deadline' => $valid && ! $readOnly && $children->isEmpty() && $task->status !== 'approved' && (((int) $task->reviewer_id === (int) $user->id && (int) $task->assignee_id !== (int) $user->id) || $selfConfirm && (int) $task->assignee_id === (int) $user->id)];
                if (! $readOnly && (int) $task->assignee_id === (int) $user->id) {
                    $own[] = $serialized;
                    $visibleIds[] = $task->id;
                }
                if (! $readOnly && $valid && ! $selfConfirm && (int) $task->reviewer_id === (int) $user->id) {
                    $reviewHistory[] = $serialized;
                    $visibleIds[] = $task->id;
                }
                if (! $readOnly && $valid && ! $selfConfirm && (int) $task->reviewer_id === (int) $user->id && $task->status === 'submitted') {
                    $reviews[] = $serialized;
                    $visibleIds[] = $task->id;
                }
                if ($readOnly || (int) $assignment->owner_id === (int) $user->id || $hofDepartments->contains($assignment->department_id)) {
                    $visibleIds[] = $task->id;
                }
            }
            foreach (DB::table('extra_work_submissions')->whereIn('task_id', $visibleIds)->orderByDesc('id')->get() as $submission) {
                $submission->retain_timeliness = (bool) $submission->retain_timeliness;
                $submissions[] = $submission;
            }
        }
        $employees = DB::table('reporting_relationships as r')->join('users as u', 'u.id', '=', 'r.user_id')->where('r.manager_id', $user->id)->whereIn('r.department_id', $departments)
            ->where('u.is_active', true)->where('u.system_role', 'member')->get(['u.id', 'u.name', 'u.employee_number', 'r.department_id'])->filter(fn ($person) => $this->member((int) $person->id, (int) $person->department_id))->values();
        $self = $memberships->whereIn('department_id', $departments)->where('responsibility', 'hof')->map(fn ($membership) => (object) ['id' => $user->id, 'name' => $user->name, 'employee_number' => $user->employee_number, 'department_id' => $membership->department_id]);

        return response()->json(['assignments' => $rows, 'tasks' => $own, 'reviews' => $reviews, 'review_history' => $reviewHistory, 'submissions' => $submissions, 'personal_sources' => $sources,
            'people' => $people->whereIn('id', $personIds->merge(collect($rows)->flatMap(fn ($row) => [$row['owner_id'], $row['assignee_id']])->merge(collect($own)->pluck('reviewer_id'))->merge(collect($own)->flatMap(fn ($task) => collect($task['children'])->pluck('assignee_id')))->merge(collect($reviews)->pluck('assignee_id'))->merge($employees->pluck('id'))->push($user->id)))->values(),
            'departments' => DB::table('departments')->whereIn('id', $departments)->orderBy('name')->get(['id', 'name', 'code']), 'employees' => $employees, 'recipients' => $readOnly ? [] : $employees->concat($self)->values(),
            'read_only' => $readOnly, 'can_assign' => ! $readOnly && ($employees->isNotEmpty() || $self->isNotEmpty())]);
    }

    public function assign(Request $request): JsonResponse
    {
        $this->scope($request);
        abort_unless($request->user()->system_role === 'member', 403);
        $data = $request->validate(['title' => ['required', 'string', 'max:500'], 'instructions' => ['nullable', 'string', 'max:2000'], 'department_id' => ['required', 'integer'],
            'assignee_id' => ['required', 'integer'], 'year' => ['required', 'integer', 'between:2000,2100'], 'weight' => ['required', 'numeric', 'between:0.01,100'], 'due_date' => ['required', 'date_format:Y-m-d'], 'priority' => ['required', Rule::in(['normal', 'high', 'urgent'])]]);
        $id = DB::transaction(function () use ($request, $data): int {
            DB::table('users')->whereIn('id', [$request->user()->id, $data['assignee_id']])->orderBy('id')->lockForUpdate()->get();
            abort_unless($this->canAssign($request->user()->id, (int) $data['assignee_id'], (int) $data['department_id']), 403, 'Assign extra work only within your configured department reporting relationships.');
            $before = $this->portfolio((int) $data['assignee_id'], (int) $data['year'], (int) $data['department_id']);
            $id = DB::table('extra_work_assignments')->insertGetId([...$data, 'owner_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $task = DB::table('extra_work_tasks')->insertGetId(['assignment_id' => $id, 'manager_id' => $request->user()->id, 'assignee_id' => $data['assignee_id'], 'reviewer_id' => $request->user()->id,
                'title' => $data['title'], 'instructions' => $data['instructions'] ?? null, 'weight' => 1, 'due_date' => $data['due_date'], 'created_at' => now(), 'updated_at' => now()]);
            $assignment = DB::table('extra_work_assignments')->find($id);
            $this->version($request, $assignment, 'assigned', null, $before);
            $this->notify((int) $data['assignee_id'], 'assigned', 'assignment', 'Extra work assigned: '.$data['title'], $task);

            return $id;
        });

        return response()->json(['id' => $id, 'message' => 'Extra work assigned.'], 201);
    }

    private function lockTask(Request $request, int $id): array
    {
        $this->scope($request);
        abort_unless($request->user()->system_role === 'member', 403);
        $reference = DB::table('extra_work_tasks')->find($id);
        abort_unless($reference, 404);
        $assignment = DB::table('extra_work_assignments')->where('id', $reference->assignment_id)->lockForUpdate()->first();
        $task = DB::table('extra_work_tasks')->where('id', $id)->lockForUpdate()->first();
        $tasks = $this->tasks((int) $assignment->id);
        abort_unless($this->eligible($task, $assignment, $tasks) && ! $tasks->contains(fn ($record) => ! $this->eligible($record, $assignment, $tasks)), 409, 'The extra-work reporting relationship changed. Contact your administrator.');

        return [$task, $assignment, $tasks];
    }

    private function checkVersion(object $task, array $data): void
    {
        abort_unless((int) $task->version === (int) $data['version'], 409, 'This extra work changed. Refresh before saving.');
    }

    private function rollup(object $assignment): void
    {
        $tasks = $this->tasks((int) $assignment->id)->sortByDesc('id');
        foreach ($tasks as $task) {
            $children = $tasks->where('parent_task_id', $task->id);
            if ($children->isEmpty()) {
                continue;
            }
            $task->reported_progress = round($children->sum(fn ($child) => $child->weight * $child->reported_progress), 2);
            $task->confirmed_progress = round($children->sum(fn ($child) => $child->weight * $child->confirmed_progress), 2);
            DB::table('extra_work_tasks')->where('id', $task->id)->update(['reported_progress' => $task->reported_progress, 'confirmed_progress' => $task->confirmed_progress,
                'status' => $task->confirmed_progress >= 100 ? 'approved' : 'in_progress', 'version' => $task->version + 1, 'updated_at' => now()]);
        }
        $roots = $tasks->whereNull('parent_task_id');
        DB::table('extra_work_assignments')->where('id', $assignment->id)->update(['reported_progress' => round($roots->sum(fn ($task) => $task->weight * $task->reported_progress), 2),
            'confirmed_progress' => round($roots->sum(fn ($task) => $task->weight * $task->confirmed_progress), 2), 'version' => $assignment->version + 1, 'updated_at' => now()]);
    }

    public function progress(Request $request, int $task): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'reported_progress' => ['required', 'numeric', 'between:0,100'], 'progress_note' => ['required', 'string', 'max:2000']]);
        DB::transaction(function () use ($request, $task, $data): void {
            [$record, $assignment, $tasks] = $this->lockTask($request, $task);
            abort_unless((int) $record->assignee_id === $request->user()->id, 403);
            $this->checkVersion($record, $data);
            abort_if(in_array($record->status, ['approved', 'submitted'], true) || $tasks->contains('parent_task_id', $task), 409, 'Submitted or divided work cannot be updated directly.');
            DB::table('extra_work_tasks')->where('id', $task)->update(['reported_progress' => $data['reported_progress'], 'progress_note' => $data['progress_note'], 'status' => $record->status === 'needs_changes' ? 'needs_changes' : 'in_progress', 'version' => $record->version + 1, 'updated_at' => now()]);
            $this->rollup($assignment);
            $this->audit($request, 'progress', $assignment, ['task_id' => $task, ...$data]);
        });

        return response()->json(['message' => 'Extra-work progress saved.']);
    }

    public function submit(Request $request, int $task): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'evidence' => ['required', 'string', 'max:5000'], 'evidence_url' => ['nullable', 'url:http,https', 'max:2000']]);
        DB::transaction(function () use ($request, $task, $data): void {
            [$record, $assignment, $tasks] = $this->lockTask($request, $task);
            abort_unless((int) $record->assignee_id === $request->user()->id, 403);
            $this->checkVersion($record, $data);
            abort_if(in_array($record->status, ['approved', 'submitted'], true) || $tasks->contains('parent_task_id', $task), 409, 'This work is already submitted, approved or divided.');
            $self = $this->selfConfirm($record, $assignment);
            $previous = DB::table('extra_work_submissions')->where('task_id', $task)->orderByDesc('id')->first();
            $basis = $previous && $previous->decision === 'needs_changes' && $previous->retain_timeliness ? ($previous->timeliness_submitted_at ?? $previous->submitted_at) : now();
            $submissionId = DB::table('extra_work_submissions')->insertGetId(['task_id' => $task, 'submitted_by' => $request->user()->id, 'evidence' => $data['evidence'], 'evidence_url' => $data['evidence_url'] ?? null,
                'decision' => $self ? 'approved' : 'pending', 'submitted_at' => now(), 'timeliness_submitted_at' => $basis,
                'timeliness_due_date' => app(TimelinessController::class)->effectiveDue($record, $assignment, $tasks), 'reviewed_at' => $self ? now() : null, 'review_note' => $self ? 'HOF own work confirmed.' : null]);
            DB::table('extra_work_tasks')->where('id', $task)->update(['status' => $self ? 'approved' : 'submitted', 'reported_progress' => 100, 'confirmed_progress' => $self ? 100 : 0, 'version' => $record->version + 1, 'updated_at' => now()]);
            $this->rollup($assignment);
            $this->audit($request, 'submitted', $assignment, ['task_id' => $task]);
            if (! $self) {
                $this->notify((int) $record->reviewer_id, 'submitted-'.$record->version, 'submission', 'Extra work awaits review: '.$record->title, $task, $submissionId);
            }
        });

        return response()->json(['message' => 'Extra work submitted.']);
    }

    public function review(Request $request, int $submission): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'decision' => ['required', Rule::in(['approved', 'needs_changes'])],
            'review_note' => ['nullable', 'required_if:decision,needs_changes', 'string', 'max:2000'], 'correction_type' => ['sometimes', Rule::in(['incomplete', 'clarification'])]]);
        DB::transaction(function () use ($request, $submission, $data): void {
            $reference = DB::table('extra_work_submissions')->find($submission);
            abort_unless($reference, 404);
            [$task, $assignment] = $this->lockTask($request, (int) $reference->task_id);
            abort_unless((int) $task->reviewer_id === $request->user()->id && (int) $task->assignee_id !== $request->user()->id, 403);
            $this->checkVersion($task, $data);
            abort_unless($task->status === 'submitted' && $reference->decision === 'pending' && (int) DB::table('extra_work_submissions')->where('task_id', $task->id)->max('id') === $submission, 409, 'This submission changed.');
            DB::table('extra_work_submissions')->where('id', $submission)->update(['decision' => $data['decision'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'review_note' => $data['review_note'] ?? null,
                'retain_timeliness' => $data['decision'] === 'needs_changes' && ($data['correction_type'] ?? 'incomplete') === 'clarification']);
            DB::table('extra_work_tasks')->where('id', $task->id)->update(['status' => $data['decision'], 'confirmed_progress' => $data['decision'] === 'approved' ? 100 : 0, 'version' => $task->version + 1, 'updated_at' => now()]);
            $this->rollup($assignment);
            $this->audit($request, 'reviewed', $assignment, ['submission_id' => $submission, ...$data]);
            $this->notify((int) $task->assignee_id, 'reviewed-'.$submission, $data['decision'], 'Extra work '.$data['decision'].': '.$task->title, (int) $task->id);
        });

        return response()->json(['message' => 'Extra-work review saved.']);
    }

    public function breakdown(Request $request, int $task): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'tasks' => ['required', 'array', 'min:1', 'max:50'], 'tasks.*.id' => ['nullable', 'integer'],
            'tasks.*.title' => ['required', 'string', 'max:500'], 'tasks.*.assignee_id' => ['required', 'integer'], 'tasks.*.weight' => ['required', 'numeric', 'between:0.0001,1'], 'tasks.*.due_date' => ['nullable', 'date_format:Y-m-d'], 'tasks.*.instructions' => ['nullable', 'string', 'max:2000']]);
        $weights = array_map(fn ($row) => round((float) $row['weight'], 4), $data['tasks']);
        if (abs(array_sum($weights) - 1) > .00005) {
            throw ValidationException::withMessages(['tasks' => 'Task weights must total 100%.']);
        }
        DB::transaction(function () use ($request, $task, $data, $weights): void {
            [$parent, $assignment, $all] = $this->lockTask($request, $task);
            abort_unless((int) $parent->assignee_id === $request->user()->id, 403);
            $this->checkVersion($parent, $data);
            $descendants = collect([$task]);
            foreach ($all as $record) {
                if ($descendants->contains($record->parent_task_id)) {
                    $descendants->push($record->id);
                }
            }
            abort_if($all->whereIn('id', $descendants)->contains(fn ($record) => (float) $record->reported_progress > 0)
                || DB::table('extra_work_submissions')->whereIn('task_id', $descendants)->exists(), 409, 'Weights and owners lock after work starts.');
            $children = $all->where('parent_task_id', $task);
            abort_if($children->contains(fn ($child) => $all->contains('parent_task_id', $child->id)), 409, 'A subordinate already divided this work.');
            $seen = [];
            foreach ($data['tasks'] as $index => $row) {
                $assignee = (int) $row['assignee_id'];
                abort_unless($assignee === $request->user()->id || $this->reports($assignee, $request->user()->id, (int) $assignment->department_id), 403, 'Choose a direct reporting person in this department.');
                $ancestor = $parent;
                while ($ancestor) {
                    abort_if($assignee !== $request->user()->id && (int) $ancestor->assignee_id === $assignee, 422, 'Work cannot be delegated back to an earlier owner.');
                    $ancestor = $ancestor->parent_task_id ? $all->get($ancestor->parent_task_id) : null;
                }
                $id = $row['id'] ?? null;
                abort_if($id && (! $children->has($id) || in_array($id, $seen)), 422, 'Choose a current child task.');
                $attributes = ['title' => $row['title'], 'assignee_id' => $assignee, 'reviewer_id' => $assignee === $request->user()->id ? $parent->reviewer_id : $request->user()->id,
                    'weight' => $weights[$index], 'instructions' => $row['instructions'] ?? null, 'due_date' => $row['due_date'] ?? null, 'updated_at' => now()];
                if ($id) {
                    DB::table('extra_work_tasks')->where('id', $id)->update([...$attributes, 'version' => $children->get($id)->version + 1]);
                } else {
                    $id = DB::table('extra_work_tasks')->insertGetId([...$attributes, 'assignment_id' => $assignment->id, 'parent_task_id' => $task, 'manager_id' => $request->user()->id, 'created_at' => now()]);
                }
                $seen[] = $id;
                $this->notify($assignee, 'delegated', 'assignment', 'Extra subtask assigned: '.$row['title'], (int) $id);
            }
            DB::table('extra_work_tasks')->whereIn('id', $children->keys()->diff($seen))->update(['is_active' => false]);
            $this->rollup($assignment);
            $this->audit($request, 'divided', $assignment, ['task_id' => $task, 'tasks' => $data['tasks']]);
        });

        return response()->json(['message' => 'Extra-work breakdown saved.']);
    }

    public function extendDeadline(Request $request, int $task): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'due_date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:1000']]);
        DB::transaction(function () use ($request, $task, $data): void {
            [$record, $assignment, $tasks] = $this->lockTask($request, $task);
            abort_unless(((int) $record->reviewer_id === $request->user()->id && (int) $record->assignee_id !== $request->user()->id) || ($this->selfConfirm($record, $assignment) && (int) $record->assignee_id === $request->user()->id), 403);
            $this->checkVersion($record, $data);
            abort_if($record->status === 'approved' || $tasks->contains('parent_task_id', $task), 409, 'Only unfinished leaf work can have its deadline extended.');
            $previous = app(TimelinessController::class)->effectiveDue($record, $assignment, $tasks);
            abort_unless($previous && $data['due_date'] > $previous, 422, 'Choose a date later than the current deadline.');
            DB::table('extra_work_tasks')->where('id', $task)->update(['due_date' => $data['due_date'], 'version' => $record->version + 1, 'updated_at' => now()]);
            DB::table('extra_work_submissions')->where('task_id', $task)->where('decision', 'pending')->update(['timeliness_due_date' => $data['due_date']]);
            $this->rollup($assignment);
            $this->audit($request, 'deadline_extended', $assignment, ['task_id' => $task, 'previous_due_date' => $previous, ...$data]);
            $this->notify((int) $record->assignee_id, 'deadline', 'deadline', 'Extra-work deadline extended: '.$record->title, $task);
        });

        return response()->json(['message' => 'Extra-work deadline extension approved.']);
    }

    public function approveWeight(Request $request, int $assignment): JsonResponse
    {
        $this->scope($request);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'weight' => ['required', 'numeric', 'between:0.01,100'], 'reason' => ['required', 'string', 'max:1000']]);
        DB::transaction(function () use ($request, $assignment, $data): void {
            $reference = DB::table('extra_work_assignments')->find($assignment);
            abort_unless($reference, 404);
            DB::table('users')->whereIn('id', [$request->user()->id, $reference->assignee_id])->orderBy('id')->lockForUpdate()->get();
            $record = DB::table('extra_work_assignments')->where('id', $assignment)->lockForUpdate()->first();
            abort_unless($record, 404);
            abort_unless($request->user()->system_role === 'member' && (int) $record->owner_id === $request->user()->id && $this->valid($record), 403, 'Only the assigning reviewer can approve a weight change.');
            $this->checkVersion($record, $data);
            $before = $this->portfolio((int) $record->assignee_id, (int) $record->year, (int) $record->department_id);
            DB::table('extra_work_assignments')->where('id', $assignment)->update(['weight' => $data['weight'], 'version' => $record->version + 1, 'updated_at' => now()]);
            $this->version($request, $record, 'weight_approved', $data['reason'], $before);
            $rootTask = $this->tasks($assignment)->whereNull('parent_task_id')->first();
            if ($rootTask) {
                $this->notify((int) $record->assignee_id, 'weight-'.$record->version, 'assignment', 'Extra-work weight approved: '.$record->title, (int) $rootTask->id);
            }
        });

        return response()->json(['message' => 'Extra-work weight change approved and recorded.']);
    }

    public function notificationState(int $taskId, int $user, bool $review, ?int $submission): array
    {
        $task = DB::table('extra_work_tasks')->find($taskId);
        $assignment = $task ? DB::table('extra_work_assignments')->find($task->assignment_id) : null;
        $valid = $assignment && $this->eligible($task, $assignment, $this->tasks((int) $assignment->id));
        $latest = $task ? DB::table('extra_work_submissions')->where('task_id', $taskId)->orderByDesc('id')->first() : null;
        $available = $valid && ($review ? (int) $task->reviewer_id === $user : (int) $task->assignee_id === $user);

        return ['available' => $available, 'state' => $task->status ?? 'Unavailable', 'required' => $available && ($review
            ? ($task->status === 'submitted' && $latest?->decision === 'pending' && ($submission === null || (int) $latest->id === $submission))
            : ! in_array($task->status, ['approved', 'submitted'], true))];
    }

    public function history(Request $request, int $assignment): JsonResponse
    {
        $scope = $this->scope($request);
        $record = DB::table('extra_work_assignments')->find($assignment);
        abort_unless($record, 404);
        abort_unless($scope->contains($record->department_id) && ($request->user()->system_role !== 'member' || (int) $record->owner_id === $request->user()->id || (int) $record->assignee_id === $request->user()->id || $this->member($request->user()->id, (int) $record->department_id)?->responsibility === 'hof'), 403);

        return response()->json(['versions' => DB::table('extra_work_versions as v')->join('users as u', 'u.id', '=', 'v.actor_id')->where('v.assignment_id', $assignment)->orderByDesc('v.id')->get(['v.*', 'u.name as actor_name'])]);
    }
}
