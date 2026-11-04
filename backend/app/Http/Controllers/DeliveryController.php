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
        return $user === (int) $assignment->assignee_id || ($this->membership($user, (int) $assignment->department_id, 'employee') && $this->reports($user, (int) $assignment->assignee_id, (int) $assignment->department_id));
    }

    public function context(object $assignment, ?object $plan = null): array
    {
        $plan ??= DB::table('performance_plans')->find($assignment->plan_id);
        $activity = $plan ? collect(app(HofWorkController::class)->activities($plan))->firstWhere('activity_code', $assignment->activity_code) : null;
        $valid = $activity && (int) $plan->owner_id === (int) $assignment->owner_id
            && $this->membership((int) $assignment->owner_id, (int) $assignment->department_id, 'hof')
            && (int) $assignment->owner_id !== (int) $assignment->assignee_id
            && app(HofWorkController::class)->recipientRole((int) $assignment->owner_id, (int) $assignment->assignee_id, (int) $assignment->department_id) === $assignment->recipient_role
            && $assignment->source_signature === $activity['source_signature'];

        return ['activity' => $activity, 'valid' => (bool) $valid];
    }

    private function currentTasks(object $assignment): Collection
    {
        return DB::table('delivery_tasks')->where('assignment_id', $assignment->id)->where('generation', $assignment->delivery_generation)->where('is_active', true)->orderBy('id')->get();
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
            $parent = [
                'id' => (int) $assignment->id, 'version' => (int) $assignment->version, 'generation' => (int) $assignment->delivery_generation,
                'owner_id' => (int) $assignment->owner_id, 'manager_id' => (int) $assignment->assignee_id,
                'department_id' => (int) $assignment->department_id, 'activity' => $activity['activity'] ?? $assignment->activity_code,
                'activity_code' => $assignment->activity_code, 'kpa_title' => $activity['kpa_title'] ?? '', 'kpi_title' => $activity['kpi_title'] ?? '',
                'year' => $activity['year'] ?? null, 'personal_weight' => $activity['personal_weight'] ?? 0,
                'due_date' => $assignment->due_date ? substr((string) $assignment->due_date, 0, 10) : null,
                'instructions' => $assignment->instructions, 'valid' => $context['valid'], 'allocation_ready' => $ready,
                'confirmed_progress' => $context['valid'] && $ready ? (float) $assignment->confirmed_progress : 0,
                'reported_progress' => (float) $assignment->reported_progress,
                'recipient_role' => app(HofWorkController::class)->recipientRole((int) $assignment->owner_id, (int) $assignment->assignee_id, (int) $assignment->department_id),
                'ownership_mode' => $assignment->ownership_mode,
                'delegation_id' => $assignment->delegation_id,
            ];
            $serialized = $tasks->map(function ($task) use ($assignment): array {
                return [...(array) $task, 'weight' => (float) $task->weight, 'reported_progress' => (float) $task->reported_progress,
                    'due_date' => $task->due_date ? substr((string) $task->due_date, 0, 10) : null,
                    'assignee_eligible' => $this->employeeEligible((int) $task->assignee_id, $assignment),
                ];
            });
            if ((int) $assignment->assignee_id === $user && $this->membership($user, (int) $assignment->department_id, 'manager') && (int) $assignment->owner_id !== $user) {
                $locked = $tasks->contains(fn ($task) => (float) $task->reported_progress > 0) || DB::table('task_submissions')->whereIn('task_id', $tasks->pluck('id'))->exists();
                $managed[] = [...$parent, 'tasks' => $serialized, 'breakdown_locked' => $locked];
                $taskIds = [...$taskIds, ...$tasks->pluck('id')->all()];
            }
            if (! $context['valid']) {
                continue;
            }
            foreach ($serialized as $task) {
                if ((int) $task['assignee_id'] === $user && $task['assignee_eligible']) {
                    $own[] = [...$task, 'parent' => $parent];
                    $taskIds[] = $task['id'];
                }
                if ((int) $task['reviewer_id'] === $user && (int) $task['assignee_id'] !== $user && $task['assignee_eligible'] && $task['status'] === 'submitted') {
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
            ->where('r.manager_id', $user)->where('m.responsibility', 'employee')->where('u.is_active', true)->where('u.system_role', 'member')->where('d.is_active', true)
            ->orderBy('u.name')->get(['u.id', 'u.name', 'u.employee_number', 'u.designation', 'r.department_id']);
        $submissions = DB::table('task_submissions')->whereIn('task_id', array_unique($taskIds))->orderByDesc('id')->get();
        $peopleIds = collect($managed)->flatMap(fn ($parent) => collect($parent['tasks'])->pluck('assignee_id')->push($parent['owner_id']))
            ->merge(collect($own)->pluck('reviewer_id'))->merge(collect($reviews)->pluck('assignee_id'))->unique();
        $delegatedKpis = app(KpiAssignmentController::class)->forRecipient($user);
        $peopleIds = $peopleIds->merge(collect($delegatedKpis)->flatMap(fn ($kpi) => collect($kpi['activities'])->map(fn ($row) => $row['assignment']?->assignee_id)->push($kpi['delegation']->owner_id)))->filter()->unique();

        return response()->json([
            'roles' => RequireDeliveryAccess::roles($request->user()), 'managed' => $managed, 'tasks' => $own, 'reviews' => $reviews,
            'employees' => $employees, 'departments' => $departments, 'submissions' => $submissions,
            'people' => DB::table('users')->whereIn('id', $peopleIds)->get(['id', 'name', 'employee_number']),
            'delegated_kpis' => $delegatedKpis,
        ]);
    }

    public function breakdown(Request $request, int $assignment): JsonResponse
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
        DB::transaction(function () use ($request, $data, $weights, $assignment): void {
            [$parent] = $this->lockAssignment($assignment);
            abort_unless((int) $parent->assignee_id === $request->user()->id, 403, 'Only this activity’s manager can define its tasks.');
            abort_unless($this->membership($request->user()->id, (int) $parent->department_id, 'manager'), 403, 'Direct employee work is defined by its HOF.');
            abort_unless((int) $parent->version === (int) $data['version'], 409, 'This activity changed. Refresh before saving.');
            $existing = $this->currentTasks($parent)->keyBy('id');
            abort_if($existing->contains(fn ($task) => (float) $task->reported_progress > 0) || DB::table('task_submissions')->whereIn('task_id', $existing->keys())->exists(), 409, 'The task breakdown is locked after work starts. Ask the HOF to review the assignment if its scope must change.');
            $seen = [];
            foreach ($data['tasks'] as $index => $task) {
                $id = isset($task['id']) ? (int) $task['id'] : null;
                abort_if($id && (! $existing->has($id) || in_array($id, $seen, true)), 422, 'An existing task must belong to this activity and appear only once.');
                if (! $this->employeeEligible((int) $task['assignee_id'], $parent)) {
                    throw ValidationException::withMessages(['tasks' => 'Choose yourself or an active employee reporting to you in this activity’s department.']);
                }
                $attributes = [
                    'title' => $task['title'], 'assignee_id' => (int) $task['assignee_id'],
                    'reviewer_id' => (int) $task['assignee_id'] === $request->user()->id ? $parent->owner_id : $request->user()->id,
                    'weight' => $weights[$index], 'due_date' => $task['due_date'] ?? null, 'instructions' => $task['instructions'] ?? null,
                    'updated_at' => now(),
                ];
                if ($id) {
                    DB::table('delivery_tasks')->where('id', $id)->update([...$attributes, 'version' => $existing[$id]->version + 1]);
                } else {
                    $id = DB::table('delivery_tasks')->insertGetId([...$attributes, 'assignment_id' => $parent->id, 'generation' => $parent->delivery_generation, 'manager_id' => $request->user()->id, 'created_at' => now()]);
                }
                $seen[] = $id;
            }
            DB::table('delivery_tasks')->where('assignment_id', $parent->id)->where('generation', $parent->delivery_generation)->whereNotIn('id', $seen)->update(['is_active' => false, 'updated_at' => now()]);
            $this->rollup($parent);
            $this->audit($request, 'tasks.allocated', 'Activity '.$parent->activity_code, ['tasks' => $data['tasks'], 'generation' => $parent->delivery_generation]);
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
        abort_unless($this->employeeEligible((int) $task->assignee_id, $assignment), 409, 'The employee reporting relationship changed. Contact the manager.');

        return [$task, $assignment];
    }

    public function progress(Request $request, int $task): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'reported_progress' => ['required', 'numeric', 'between:0,100'], 'progress_note' => ['required', 'string', 'max:2000']]);
        DB::transaction(function () use ($request, $data, $task): void {
            [$record, $assignment] = $this->lockTask($task);
            abort_unless((int) $record->assignee_id === $request->user()->id, 403, 'You can update only your own tasks.');
            abort_unless((int) $record->version === (int) $data['version'], 409, 'This task changed. Refresh before saving.');
            abort_if(in_array($record->status, ['submitted', 'approved'], true), 409, 'Submitted or approved work cannot be edited.');
            DB::table('delivery_tasks')->where('id', $task)->update(['reported_progress' => round((float) $data['reported_progress'], 2), 'progress_note' => $data['progress_note'], 'status' => $record->status === 'needs_changes' ? 'needs_changes' : 'in_progress', 'version' => $record->version + 1, 'updated_at' => now()]);
            $this->rollup($assignment);
            $this->audit($request, 'task.progress', 'Task '.$task, $data);
        });

        return response()->json(['message' => 'Reported progress saved. Approval is still required.']);
    }

    public function submit(Request $request, int $task): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'evidence' => ['required', 'string', 'max:5000'], 'evidence_url' => ['nullable', 'url:http,https', 'max:2000']]);
        DB::transaction(function () use ($request, $data, $task): void {
            [$record, $assignment] = $this->lockTask($task);
            abort_unless((int) $record->assignee_id === $request->user()->id, 403, 'You can submit only your own tasks.');
            abort_unless((int) $record->version === (int) $data['version'], 409, 'This task changed. Refresh before submitting.');
            abort_if(in_array($record->status, ['submitted', 'approved'], true), 409, 'This task is already submitted or approved.');
            DB::table('task_submissions')->insert(['task_id' => $task, 'submitted_by' => $request->user()->id, 'evidence' => $data['evidence'], 'evidence_url' => $data['evidence_url'] ?? null, 'submitted_at' => now()]);
            DB::table('delivery_tasks')->where('id', $task)->update(['status' => 'submitted', 'reported_progress' => 100, 'version' => $record->version + 1, 'updated_at' => now()]);
            $this->rollup($assignment);
            $this->audit($request, 'task.submitted', 'Task '.$task, ['evidence' => $data['evidence'], 'evidence_url' => $data['evidence_url'] ?? null]);
        });

        return response()->json(['message' => 'Work submitted for review.']);
    }

    public function review(Request $request, int $submission): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'decision' => ['required', Rule::in(['approved', 'needs_changes'])], 'review_note' => ['required_if:decision,needs_changes', 'nullable', 'string', 'max:2000']]);
        DB::transaction(function () use ($request, $data, $submission): void {
            $reference = DB::table('task_submissions')->find($submission);
            abort_unless($reference, 404);
            [$task, $assignment] = $this->lockTask((int) $reference->task_id);
            abort_unless((int) $task->reviewer_id === $request->user()->id && (int) $task->assignee_id !== $request->user()->id, 403, 'Only the designated reviewer can review this work. Self-approval is not allowed.');
            $latest = DB::table('task_submissions')->where('task_id', $task->id)->orderByDesc('id')->first();
            abort_unless((int) $task->version === (int) $data['version'] && $task->status === 'submitted' && (int) $latest->id === $submission && $latest->decision === 'pending', 409, 'This submission has changed or has already been reviewed.');
            DB::table('task_submissions')->where('id', $submission)->update(['decision' => $data['decision'], 'reviewed_by' => $request->user()->id, 'review_note' => $data['review_note'] ?? null, 'reviewed_at' => now()]);
            DB::table('delivery_tasks')->where('id', $task->id)->update(['status' => $data['decision'], 'version' => $task->version + 1, 'updated_at' => now()]);
            $this->rollup($assignment);
            $this->audit($request, 'task.reviewed', 'Task '.$task->id, ['submission_id' => $submission, ...$data]);
        });

        return response()->json(['message' => 'Review decision saved.']);
    }

    private function rollup(object $assignment): void
    {
        $tasks = $this->currentTasks($assignment);
        $ready = $tasks->isNotEmpty() && abs($tasks->sum('weight') - 1) < 0.00005;
        DB::table('work_assignments')->where('id', $assignment->id)->update([
            'reported_progress' => $ready ? round($tasks->sum(fn ($task) => (float) $task->weight * (float) $task->reported_progress), 2) : 0,
            'confirmed_progress' => $ready ? round($tasks->where('status', 'approved')->sum('weight') * 100, 2) : 0,
            'version' => $assignment->version + 1, 'updated_at' => now(),
        ]);
    }

    private function audit(Request $request, string $action, string $subject, array $after): void
    {
        DB::table('admin_activity')->insert(['actor_id' => $request->user()->id, 'action' => $action, 'subject' => $subject, 'after_data' => json_encode($after), 'created_at' => now()]);
    }
}
