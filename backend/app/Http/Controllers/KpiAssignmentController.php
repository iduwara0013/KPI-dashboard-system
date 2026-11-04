<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class KpiAssignmentController extends Controller
{
    public function summaries(array $rows, Collection $delegations): array
    {
        $result = [];
        foreach (collect($rows)->groupBy(fn ($row) => $row['plan_id'].':'.$row['kpi_code']) as $group) {
            $first = $group->first();
            $delegation = $delegations->first(fn ($record) => (int) $record->plan_id === $first['plan_id'] && $record->kpi_code === $first['kpi_code']);
            $signature = hash('sha256', json_encode($group->map(fn ($row) => [$row['activity_code'], $row['source_signature']])->values()->all()));
            $eligible = ! $delegation || app(HofWorkController::class)->recipientRole((int) $delegation->owner_id, (int) $delegation->assignee_id, (int) $first['department_id']) === $delegation->recipient_role;
            $activeHof = ! $delegation || DB::table('department_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')->join('departments as d', 'd.id', '=', 'm.department_id')
                ->where('m.user_id', $delegation->owner_id)->where('m.department_id', $first['department_id'])->where('m.responsibility', 'hof')->where('u.is_active', true)->where('u.system_role', 'member')->where('d.is_active', true)->exists();
            $confirmed = $group->sum(fn ($row) => $row['activity_weight'] * (($row['source_changed'] ?? false) || ! ($row['assignee_eligible'] ?? true) ? 0 : (float) ($row['assignment']->confirmed_progress ?? 0)));
            $result[] = [...$first, 'activity' => $first['kpi_title'], 'activities' => $group->values()->all(),
                'personal_weight' => round($first['kpa_weight'] * $first['kpi_weight'], 8),
                'source_signature' => $signature, 'delegation' => $delegation,
                'valid' => $delegation && $activeHof && $eligible && $delegation->source_signature === $signature,
                'confirmed_progress' => round($confirmed, 4),
            ];
        }

        return $result;
    }

    public function rowsForPlan(object $plan): array
    {
        $assignments = DB::table('work_assignments')->where('plan_id', $plan->id)->get()->keyBy('activity_code');

        return array_map(function (array $row) use ($plan, $assignments): array {
            $assignment = $assignments->get($row['activity_code']);
            if ($assignment && (int) $assignment->owner_id === (int) $assignment->assignee_id && ! app(DeliveryController::class)->context($assignment)['valid']) {
                $assignment->confirmed_progress = 0;
            }
            $eligible = ! $assignment || app(HofWorkController::class)->recipientRole((int) $plan->owner_id, (int) $assignment->assignee_id, $row['department_id']) === $assignment->recipient_role;

            return [...$row, 'assignment' => $assignment, 'source_changed' => $assignment && $assignment->source_signature !== $row['source_signature'], 'assignee_eligible' => $eligible];
        }, app(HofWorkController::class)->activities($plan));
    }

    public function forRecipient(int $user): array
    {
        $delegations = DB::table('kpi_delegations')->where('assignee_id', $user)->get();
        $result = [];
        foreach (DB::table('performance_plans')->whereIn('id', $delegations->pluck('plan_id')->unique())->get() as $plan) {
            foreach ($this->summaries($this->rowsForPlan($plan), $delegations) as $summary) {
                if ($summary['delegation']) {
                    $result[] = $summary;
                }
            }
        }

        return $result;
    }

    public function assign(Request $request): JsonResponse
    {
        abort_unless($request->user()?->is_active && $request->user()->system_role === 'member', 403);
        $data = $request->validate([
            'plan_id' => ['required', 'integer'], 'kpi_code' => ['required', 'string', 'max:64'],
            'source_revision' => ['required', 'integer', 'min:1'], 'version' => ['required', 'integer', 'min:0'],
            'assignee_id' => ['required', 'integer'], 'due_date' => ['nullable', 'date_format:Y-m-d'],
            'instructions' => ['nullable', 'string', 'max:1000'], 'proposed_personal_weight' => ['nullable', 'numeric', 'between:0,1'],
        ]);
        DB::transaction(function () use ($request, $data): void {
            $plan = DB::table('performance_plans')->where('id', $data['plan_id'])->lockForUpdate()->first();
            abort_unless($plan && (int) $plan->owner_id === $request->user()->id, 403);
            abort_unless((int) $plan->published_revision === (int) $data['source_revision'], 409, 'The published plan changed. Refresh before assigning this KPI.');
            $kpi = collect($this->summaries(app(HofWorkController::class)->activities($plan), collect()))->firstWhere('kpi_code', $data['kpi_code']);
            abort_unless($kpi, 422, 'Choose a current published KPI.');
            $hof = app(HofWorkController::class);
            $activeHof = DB::table('department_memberships as m')->join('departments as d', 'd.id', '=', 'm.department_id')
                ->where('m.user_id', $plan->owner_id)->where('m.department_id', $kpi['department_id'])->where('m.responsibility', 'hof')->where('d.is_active', true)->exists();
            $role = $hof->recipientRole((int) $plan->owner_id, (int) $data['assignee_id'], $kpi['department_id']);
            abort_unless($activeHof && $role !== null, 422, 'Choose yourself or an eligible manager/employee in this department.');
            $old = DB::table('kpi_delegations')->where('plan_id', $plan->id)->where('kpi_code', $data['kpi_code'])->lockForUpdate()->first();
            abort_unless((int) ($old->version ?? 0) === (int) $data['version'], 409, 'This KPI delegation changed. Refresh before saving.');
            $changed = $old && ((int) $old->assignee_id !== (int) $data['assignee_id'] || $old->source_signature !== $kpi['source_signature'] || $old->recipient_role !== $role);
            $attributes = ['owner_id' => $plan->owner_id, 'assignee_id' => $data['assignee_id'], 'department_id' => $kpi['department_id'], 'recipient_role' => $role,
                'source_revision' => $plan->published_revision, 'source_signature' => $kpi['source_signature'], 'version' => ($old->version ?? 0) + 1,
                'generation' => ($old->generation ?? 1) + ($changed ? 1 : 0), 'due_date' => $data['due_date'] ?? null, 'instructions' => $data['instructions'] ?? null, 'updated_at' => now()];
            if ($old) {
                DB::table('kpi_delegations')->where('id', $old->id)->update($attributes);
                $id = $old->id;
            } else {
                $id = DB::table('kpi_delegations')->insertGetId([...$attributes, 'plan_id' => $plan->id, 'kpi_code' => $data['kpi_code'], 'created_at' => now()]);
            }
            $delegation = DB::table('kpi_delegations')->find($id);
            foreach ($kpi['activities'] as $activity) {
                $assignment = DB::table('work_assignments')->where('plan_id', $plan->id)->where('activity_code', $activity['activity_code'])->first();
                if ($assignment && $assignment->ownership_mode === 'activity') {
                    DB::table('work_assignments')->where('id', $assignment->id)->update(['delegation_id' => $id, 'version' => $assignment->version + 1, 'updated_at' => now()]);

                    continue;
                }
                $hof->writeAssignment($request, $plan, $activity, (int) $delegation->assignee_id, $data, $delegation, true);
            }
            if (isset($data['proposed_personal_weight'])) {
                app(PersonalAllocationController::class)->proposeWeight($request->user()->id, (int) $delegation->assignee_id, 'kpi:'.$id.':'.$delegation->generation, (float) $data['proposed_personal_weight']);
            }
            DB::table('admin_activity')->insert(['actor_id' => $request->user()->id, 'action' => 'kpi.delegated', 'subject' => $data['kpi_code'], 'before_data' => $old ? json_encode($old) : null, 'after_data' => json_encode($attributes), 'created_at' => now()]);
        });

        return response()->json(['message' => 'KPI assigned. Individual activity overrides were preserved.']);
    }
}
