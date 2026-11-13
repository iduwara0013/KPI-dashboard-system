<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RequirePlanningAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WorkChangeController extends Controller
{
    public function notificationState(int $change, int $user): array
    {
        $row = DB::table('work_change_requests')->find($change);
        $available = $row && in_array($user, [(int) $row->requested_by, (int) $row->assignee_id, (int) $row->reviewer_id], true)
            && DB::table('department_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')->join('departments as d', 'd.id', '=', 'm.department_id')
                ->where('m.user_id', $user)->where('m.department_id', $row->department_id)->where('u.is_active', true)->where('d.is_active', true)->exists();
        $task = $available ? DB::table('delivery_tasks')->find($row->task_id) : null;
        $assignment = $task ? DB::table('work_assignments')->find($task->assignment_id) : null;
        $required = $available && $row->decision === 'pending' && (int) $row->reviewer_id === $user && $assignment && $this->current($task, $assignment)
            && (int) $task->reviewer_id === $user && (int) $task->assignee_id === (int) $row->assignee_id;

        return ['available' => (bool) $available, 'required' => (bool) $required, 'state' => $row->decision ?? 'Unavailable'];
    }

    public function replacement(int $task): ?object
    {
        return DB::table('work_replacements as r')->join('extra_work_assignments as e', 'e.id', '=', 'r.extra_assignment_id')
            ->where('r.task_id', $task)->first(['r.*', 'e.title as extra_title', 'e.confirmed_progress as extra_confirmed_progress', 'e.reported_progress as extra_reported_progress']);
    }

    public function fullyReplaced(int $task): bool
    {
        return (float) ($this->replacement($task)?->fraction ?? 0) >= 1;
    }

    public function displayStatus(object $task): string
    {
        $replacement = $this->replacement((int) $task->id);
        if (! $replacement) {
            return $task->status;
        }

        return $this->effectiveProgress($task) >= 100 ? 'approved' : ((float) $replacement->fraction >= 1 ? 'replaced' : ($task->status === 'approved' ? 'in_progress' : $task->status));
    }

    public function overdue(object $task, object $assignment): bool
    {
        $replacement = $this->replacement((int) $task->id);
        $today = now('Asia/Colombo')->toDateString();
        $due = app(TimelinessController::class)->effectiveDue($task, $assignment);
        if ((! $replacement || (float) $replacement->fraction < 1) && (float) $task->confirmed_progress < 100 && $due && $due < $today) {
            return true;
        }
        $extra = $replacement ? DB::table('extra_work_assignments')->find($replacement->extra_assignment_id) : null;

        return $extra && (float) $extra->confirmed_progress < 100 && $extra->due_date && substr((string) $extra->due_date, 0, 10) < $today;
    }

    public function effectiveProgress(object $task, string $field = 'confirmed_progress'): float
    {
        $replacement = $this->replacement((int) $task->id);
        if (! $replacement) {
            return (float) $task->$field;
        }
        $extra = DB::table('extra_work_assignments')->find($replacement->extra_assignment_id);
        $valid = $extra && app(ExtraWorkController::class)->assignmentValid($extra);

        return round((1 - $replacement->fraction) * (float) $task->$field + $replacement->fraction * ($valid ? (float) $extra->$field : 0), 2);
    }

    public function replacementTiming(int $task, array $original): array
    {
        $replacement = $this->replacement($task);
        if (! $replacement) {
            return $original;
        }
        $extra = DB::table('extra_work_assignments')->find($replacement->extra_assignment_id);
        $valid = $extra && app(ExtraWorkController::class)->assignmentValid($extra);
        $timing = $valid ? app(ExtraWorkController::class)->assignmentTiming($extra)['summary'] : null;

        return [...app(TimelinessController::class)->combine([['weight' => 1 - (float) $replacement->fraction, 'timeliness' => $original], ['weight' => (float) $replacement->fraction, 'timeliness' => $timing]]), 'replacement' => true,
            'due_date' => (float) $replacement->fraction >= 1 ? ($extra?->due_date ? substr((string) $extra->due_date, 0, 10) : null) : ($original['due_date'] ?? null)];
    }

    public function reservedExtra(int $extra): bool
    {
        return DB::table('work_replacements')->where('extra_assignment_id', $extra)->exists();
    }

    /** Called after the extra-work transaction commits, avoiding inverse plan/extra lock ordering. */
    public function refreshForExtra(int $extra): void
    {
        $link = DB::table('work_replacements')->where('extra_assignment_id', $extra)->first();
        if (! $link) {
            return;
        }
        DB::transaction(function () use ($link): void {
            $task = DB::table('delivery_tasks')->find($link->task_id);
            $reference = $task ? DB::table('work_assignments')->find($task->assignment_id) : null;
            if (! $reference) {
                return;
            }
            DB::table('performance_plans')->where('id', $reference->plan_id)->lockForUpdate()->first();
            $assignment = DB::table('work_assignments')->where('id', $reference->id)->lockForUpdate()->first();
            if ($assignment && app(DeliveryController::class)->context($assignment)['valid']) {
                app(DeliveryController::class)->rollup($assignment);
            }
        });
    }

    private function scope(Request $request): array
    {
        $user = $request->user();
        abort_unless($user?->is_active && in_array($user->system_role, ['member', 'md', 'plan_editor'], true), 403);

        return $user->system_role === 'md' ? DB::table('departments')->where('is_active', true)->pluck('id')->all() : RequirePlanningAccess::departmentIds($user)->all();
    }

    private function current(object $task, object $assignment): bool
    {
        return app(DeliveryController::class)->context($assignment)['valid'] && app(DeliveryController::class)->taskEligible($task, $assignment);
    }

    private function eligibleLeaf(object $task): bool
    {
        return ! in_array($task->status, ['approved', 'submitted'], true) && ! DB::table('delivery_tasks')->where('parent_task_id', $task->id)->where('is_active', true)->exists()
            && ! DB::table('work_replacements')->where('task_id', $task->id)->exists();
    }

    private function extraMatches(object $task, object $assignment, object $extra): bool
    {
        $plan = DB::table('performance_plans')->find($assignment->plan_id);

        return (int) $extra->assignee_id === (int) $task->assignee_id && (int) $extra->department_id === (int) $assignment->department_id
            && (int) $extra->year === (int) $plan->year && app(ExtraWorkController::class)->assignmentValid($extra);
    }

    private function audit(Request $request, string $action, int $id, array $snapshot): void
    {
        DB::table('admin_activity')->insert(['actor_id' => $request->user()->id, 'action' => 'work_change.'.$action, 'subject' => 'Work change request '.$id, 'after_data' => json_encode($snapshot), 'created_at' => now()]);
    }

    private function snapshot(object $task, object $assignment, object $extra): array
    {
        $activity = app(DeliveryController::class)->context($assignment)['activity'];
        $share = (float) $task->weight;
        $ancestor = $task;
        while ($ancestor->parent_task_id !== null) {
            $parent = DB::table('delivery_tasks')->find($ancestor->parent_task_id);
            if (! $parent || (int) $parent->id >= (int) $ancestor->id) {
                break;
            }
            $share *= (float) $parent->weight;
            $ancestor = $parent;
        }

        return ['original' => (array) $task, 'assignment' => (array) $assignment, 'extra' => (array) $extra, 'activity' => $activity,
            'source_plan_share' => (float) ($activity['personal_weight'] ?? 0) * $share];
    }

    private function notify(int $user, int $id, string $event, string $message): void
    {
        NotificationController::send($user, 'work-change:'.$id.':'.$event, $event === 'requested' ? 'submission' : 'assignment', $message,
            ['workspace' => 'extra', 'page' => 'changes', 'work_change_id' => $id]);
    }

    public function workspace(Request $request): JsonResponse
    {
        $scope = $this->scope($request);
        $user = $request->user();
        $readOnly = $user->system_role !== 'member';
        $hof = DB::table('department_memberships')->where('user_id', $user->id)->where('responsibility', 'hof')->pluck('department_id');
        $assignments = DB::table('work_assignments')->whereIn('department_id', $scope)->get()->keyBy('id');
        $options = [];
        foreach (DB::table('delivery_tasks')->whereIntegerInRaw('assignment_id', $assignments->keys()->all())->where('is_active', true)->get() as $task) {
            $assignment = $assignments->get($task->assignment_id);
            if (! $readOnly && ((int) $task->assignee_id === (int) $user->id || (int) $task->reviewer_id === (int) $user->id) && $this->current($task, $assignment) && $this->eligibleLeaf($task)) {
                $plan = DB::table('performance_plans')->find($assignment->plan_id);
                $options[] = [...(array) $task, 'department_id' => $assignment->department_id, 'year' => $plan->year];
            }
        }
        $requests = DB::table('work_change_requests')->whereIn('department_id', $scope)->when(! $readOnly, fn ($query) => $query->where(fn ($query) => $query->where('assignee_id', $user->id)->orWhere('reviewer_id', $user->id)->orWhere('requested_by', $user->id)->orWhereIn('department_id', $hof)))->orderByDesc('id')->get();
        $rows = $requests->map(function ($row) use ($user, $readOnly): array {
            $task = DB::table('delivery_tasks')->find($row->task_id);
            $assignment = $task ? DB::table('work_assignments')->find($task->assignment_id) : null;
            $extra = DB::table('extra_work_assignments')->find($row->extra_assignment_id);
            $current = $task && $assignment && $extra && $this->current($task, $assignment) && (int) $task->assignee_id === (int) $row->assignee_id && (int) $task->reviewer_id === (int) $row->reviewer_id;

            return [...(array) $row, 'task_title' => json_decode($row->snapshot, true)['original']['title'], 'extra_title' => $extra?->title,
                'assignee_name' => DB::table('users')->where('id', $row->assignee_id)->value('name'), 'reviewer_name' => DB::table('users')->where('id', $row->reviewer_id)->value('name'),
                'task_version' => $task?->version, 'extra_version' => $extra?->version, 'can_decide' => ! $readOnly && $current && $row->decision === 'pending' && (int) $row->reviewer_id === (int) $user->id,
                'can_cancel' => ! $readOnly && $row->decision === 'pending' && (int) $row->requested_by === (int) $user->id,
                'approved_fraction' => DB::table('work_replacements')->where('request_id', $row->id)->value('fraction')];
        });
        $extras = DB::table('extra_work_assignments')->whereIn('department_id', $scope)->whereIn('assignee_id', array_unique(array_column($options, 'assignee_id')))->get()
            ->filter(fn ($extra) => app(ExtraWorkController::class)->assignmentValid($extra) && ! $this->reservedExtra((int) $extra->id))->values();
        $reporters = DB::table('reporting_relationships as r')->join('users as u', 'u.id', '=', 'r.user_id')->where('r.manager_id', $user->id)->whereIn('r.department_id', $scope)->where('u.is_active', true)->where('u.system_role', 'member')->get(['u.id', 'u.name', 'r.department_id']);

        return response()->json(['requests' => $rows, 'original_tasks' => $options, 'extra_assignments' => $extras, 'reassignment_people' => $reporters,
            'read_only' => $readOnly, 'departments' => DB::table('departments')->whereIn('id', $scope)->get(['id', 'name']), 'people' => DB::table('users')->whereIn('id', array_unique(array_column($options, 'assignee_id')))->get(['id', 'name'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $scope = $this->scope($request);
        abort_unless($request->user()->system_role === 'member', 403);
        $data = $request->validate(['task_id' => ['required', 'integer'], 'extra_assignment_id' => ['required', 'integer'], 'task_version' => ['required', 'integer'], 'requested_fraction' => ['required', 'numeric', 'between:0.0001,1'], 'reason' => ['required', 'string', 'max:2000']]);
        $id = DB::transaction(function () use ($request, $data, $scope): int {
            [$task, $assignment] = app(DeliveryController::class)->lockTask((int) $data['task_id']);
            abort_unless(in_array((int) $assignment->department_id, $scope), 403);
            abort_unless(in_array((int) $request->user()->id, [(int) $task->assignee_id, (int) $task->reviewer_id], true), 403);
            abort_unless((int) $task->version === (int) $data['task_version'] && $this->eligibleLeaf($task), 409, 'Choose a current unfinished leaf task.');
            abort_if(DB::table('work_change_requests')->where('task_id', $task->id)->where('decision', 'pending')->exists(), 409, 'A work change request is already awaiting review for this task.');
            $extra = DB::table('extra_work_assignments')->where('id', $data['extra_assignment_id'])->lockForUpdate()->first();
            abort_unless($extra && $this->extraMatches($task, $assignment, $extra) && ! $this->reservedExtra((int) $extra->id), 422, 'Choose unreserved extra work for the same person, department and year.');
            $plan = DB::table('performance_plans')->find($assignment->plan_id);
            $id = DB::table('work_change_requests')->insertGetId(['task_id' => $task->id, 'extra_assignment_id' => $extra->id, 'requested_by' => $request->user()->id,
                'reviewer_id' => $task->reviewer_id, 'assignee_id' => $task->assignee_id, 'department_id' => $assignment->department_id, 'year' => $plan->year,
                'requested_fraction' => round((float) $data['requested_fraction'], 4), 'reason' => $data['reason'], 'snapshot' => json_encode($this->snapshot($task, $assignment, $extra)), 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($request, 'requested', $id, $data);
            $this->notify((int) $task->reviewer_id, $id, 'requested', 'Work change needs your decision: '.$task->title);

            return $id;
        });

        return response()->json(['id' => $id, 'message' => 'Work change requested. Both responsibilities remain active until a decision is approved.'], 201);
    }

    public function decide(Request $request, int $change): JsonResponse
    {
        $scope = $this->scope($request);
        abort_unless($request->user()->system_role === 'member', 403);
        $data = $request->validate(['version' => ['required', 'integer'], 'task_version' => ['required', 'integer'], 'extra_version' => ['required', 'integer'],
            'decision' => ['required', Rule::in(['replace', 'continue_both', 'extend_deadline', 'reassign', 'rejected'])], 'decision_note' => ['required', 'string', 'max:2000'],
            'fraction' => ['required_if:decision,replace', 'nullable', 'numeric', 'between:0.0001,1'], 'due_date' => ['required_if:decision,extend_deadline', 'nullable', 'date_format:Y-m-d'], 'assignee_id' => ['required_if:decision,reassign', 'nullable', 'integer']]);
        DB::transaction(function () use ($request, $change, $data, $scope): void {
            $reference = DB::table('work_change_requests')->find($change);
            abort_unless($reference, 404);
            [$task, $assignment] = app(DeliveryController::class)->lockTask((int) $reference->task_id);
            abort_unless(in_array((int) $assignment->department_id, $scope), 403);
            $record = DB::table('work_change_requests')->where('id', $change)->lockForUpdate()->first();
            abort_unless((int) $task->reviewer_id === (int) $request->user()->id && (int) $record->reviewer_id === (int) $request->user()->id && (int) $record->assignee_id === (int) $task->assignee_id, 403, 'Only the original task reviewer can decide this request.');
            abort_unless((int) $task->assignee_id !== (int) $request->user()->id || app(DeliveryController::class)->selfConfirmable($task, $assignment), 403, 'Self-approval is available only for HOF own work.');
            abort_unless($record->decision === 'pending' && (int) $record->version === (int) $data['version'] && (int) $task->version === (int) $data['task_version'], 409, 'This request or task changed. Refresh before deciding.');
            $extra = DB::table('extra_work_assignments')->where('id', $record->extra_assignment_id)->lockForUpdate()->first();
            abort_unless($extra && (int) $extra->version === (int) $data['extra_version'], 409, 'The extra work changed. Refresh before deciding.');
            $before = $this->snapshot($task, $assignment, $extra);
            if (! in_array($data['decision'], ['continue_both', 'rejected'], true)) {
                abort_unless($this->eligibleLeaf($task) && $this->extraMatches($task, $assignment, $extra), 409, 'The work, person or reporting scope changed. Reject this request and create a current one.');
            }
            if ($data['decision'] === 'replace') {
                abort_if($this->reservedExtra((int) $extra->id), 409, 'This extra work already replaces another responsibility.');
                DB::table('work_replacements')->insert(['request_id' => $change, 'task_id' => $task->id, 'extra_assignment_id' => $extra->id, 'fraction' => round((float) $data['fraction'], 4), 'created_at' => now()]);
                DB::table('delivery_tasks')->where('id', $task->id)->increment('version');
                app(DeliveryController::class)->rollup($assignment);
            } elseif ($data['decision'] === 'extend_deadline') {
                $request->merge(['version' => $task->version, 'due_date' => $data['due_date'], 'reason' => $data['decision_note']]);
                app(DeliveryController::class)->extendDeadline($request, (int) $task->id);
            } elseif ($data['decision'] === 'reassign') {
                abort_if((float) $task->reported_progress > 0 || DB::table('task_submissions')->where('task_id', $task->id)->exists(), 409, 'Only original tasks that have not started can be reassigned.');
                $assignee = (int) $data['assignee_id'];
                $candidate = (object) [...(array) $task, 'assignee_id' => $assignee];
                abort_unless($assignee !== (int) $task->assignee_id && $assignee !== (int) $task->reviewer_id && app(DeliveryController::class)->taskEligible($candidate, $assignment)
                    && DB::table('reporting_relationships')->where('user_id', $assignee)->where('manager_id', $task->reviewer_id)->where('department_id', $assignment->department_id)->exists(), 422, 'Choose an eligible direct report in this task’s reporting branch.');
                DB::table('delivery_tasks')->where('id', $task->id)->update(['assignee_id' => $assignee, 'version' => $task->version + 1, 'updated_at' => now()]);
                app(DeliveryController::class)->rollup($assignment);
                NotificationController::send($assignee, 'work-reassigned:'.$change, 'assignment', 'Work reassigned to you: '.$task->title, ['workspace' => 'delivery', 'page' => 'tasks', 'task_id' => $task->id]);
            }
            $snapshot = [...json_decode($record->snapshot, true), 'decision' => ['before' => $before, 'details' => $data, 'after_original' => DB::table('delivery_tasks')->find($task->id), 'after_assignment' => DB::table('work_assignments')->find($assignment->id)]];
            DB::table('work_change_requests')->where('id', $change)->update(['decision' => $data['decision'], 'decision_note' => $data['decision_note'], 'snapshot' => json_encode($snapshot), 'decided_at' => now(), 'version' => $record->version + 1, 'updated_at' => now()]);
            $this->audit($request, 'decided', $change, $snapshot['decision']);
            $this->notify((int) $record->assignee_id, $change, 'decided', 'Work change decision: '.str_replace('_', ' ', $data['decision']).' · '.$task->title);
        });

        return response()->json(['message' => 'Work change decision saved. KPI outcome targets remain unchanged.']);
    }

    public function cancel(Request $request, int $change): JsonResponse
    {
        $scope = $this->scope($request);
        DB::transaction(function () use ($request, $change, $scope): void {
            $record = DB::table('work_change_requests')->where('id', $change)->lockForUpdate()->first();
            abort_unless($record && in_array((int) $record->department_id, $scope) && (int) $record->requested_by === (int) $request->user()->id && $request->user()->system_role === 'member', 403);
            abort_unless($record->decision === 'pending', 409, 'Only pending requests can be withdrawn.');
            DB::table('work_change_requests')->where('id', $change)->update(['decision' => 'cancelled', 'decided_at' => now(), 'version' => $record->version + 1, 'updated_at' => now()]);
            $this->audit($request, 'cancelled', $change, []);
        });

        return response()->json(['message' => 'Request withdrawn. Both responsibilities remain active.']);
    }
}
