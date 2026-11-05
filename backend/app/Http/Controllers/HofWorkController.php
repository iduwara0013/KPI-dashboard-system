<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HofWorkController extends Controller
{
    private function departments(Request $request): array
    {
        abort_unless($request->user()?->is_active && $request->user()->system_role === 'member', 403, 'An active HOF account is required.');
        $ids = DB::table('department_memberships')->join('departments', 'departments.id', '=', 'department_memberships.department_id')
            ->where('user_id', $request->user()->id)->where('responsibility', 'hof')->where('departments.is_active', true)
            ->pluck('department_id')->map(fn ($id) => (int) $id)->all();
        abort_if($ids === [], 403, 'An active HOF department assignment is required.');

        return $ids;
    }

    private function managers(int $owner, array $departments): array
    {
        return DB::table('reporting_relationships as r')
            ->join('department_memberships as m', function ($join): void {
                $join->on('m.user_id', '=', 'r.user_id')->on('m.department_id', '=', 'r.department_id');
            })->join('users as u', 'u.id', '=', 'r.user_id')
            ->where('r.manager_id', $owner)->whereIn('r.department_id', $departments)
            ->where('m.responsibility', 'manager')->where('u.is_active', true)->where('u.system_role', 'member')
            ->orderBy('u.name')->get(['u.id', 'u.name', 'u.designation', 'u.employee_number', 'r.department_id'])->toArray();
    }

    public function recipientRole(int $owner, int $recipient, int $department): ?string
    {
        if ($owner === $recipient) {
            return 'own';
        }
        if (collect($this->managers($owner, [$department]))->contains(fn ($manager) => (int) $manager->id === $recipient)) {
            return 'manager';
        }

        return collect($this->employees($owner, [$department]))->contains(fn ($employee) => (int) $employee->id === $recipient) ? 'employee' : null;
    }

    public function employees(int $owner, array $departments): array
    {
        $members = DB::table('department_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->whereIn('m.department_id', $departments)->where('u.is_active', true)->where('u.system_role', 'member')
            ->get(['u.id', 'u.name', 'u.designation', 'u.employee_number', 'm.department_id', 'm.responsibility']);
        $edges = DB::table('reporting_relationships')->whereIn('department_id', $departments)->get();
        $result = [];
        foreach ($departments as $department) {
            $reachable = [$owner];
            do {
                $before = count($reachable);
                foreach ($edges->where('department_id', $department) as $edge) {
                    if (in_array((int) $edge->manager_id, $reachable, true) && $members->contains(fn ($member) => (int) $member->id === (int) $edge->user_id && (int) $member->department_id === $department)) {
                        $reachable[] = (int) $edge->user_id;
                    }
                }
                $reachable = array_values(array_unique($reachable));
            } while (count($reachable) > $before);
            foreach ($members->where('department_id', $department)->where('responsibility', 'employee') as $member) {
                if ((int) $member->id !== $owner && in_array((int) $member->id, $reachable, true)) {
                    $result[] = $member;
                }
            }
        }

        return $result;
    }

    public function activities(object $plan): array
    {
        if ($plan->published_revision === null) {
            return [];
        }
        $snapshot = DB::table('performance_plan_versions')->where('plan_id', $plan->id)
            ->where('version', $plan->published_revision)->value('snapshot');
        $document = json_decode($snapshot ?? '{}', true);
        $rows = [];
        foreach ($document['kpas'] ?? [] as $kpa) {
            foreach ($kpa['kpis'] as $kpi) {
                foreach ($kpi['activities'] as $activity) {
                    $source = [
                        'activity' => $activity['title'], 'notes' => $activity['notes'], 'due_date' => $activity['due_date'],
                        'department_id' => $kpa['department_id'], 'kpa_code' => $kpa['code'], 'kpi_code' => $kpi['code'],
                        'kpa_title' => $kpa['title'], 'kpi_title' => $kpi['title'],
                        'kpa_weight' => $kpa['weight'], 'kpi_weight' => $kpi['weight'], 'activity_weight' => $activity['weight'],
                        'target_value' => $kpi['target_value'], 'target_unit' => $kpi['target_unit'],
                        'frequency' => $kpi['frequency'], 'direction' => $kpi['direction'],
                    ];
                    $rows[] = [
                        'plan_id' => (int) $plan->id, 'plan_code' => $plan->code, 'year' => (int) $plan->year,
                        'source_revision' => (int) $plan->published_revision, 'activity_code' => $activity['code'],
                        ...$source, 'personal_weight' => round($kpa['weight'] * $kpi['weight'] * $activity['weight'], 8),
                        'source_signature' => hash('sha256', json_encode($source, JSON_PRESERVE_ZERO_FRACTION)),
                    ];
                }
            }
        }

        return $rows;
    }

    public function workspace(Request $request): JsonResponse
    {
        $departments = $this->departments($request);
        $owner = $request->user()->id;
        $managers = $this->managers($owner, $departments);
        $assignments = DB::table('work_assignments')->where('owner_id', $owner)->get();
        $delegations = DB::table('kpi_delegations')->where('owner_id', $owner)->get();
        $rows = [];
        $seen = [];
        foreach (DB::table('performance_plans')->where('owner_id', $owner)->whereNotNull('published_revision')->orderByDesc('year')->get() as $plan) {
            foreach ($this->activities($plan) as $activity) {
                if (! in_array($activity['department_id'], $departments, true)) {
                    continue;
                }
                $assignment = $assignments->first(fn ($row) => (int) $row->plan_id === $activity['plan_id'] && $row->activity_code === $activity['activity_code']);
                if ($assignment) {
                    $seen[] = (int) $assignment->id;
                    $assignment->reported_progress = (float) $assignment->reported_progress;
                    $assignment->confirmed_progress = (float) $assignment->confirmed_progress;
                    $assignment->due_date = $assignment->due_date ? substr((string) $assignment->due_date, 0, 10) : null;
                    $assignment->own_review_configured = (int) $assignment->owner_id === (int) $assignment->assignee_id && DB::table('delivery_tasks')->where('assignment_id', $assignment->id)->where('generation', $assignment->delivery_generation)->where('is_active', true)->exists();
                    if ((int) $assignment->owner_id === (int) $assignment->assignee_id && ! app(DeliveryController::class)->context($assignment)['valid']) {
                        $assignment->confirmed_progress = 0;
                    }
                }
                $eligible = ! $assignment || $this->recipientRole($owner, (int) $assignment->assignee_id, $activity['department_id']) === $assignment->recipient_role;
                $delegation = $delegations->first(fn ($row) => (int) $row->plan_id === $activity['plan_id'] && $row->kpi_code === $activity['kpi_code']);
                $rows[] = [...$activity, 'assignment' => $assignment,
                    'source_changed' => $assignment && $assignment->source_signature !== $activity['source_signature'],
                    'assignee_eligible' => $eligible,
                    'delegation' => $delegation,
                ];
                if ($assignment && ($assignment->source_signature !== $activity['source_signature'] || ! $eligible)) {
                    $assignment->confirmed_progress = 0;
                }
            }
        }

        $members = DB::table('department_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->whereIn('m.department_id', $departments)->where('u.is_active', true)->orderBy('u.name')
            ->get(['u.id', 'u.name', 'u.designation', 'u.employee_number', 'm.department_id', 'm.responsibility']);
        $relationships = DB::table('reporting_relationships')->whereIn('department_id', $departments)->get();
        $team = [];
        foreach ($departments as $department) {
            $reachable = [$owner];
            do {
                $before = count($reachable);
                foreach ($relationships->where('department_id', $department) as $relationship) {
                    if (in_array((int) $relationship->manager_id, $reachable, true) && $members->contains(fn ($member) => (int) $member->id === (int) $relationship->user_id && (int) $member->department_id === $department)) {
                        $reachable[] = (int) $relationship->user_id;
                    }
                }
                $reachable = array_values(array_unique($reachable));
            } while (count($reachable) > $before);
            foreach ($members->where('department_id', $department) as $member) {
                if ((int) $member->id !== $owner && in_array((int) $member->id, $reachable, true)) {
                    $team[] = $member;
                }
            }
        }

        return response()->json([
            'activities' => $rows, 'managers' => $managers, 'team' => $team,
            'employees' => $this->employees($owner, $departments),
            'kpis' => app(KpiAssignmentController::class)->summaries($rows, $delegations),
            'departments' => DB::table('departments')->whereIn('id', $departments)->orderBy('name')->get(['id', 'name', 'code']),
            'retired_assignments' => $assignments->reject(fn ($row) => in_array((int) $row->id, $seen, true))
                ->map(fn ($row) => ['id' => $row->id, 'plan_id' => $row->plan_id, 'activity_code' => $row->activity_code])->values(),
        ]);
    }

    public function assign(Request $request): JsonResponse
    {
        $departments = $this->departments($request);
        $data = $request->validate([
            'plan_id' => ['required', 'integer'], 'activity_code' => ['required', 'string', 'max:64'],
            'source_revision' => ['required', 'integer', 'min:1'], 'version' => ['required', 'integer', 'min:0'],
            'assignee_id' => ['required', 'integer'], 'due_date' => ['nullable', 'date_format:Y-m-d'],
            'instructions' => ['nullable', 'string', 'max:1000'],
            'use_kpi_owner' => ['sometimes', 'boolean'],
            'proposed_personal_weight' => ['nullable', 'numeric', 'between:0,1'],
        ]);
        DB::transaction(function () use ($request, $departments, $data): void {
            $plan = DB::table('performance_plans')->where('id', $data['plan_id'])->lockForUpdate()->first();
            abort_unless($plan && (int) $plan->owner_id === $request->user()->id, 403, 'You can assign only your own published plan activities.');
            abort_unless((int) $plan->published_revision === (int) $data['source_revision'], 409, 'The published plan changed. Refresh the work dashboard.');
            $activity = collect($this->activities($plan))->firstWhere('activity_code', $data['activity_code']);
            abort_unless($activity && in_array($activity['department_id'], $departments, true), 422, 'Choose a current published activity in your department.');
            $existing = DB::table('work_assignments')->where('plan_id', $plan->id)->where('activity_code', $data['activity_code'])->lockForUpdate()->first();
            abort_unless((int) ($existing->version ?? 0) === (int) $data['version'], 409, 'This assignment changed. Refresh before saving.');
            $delegation = DB::table('kpi_delegations')->where('plan_id', $plan->id)->where('kpi_code', $activity['kpi_code'])->first();
            $inherit = (bool) ($data['use_kpi_owner'] ?? false);
            abort_if($inherit && ! $delegation, 422, 'This KPI has no delegated owner.');
            if ($inherit) {
                $summary = collect(app(KpiAssignmentController::class)->summaries($this->activities($plan), collect([$delegation])))->firstWhere('kpi_code', $activity['kpi_code']);
                abort_unless($summary && $summary['valid'], 409, 'Review the changed KPI delegation before restoring inherited ownership.');
            }
            $assignee = $inherit ? (int) $delegation->assignee_id : (int) $data['assignee_id'];
            $record = $this->writeAssignment($request, $plan, $activity, $assignee, $data, $delegation, $inherit);
            if (isset($data['proposed_personal_weight'])) {
                $key = 'assignment:'.$record->id.':'.$record->delivery_generation;
                if ($this->recipientRole((int) $plan->owner_id, $assignee, $activity['department_id']) === 'employee') {
                    $task = DB::table('delivery_tasks')->where('assignment_id', $record->id)->where('generation', $record->delivery_generation)->where('is_active', true)->first();
                    $key = 'task:'.$task->id.':'.$record->delivery_generation;
                }
                app(PersonalAllocationController::class)->proposeWeight($request->user()->id, $assignee, $key, (float) $data['proposed_personal_weight']);
            }
        });

        return response()->json(['message' => 'Assignment saved.']);
    }

    public function writeAssignment(Request $request, object $plan, array $activity, int $assignee, array $data, ?object $delegation = null, bool $inherit = false): object
    {
        $role = $this->recipientRole((int) $plan->owner_id, $assignee, (int) $activity['department_id']);
        if ($role === null) {
            throw ValidationException::withMessages(['assignee_id' => 'Choose yourself, a reporting manager or an employee under your HOF scope in this department.']);
        }
        $existing = DB::table('work_assignments')->where('plan_id', $plan->id)->where('activity_code', $activity['activity_code'])->lockForUpdate()->first();
        $reset = ! $existing || (int) $existing->assignee_id !== $assignee || $existing->source_signature !== $activity['source_signature'] || $existing->recipient_role !== $role;
        $attributes = [
            'owner_id' => $plan->owner_id, 'assignee_id' => $assignee, 'department_id' => $activity['department_id'],
            'source_revision' => $plan->published_revision, 'source_signature' => $activity['source_signature'],
            'delegation_id' => $delegation?->id, 'ownership_mode' => $inherit ? 'kpi' : 'activity',
            'recipient_role' => $role,
            'due_date' => $data['due_date'] ?? null, 'instructions' => $data['instructions'] ?? null,
            'version' => ($existing->version ?? 0) + 1, 'updated_at' => now(),
        ];
        if ($reset) {
            $attributes = [...$attributes, 'reported_progress' => 0, 'progress_note' => null, 'confirmed_progress' => 0, 'delivery_generation' => $existing ? $existing->delivery_generation + 1 : 1];
        }
        if ($existing) {
            DB::table('work_assignments')->where('id', $existing->id)->update($attributes);
            $id = $existing->id;
        } else {
            $id = DB::table('work_assignments')->insertGetId([...$attributes, 'plan_id' => $plan->id, 'activity_code' => $activity['activity_code'], 'created_at' => now()]);
        }
        $record = DB::table('work_assignments')->find($id);
        if (! $inherit && $assignee !== (int) $request->user()->id && ($reset || $existing->due_date != $attributes['due_date'] || $existing->instructions !== $attributes['instructions'])) {
            NotificationController::send($assignee, 'assignment:'.$id.':'.$record->version, 'assignment', 'Activity assigned or updated: '.$activity['activity'], ['workspace' => 'delivery', 'page' => $role === 'employee' ? 'tasks' : 'activities', 'search' => $activity['activity']]);
        }
        if ($role === 'employee' && ($reset || ! DB::table('delivery_tasks')->where('assignment_id', $id)->where('generation', $record->delivery_generation)->where('is_active', true)->exists())) {
            DB::table('delivery_tasks')->insert([
                'assignment_id' => $id, 'generation' => $record->delivery_generation, 'manager_id' => $plan->owner_id,
                'assignee_id' => $assignee, 'reviewer_id' => $plan->owner_id, 'title' => mb_substr($activity['activity'], 0, 500), 'weight' => 1,
                'due_date' => $attributes['due_date'], 'instructions' => $attributes['instructions'], 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->audit($request, 'work.assigned', $activity['activity_code'], $existing ? (array) $existing : null, $attributes);

        return $record;
    }

    public function progress(Request $request, int $assignment): JsonResponse
    {
        $departments = $this->departments($request);
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'], 'reported_progress' => ['required', 'numeric', 'between:0,100'],
            'progress_note' => ['required', 'string', 'max:1000'],
        ]);
        DB::transaction(function () use ($request, $departments, $data, $assignment): void {
            $reference = DB::table('work_assignments')->find($assignment);
            abort_unless($reference && (int) $reference->owner_id === $request->user()->id && (int) $reference->assignee_id === $request->user()->id, 403, 'Only your own work can be updated here.');
            $plan = DB::table('performance_plans')->where('id', $reference->plan_id)->lockForUpdate()->first();
            $record = DB::table('work_assignments')->where('id', $assignment)->lockForUpdate()->first();
            abort_unless((int) $record->assignee_id === $request->user()->id && (int) $record->version === (int) $data['version'], 409, 'This assignment changed. Refresh before saving.');
            $activity = collect($this->activities($plan))->firstWhere('activity_code', $record->activity_code);
            abort_if(DB::table('delivery_tasks')->where('assignment_id', $assignment)->where('generation', $record->delivery_generation)->where('is_active', true)->exists(), 409, 'Use Task reviews & delivery to update and submit this work for approval.');
            abort_unless($activity && in_array($activity['department_id'], $departments, true) && $record->source_signature === $activity['source_signature'], 409, 'The published activity changed. Review its assignment before updating progress.');
            $attributes = ['reported_progress' => round((float) $data['reported_progress'], 2), 'progress_note' => $data['progress_note'], 'version' => $record->version + 1, 'updated_at' => now()];
            DB::table('work_assignments')->where('id', $assignment)->update($attributes);
            $this->audit($request, 'work.progress_updated', $record->activity_code, (array) $record, $attributes);
        });

        return response()->json(['message' => 'Reported progress saved. This is not approved completion.']);
    }

    private function audit(Request $request, string $action, string $subject, ?array $before, array $after): void
    {
        DB::table('admin_activity')->insert([
            'actor_id' => $request->user()->id, 'action' => $action, 'subject' => $subject,
            'before_data' => $before ? json_encode($before) : null, 'after_data' => json_encode($after), 'created_at' => now(),
        ]);
    }
}
