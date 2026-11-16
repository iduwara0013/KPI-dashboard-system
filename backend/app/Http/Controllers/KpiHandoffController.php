<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RequirePlanningAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class KpiHandoffController extends Controller
{
    public function activityEligible(object $assignment): bool
    {
        $handoffs = DB::table('kpi_handoff_activities as a')->join('kpi_handoffs as h', 'h.id', '=', 'a.handoff_id')
            ->join('delivery_tasks as t', 't.id', '=', 'a.task_id')->join('kpi_delegations as d', 'd.id', '=', 'h.root_delegation_id')
            ->leftJoin('department_memberships as sender', function ($join) use ($assignment): void {
                $join->on('sender.user_id', '=', 'h.assigner_id')->where('sender.department_id', $assignment->department_id);
            })->leftJoin('department_memberships as receiver', function ($join) use ($assignment): void {
                $join->on('receiver.user_id', '=', 'h.assignee_id')->where('receiver.department_id', $assignment->department_id);
            })->where('t.assignment_id', $assignment->id)->where('t.generation', $assignment->delivery_generation)->where('t.is_active', true)
            ->get(['h.root_generation', 'd.generation', 'h.root_delegation_id', 'h.assigner_id', 'h.assignee_id', 't.manager_id', 't.assignee_id as task_assignee_id', 't.weight', 'sender.responsibility as sender_role', 'receiver.responsibility as receiver_role']);

        return ! $handoffs->contains(fn ($row) => (int) $row->root_generation !== (int) $row->generation
            || (int) $row->root_delegation_id !== (int) $assignment->delegation_id || $row->sender_role !== 'manager' || $row->receiver_role !== 'manager'
            || (int) $row->assigner_id !== (int) $row->manager_id || (int) $row->assignee_id !== (int) $row->task_assignee_id || abs((float) $row->weight - 1) > 0.00005);
    }

    private function manager(int $user, int $department): bool
    {
        return DB::table('department_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->join('departments as d', 'd.id', '=', 'm.department_id')->where('m.user_id', $user)
            ->where('m.department_id', $department)->where('m.responsibility', 'manager')
            ->where('u.is_active', true)->where('u.system_role', 'member')->where('d.is_active', true)->exists();
    }

    private function rootSummary(object $root): ?array
    {
        $plan = DB::table('performance_plans')->find($root->plan_id);
        if (! $plan) {
            return null;
        }

        return collect(app(KpiAssignmentController::class)->summaries(app(KpiAssignmentController::class)->rowsForPlan($plan, [$root->kpi_code]), collect([$root])))
            ->firstWhere('kpi_code', $root->kpi_code);
    }

    private function mappedTasks(int $handoff): Collection
    {
        return DB::table('kpi_handoff_activities as h')->join('delivery_tasks as t', 't.id', '=', 'h.task_id')
            ->where('h.handoff_id', $handoff)->get(['t.*'])->keyBy('assignment_id');
    }

    private function validHandoff(object $handoff, array $root, array $seen = []): bool
    {
        $delegation = $root['delegation'];
        if (in_array((int) $handoff->id, $seen, true) || ! $root['valid'] || (int) $handoff->root_generation !== (int) $delegation->generation
            || ! $this->manager((int) $handoff->assignee_id, $root['department_id'])
            || ! $this->manager((int) $handoff->assigner_id, $root['department_id'])
            || ! DB::table('reporting_relationships')->where('user_id', $handoff->assignee_id)->where('manager_id', $handoff->assigner_id)->where('department_id', $root['department_id'])->exists()) {
            return false;
        }
        $previousTasks = collect();
        if ($handoff->parent_id !== null) {
            $previous = DB::table('kpi_handoffs')->find($handoff->parent_id);
            if (! $previous || (int) $previous->id >= (int) $handoff->id || (int) $previous->root_delegation_id !== (int) $delegation->id
                || (int) $previous->assignee_id !== (int) $handoff->assigner_id || ! $this->validHandoff($previous, $root, [...$seen, (int) $handoff->id])) {
                return false;
            }
            $previousTasks = $this->mappedTasks((int) $previous->id);
        } elseif ((int) $delegation->assignee_id !== (int) $handoff->assigner_id) {
            return false;
        }
        $tasks = $this->mappedTasks((int) $handoff->id);
        if ($tasks->count() !== count($root['activities'])) {
            return false;
        }
        foreach ($root['activities'] as $row) {
            $assignment = $row['assignment'];
            $task = $assignment ? $tasks->get($assignment->id) : null;
            if (! $assignment || ! $task || $assignment->ownership_mode !== 'kpi' || (int) $assignment->assignee_id !== (int) $delegation->assignee_id
                || (int) $assignment->delegation_id !== (int) $delegation->id || (int) $task->assignee_id !== (int) $handoff->assignee_id
                || (int) $task->manager_id !== (int) $handoff->assigner_id || abs((float) $task->weight - 1) > 0.00005
                || $task->parent_task_id != $previousTasks->get($assignment->id)?->id
                || ! app(DeliveryController::class)->context($assignment)['valid'] || ! app(DeliveryController::class)->taskEligible($task, $assignment)) {
                return false;
            }
        }

        return true;
    }

    private function summary(object $handoff): ?array
    {
        $reference = DB::table('kpi_delegations')->find($handoff->root_delegation_id);
        $root = $reference ? $this->rootSummary($reference) : null;
        if (! $root) {
            return null;
        }
        $valid = $this->validHandoff($handoff, $root);
        $tasks = $this->mappedTasks((int) $handoff->id);
        $activities = array_map(function (array $row) use ($tasks, $valid): array {
            $assignment = $row['assignment'];
            $task = $assignment ? $tasks->get($assignment->id) : null;

            return [...$row, 'work_assignment_id' => $assignment?->id, 'handoff_task_id' => $task?->id,
                'assignment' => $task ? (object) [...(array) $task, 'ownership_mode' => 'kpi', 'confirmed_progress' => $valid ? app(WorkChangeController::class)->effectiveProgress($task) : 0] : null,
                'timeliness' => $task && $valid ? (app(TimelinessController::class)->forAssignment($assignment)['tasks'][$task->id] ?? null) : null];
        }, $root['activities']);
        $summary = [...$root, 'activities' => $activities, 'valid' => $valid, 'handoff_kind' => 'handoff', 'source_id' => (int) $handoff->id,
            'link_key' => 'handoff:'.$handoff->id, 'allocation_key' => 'handoff:'.$handoff->id.':'.$handoff->root_generation,
            'source_signature' => hash('sha256', $root['source_signature'].':'.$handoff->id.':'.$handoff->root_generation.':'.$handoff->version),
            'delegation' => (object) ['id' => $handoff->id, 'owner_id' => $handoff->assigner_id, 'assignee_id' => $handoff->assignee_id,
                'generation' => $handoff->root_generation, 'version' => $handoff->version, 'recipient_role' => 'manager', 'root_id' => $reference->id],
            'confirmed_progress' => round(collect($activities)->sum(fn ($row) => $row['activity_weight'] * ($row['assignment']->confirmed_progress ?? 0)), 4),
            'timeliness' => app(TimelinessController::class)->combine(array_map(fn ($row) => ['weight' => $row['activity_weight'], 'timeliness' => $row['timeliness']], $activities))];

        return $this->decorate($summary);
    }

    public function forRecipient(int $user): array
    {
        return DB::table('kpi_handoffs as h')->join('kpi_delegations as d', 'd.id', '=', 'h.root_delegation_id')
            ->where('h.assignee_id', $user)->whereColumn('h.root_generation', 'd.generation')->orderBy('h.id')->get(['h.*'])
            ->map(fn ($handoff) => $this->summary($handoff))->filter()->values()->all();
    }

    public function decorate(array $summary): array
    {
        $kind = $summary['handoff_kind'] ?? 'root';
        $rootId = (int) ($summary['delegation']->root_id ?? $summary['delegation']->id);
        $forwarded = DB::table('kpi_handoffs')->where('root_delegation_id', $rootId)
            ->where('root_generation', $summary['delegation']->generation)->where('assigner_id', $summary['delegation']->assignee_id)->first();
        $reason = $this->unavailableReason($summary, $forwarded);
        $forwardedTask = $forwarded ? $this->mappedTasks((int) $forwarded->id)->first() : null;

        return [...$summary, 'handoff_kind' => $kind, 'source_id' => $summary['source_id'] ?? (int) $summary['delegation']->id,
            'link_key' => $summary['link_key'] ?? 'kpi:'.$summary['delegation']->id,
            'can_forward' => $reason === null, 'forward_reason' => $reason, 'forwarded_to_id' => $forwarded?->assignee_id,
            'forwarded_handoff_id' => $forwarded?->id, 'forwarded_version' => $forwarded?->version,
            'forwarded_due_date' => $forwardedTask?->due_date, 'forwarded_instructions' => $forwardedTask?->instructions,
            'can_edit_handoff' => $forwarded && $this->untouched($forwarded)];
    }

    private function untouched(object $handoff): bool
    {
        $tasks = $this->mappedTasks((int) $handoff->id);

        return $tasks->isNotEmpty() && ! DB::table('kpi_handoffs')->where('parent_id', $handoff->id)->exists()
            && ! $tasks->contains(fn ($task) => ! $task->is_active || (float) $task->reported_progress > 0 || (float) $task->confirmed_progress > 0 || in_array($task->status, ['submitted', 'approved', 'needs_changes'], true))
            && ! DB::table('delivery_tasks')->whereIn('parent_task_id', $tasks->pluck('id'))->where('is_active', true)->exists()
            && ! DB::table('task_submissions')->whereIn('task_id', $tasks->pluck('id'))->exists()
            && ! DB::table('work_change_requests')->whereIn('task_id', $tasks->pluck('id'))->exists()
            && ! DB::table('work_replacements')->whereIn('task_id', $tasks->pluck('id'))->exists();
    }

    public function change(Request $request, int $handoff): JsonResponse
    {
        $data = $request->validate(['mode' => ['required', Rule::in(['edit', 'cancel'])], 'version' => ['required', 'integer', 'min:1'],
            'assignee_id' => ['required_if:mode,edit', 'integer'], 'due_date' => ['nullable', 'date_format:Y-m-d'], 'instructions' => ['nullable', 'string', 'max:1000']]);
        DB::transaction(function () use ($request, $handoff, $data): void {
            $reference = DB::table('kpi_handoffs')->find($handoff);
            abort_unless($reference, 404);
            $root = DB::table('kpi_delegations')->find($reference->root_delegation_id);
            abort_unless($root, 409);
            DB::table('performance_plans')->where('id', $root->plan_id)->lockForUpdate()->first();
            $root = DB::table('kpi_delegations')->where('id', $root->id)->lockForUpdate()->first();
            $record = DB::table('kpi_handoffs')->where('id', $handoff)->lockForUpdate()->first();
            abort_unless($record, 404);
            abort_unless(((int) $record->assigner_id === $request->user()->id && $this->manager($request->user()->id, (int) $root->department_id))
                || RequirePlanningAccess::canManageWork($request->user(), (int) $root->plan_id, (int) $root->department_id), 403);
            abort_unless((int) $record->version === (int) $data['version'] && (int) $record->root_generation === (int) $root->generation, 409, 'This KPI assignment changed. Refresh before saving.');
            abort_unless($this->untouched($record), 409, 'This KPI already has started or delegated work. Its history is protected.');
            $tasks = $this->mappedTasks($handoff);
            $before = ['handoff' => $record, 'tasks' => $tasks->values()->all()];
            if ($data['mode'] === 'edit') {
                $assignee = (int) $data['assignee_id'];
                abort_unless($this->manager($assignee, (int) $root->department_id) && DB::table('reporting_relationships')->where('manager_id', $record->assigner_id)->where('user_id', $assignee)->where('department_id', $root->department_id)->exists(), 422, 'Choose a manager reporting directly to the assigning manager.');
                $owners = [(int) $root->owner_id, (int) $root->assignee_id, (int) $record->assigner_id];
                $parent = $record->parent_id ? DB::table('kpi_handoffs')->find($record->parent_id) : null;
                while ($parent) {
                    $owners[] = (int) $parent->assignee_id;
                    $parent = $parent->parent_id ? DB::table('kpi_handoffs')->find($parent->parent_id) : null;
                }
                abort_if(in_array($assignee, $owners, true), 422, 'A KPI cannot be returned to an earlier owner.');
                DB::table('kpi_handoffs')->where('id', $handoff)->update(['assignee_id' => $assignee, 'version' => $record->version + 1, 'updated_at' => now()]);
                foreach ($tasks as $task) {
                    DB::table('delivery_tasks')->where('id', $task->id)->update(['assignee_id' => $assignee, 'due_date' => $data['due_date'] ?? null,
                        'instructions' => $data['instructions'] ?? null, 'version' => $task->version + 1, 'updated_at' => now()]);
                }
                NotificationController::send($assignee, 'kpi-handoff:'.$handoff.':'.($record->version + 1), 'assignment', 'KPI assignment updated', ['workspace' => 'delivery', 'page' => 'kpis', 'handoff_id' => $handoff]);
            } else {
                DB::table('delivery_tasks')->whereIn('id', $tasks->pluck('id'))->update(['is_active' => false, 'updated_at' => now()]);
                DB::table('kpi_handoff_activities')->where('handoff_id', $handoff)->delete();
                DB::table('kpi_handoffs')->where('id', $handoff)->delete();
                foreach ($tasks->pluck('parent_task_id')->filter()->unique() as $parentId) {
                    DB::table('delivery_tasks')->where('id', $parentId)->update(['status' => 'assigned', 'reported_progress' => 0, 'confirmed_progress' => 0, 'updated_at' => now()]);
                }
            }
            foreach ($tasks->keys() as $assignmentId) {
                app(DeliveryController::class)->rollup(DB::table('work_assignments')->find($assignmentId));
            }
            DB::table('admin_activity')->insert(['actor_id' => $request->user()->id, 'action' => 'kpi.handoff.'.$data['mode'], 'subject' => 'KPI handoff '.$handoff,
                'before_data' => json_encode($before), 'after_data' => json_encode($data), 'created_at' => now()]);
            NotificationController::send((int) $record->assignee_id, 'kpi-handoff-change:'.$handoff.':'.($record->version + 1), 'assignment', 'KPI assignment '.$data['mode'].' recorded', ['workspace' => 'delivery', 'page' => 'kpis']);
        });

        return response()->json(['message' => $data['mode'] === 'cancel' ? 'KPI handoff cancelled. Activities returned to the assigning manager.' : 'KPI handoff updated.']);
    }

    private function unavailableReason(array $summary, ?object $forwarded): ?string
    {
        if (! $summary['valid'] || ! $this->manager((int) $summary['delegation']->assignee_id, $summary['department_id'])) {
            return 'A current KPI assignment and manager access in this department are required.';
        }
        if ($forwarded) {
            return 'This full KPI has already been assigned to the next manager.';
        }
        foreach ($summary['activities'] as $row) {
            $assignment = $row['assignment'];
            if (! $assignment || $assignment->ownership_mode !== 'kpi' || (int) $assignment->assignee_id !== (int) $summary['delegation']->assignee_id) {
                return 'Some activities have individual owners. Ask the HOF to review those assignments first.';
            }
            $taskId = $row['handoff_task_id'] ?? null;
            $assignmentId = $row['work_assignment_id'] ?? $assignment->id;
            $work = DB::table('work_assignments')->find($assignmentId);
            if (! $work || ! app(DeliveryController::class)->context($work)['valid']
                || DB::table('delivery_tasks')->where('assignment_id', $work->id)->where('generation', $work->delivery_generation)->where('is_active', true)->where('parent_task_id', $taskId)->exists()
                || $taskId && ((float) $assignment->reported_progress > 0 || DB::table('task_submissions')->where('task_id', $taskId)->exists() || DB::table('work_replacements')->where('task_id', $taskId)->exists())) {
                return 'This KPI has existing task breakdowns or started work. Continue through its activities and tasks.';
            }
        }

        return null;
    }

    public function assign(Request $request): JsonResponse
    {
        $data = $request->validate(['source_kind' => ['required', Rule::in(['root', 'handoff'])], 'source_id' => ['required', 'integer', 'min:1'],
            'version' => ['required', 'integer', 'min:1'], 'assignee_id' => ['required', 'integer', 'min:1'],
            'due_date' => ['nullable', 'date_format:Y-m-d'], 'instructions' => ['nullable', 'string', 'max:1000']]);
        DB::transaction(function () use ($request, $data): void {
            $source = DB::table($data['source_kind'] === 'root' ? 'kpi_delegations' : 'kpi_handoffs')->find($data['source_id']);
            abort_unless($source && (int) $source->assignee_id === $request->user()->id, 403);
            $root = $data['source_kind'] === 'root' ? $source : DB::table('kpi_delegations')->find($source->root_delegation_id);
            abort_unless($root, 409, 'The original KPI assignment is no longer available.');
            DB::table('performance_plans')->where('id', $root->plan_id)->lockForUpdate()->first();
            $root = DB::table('kpi_delegations')->where('id', $root->id)->lockForUpdate()->first();
            $source = $data['source_kind'] === 'root' ? $root : DB::table('kpi_handoffs')->where('id', $source->id)->lockForUpdate()->first();
            abort_unless($source && (int) $source->assignee_id === $request->user()->id, 403);
            $summary = $data['source_kind'] === 'root' ? $this->rootSummary($root) : $this->summary($source);
            abort_unless($summary && $summary['valid'], 409, 'The KPI or reporting relationship changed. Refresh before assigning it.');
            abort_unless((int) $source->version === (int) $data['version'], 409, 'This KPI assignment changed. Refresh before saving.');
            $summary = $this->decorate($summary);
            abort_unless($summary['can_forward'], 409, $summary['forward_reason']);
            $actor = $request->user()->id;
            $assignee = (int) $data['assignee_id'];
            abort_unless($this->manager($assignee, $summary['department_id']) && DB::table('reporting_relationships')
                ->where('user_id', $assignee)->where('manager_id', $actor)->where('department_id', $summary['department_id'])->exists(), 422, 'Choose an active manager reporting directly to you in this department.');
            $ancestor = $data['source_kind'] === 'handoff' ? $source : null;
            $previousOwners = [(int) $root->owner_id, (int) $root->assignee_id, $actor];
            while ($ancestor) {
                $previousOwners[] = (int) $ancestor->assignee_id;
                $ancestor = $ancestor->parent_id ? DB::table('kpi_handoffs')->find($ancestor->parent_id) : null;
            }
            abort_if(in_array($assignee, $previousOwners, true), 422, 'A KPI cannot be assigned back to an earlier owner.');
            $id = DB::table('kpi_handoffs')->insertGetId(['root_delegation_id' => $root->id, 'root_generation' => $root->generation,
                'parent_id' => $data['source_kind'] === 'handoff' ? $source->id : null, 'assigner_id' => $actor, 'assignee_id' => $assignee, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($summary['activities'] as $row) {
                $taskId = $row['handoff_task_id'] ?? null;
                $assignmentId = $row['work_assignment_id'] ?? $row['assignment']->id;
                $assignment = DB::table('work_assignments')->find($assignmentId);
                $scope = $taskId ? DB::table('delivery_tasks')->find($taskId) : $assignment;
                $payload = ['version' => (int) $scope->version, 'tasks' => [['work_kind' => 'activity', 'title' => $row['activity'], 'assignee_id' => $assignee, 'weight' => 1,
                    'due_date' => array_key_exists('due_date', $data) ? $data['due_date'] : ($scope->due_date ? substr((string) $scope->due_date, 0, 10) : null), 'instructions' => $data['instructions'] ?? null]]];
                $branchRequest = Request::create('/api/delivery/kpi-handoffs', 'PUT', $payload);
                $branchRequest->setUserResolver($request->getUserResolver());
                $branchRequest->attributes->set('full_kpi_forward', true);
                if ($taskId) {
                    app(DeliveryController::class)->breakdownTask($branchRequest, $taskId);
                } else {
                    app(DeliveryController::class)->breakdown($branchRequest, (int) $assignment->id);
                }
                $created = DB::table('delivery_tasks')->where('assignment_id', $assignment->id)->where('generation', $assignment->delivery_generation)
                    ->where('parent_task_id', $taskId)->where('is_active', true)->sole();
                DB::table('kpi_handoff_activities')->insert(['handoff_id' => $id, 'task_id' => $created->id]);
            }
            NotificationController::send($assignee, 'kpi-handoff:'.$id, 'assignment', 'KPI assigned: '.$summary['kpi_title'], ['workspace' => 'delivery', 'page' => 'kpis', 'search' => $summary['kpi_title']]);
            DB::table('admin_activity')->insert(['actor_id' => $actor, 'action' => 'kpi.forwarded', 'subject' => $summary['kpi_code'],
                'after_data' => json_encode(['handoff_id' => $id, 'root_delegation_id' => $root->id, 'assignee_id' => $assignee, 'activity_count' => count($summary['activities'])]), 'created_at' => now()]);
        });

        return response()->json(['message' => 'Full KPI assigned. It now appears as the next manager’s linked KPA.']);
    }
}
