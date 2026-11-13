<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RequirePlanningAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MonthlyKpiResultController extends Controller
{
    public function workspace(Request $request): JsonResponse
    {
        $this->authorizeWorkspace($request);
        $date = now('Asia/Colombo');
        $data = $request->validate(['period' => ['nullable', 'date_format:Y-m', 'regex:/^(20\d{2}|2100)-/']]);
        $period = $data['period'] ?? $date->format('Y-m');
        [$year, $month] = array_map('intval', explode('-', $period));
        $future = $period > $date->format('Y-m');
        $user = $request->user();
        $editor = $user->system_role === 'plan_editor';
        $editorDepartments = $editor ? RequirePlanningAccess::departmentIds($user) : collect();
        $people = DB::table('users')->pluck('name', 'id');
        $rows = [];

        foreach (DB::table('performance_plans')->where('year', $year)->whereNotNull('published_revision')->get() as $plan) {
            $submissions = DB::table('monthly_kpi_submissions')->where('plan_id', $plan->id)->where('month', $month)
                ->orderByDesc('attempt')->get()->groupBy('kpi_code');
            $kpis = $this->monthlyKpis($plan);
            if ($submissions->isNotEmpty()) {
                $kpis += $this->archivedKpis($plan, $submissions->except(array_keys($kpis)));
            }
            foreach ($kpis as $code => $kpi) {
                if ($editor && ! $editorDepartments->contains((int) $kpi['department_id'])) {
                    continue;
                }
                $isParticipant = in_array($user->id, [$kpi['reporter_id'], $kpi['reviewer_id']], true);
                $isPlanOwner = $kpi['active_hof'] && $user->id === (int) $plan->owner_id;
                if (! $editor && $user->system_role !== 'md' && ! $isParticipant && ! $isPlanOwner) {
                    continue;
                }
                $history = $submissions->get($code, collect());
                if ($editor) {
                    $history = $history->filter(function ($item) use ($plan, $code, $editorDepartments): bool {
                        $snapshot = clone $plan;
                        $snapshot->published_revision = (int) $item->source_revision;

                        return collect(app(HofWorkController::class)->activities($snapshot))
                            ->contains(fn ($activity) => $activity['kpi_code'] === $code && $editorDepartments->contains((int) $activity['department_id']));
                    });
                }
                $latest = $history->first();
                $status = $latest->status ?? 'pending_measurement';
                $target = $latest ? (float) $latest->target_value : $kpi['target_value'];
                $actual = $latest ? (float) $latest->actual_value : null;
                $direction = $latest->direction ?? $kpi['direction'];
                $canReview = $status === 'submitted' && $kpi['reviewer_id'] === $user->id && (int) $latest->submitted_by !== $user->id;
                $selfConfirm = $kpi['reporter_id'] === (int) $plan->owner_id && $kpi['active_hof'];
                $canSubmit = ! $future && $kpi['reporter_id'] === $user->id
                    && ($selfConfirm || $kpi['reviewer_id'] !== null) && ($this->canSubmit($latest, $user->id) || ($selfConfirm && $latest?->status === 'submitted'));

                $rows[] = [
                    'plan_id' => (int) $plan->id, 'plan_code' => $plan->code, 'kpi_code' => $code,
                    'title' => $kpi['kpi_title'], 'kpa' => $kpi['kpa_title'], 'department' => $kpi['department'],
                    'reporter' => $people[$kpi['reporter_id']] ?? null, 'reviewer' => $people[$kpi['reviewer_id']] ?? null,
                    'reviewer_id' => $kpi['reviewer_id'], 'plan_owner_id' => (int) $plan->owner_id,
                    'target' => $target, 'actual' => $actual, 'unit' => $latest->target_unit ?? $kpi['target_unit'],
                    'direction' => $direction, 'gap' => $actual === null ? null : round($direction === 'lower' ? $target - $actual : $actual - $target, 4),
                    'status' => $status, 'attempt' => (int) ($latest->attempt ?? 0), 'archived' => $kpi['context'] === null,
                    'can_submit' => $canSubmit, 'can_review' => $canReview,
                    'can_assign_reviewer' => false, 'self_confirm' => $selfConfirm,
                    'context' => $kpi['context'],
                    'history' => $history->map(fn ($item) => [
                        'attempt' => (int) $item->attempt, 'actual' => (float) $item->actual_value, 'status' => $item->status,
                        'submitted_by' => $people[$item->submitted_by], 'created_at' => $item->created_at,
                        'reviewed_by' => $people[$item->reviewed_by] ?? null, 'reviewed_at' => $item->reviewed_at,
                        ...($isParticipant || $isPlanOwner ? ['evidence' => $item->evidence, 'evidence_url' => $item->evidence_url, 'review_note' => $item->review_note] : []),
                    ])->all(),
                ];
            }
        }

        return response()->json(['period' => $period, 'future' => $future, 'rows' => $rows, 'reviewers' => []]);
    }

    public function submit(Request $request): JsonResponse
    {
        $this->authorizeWorkspace($request);
        $data = $request->validate([
            ...$this->contextRules(), 'month' => ['required', 'integer', 'between:1,12'],
            'attempt' => ['required', 'integer', 'min:0'],
            'actual_value' => ['required', 'numeric', 'decimal:0,4', 'between:-9999999999999,9999999999999'],
            'evidence' => ['required', 'string', 'max:2000'],
            'evidence_url' => ['nullable', 'url:http,https', 'max:1000'],
        ]);
        $selfConfirmed = DB::transaction(function () use ($request, $data): bool {
            [$plan, $kpi] = $this->lockedContext($data);
            $user = $request->user()->id;
            abort_unless($kpi['reporter_id'] === $user, 403, 'Only the KPI owner can submit a result.');
            abort_unless($kpi['reviewer_id'] !== null || ($user === (int) $plan->owner_id && $kpi['active_hof']), 422, 'Configure a reporting reviewer before submitting this result.');
            $selfConfirm = $user === (int) $plan->owner_id && $kpi['active_hof'];
            abort_if(sprintf('%04d-%02d', $plan->year, $data['month']) > now('Asia/Colombo')->format('Y-m'), 422, 'Choose a current or past month.');
            $latest = $this->latest($data);
            abort_unless((int) ($latest->attempt ?? 0) === (int) $data['attempt']
                && ($this->canSubmit($latest, $user) || ($selfConfirm && $latest?->status === 'submitted')), 409, 'The monthly result changed. Refresh before submitting.');
            if ($latest?->status === 'submitted') {
                DB::table('monthly_kpi_submissions')->where('id', $latest->id)->update(['status' => 'superseded']);
            }
            DB::table('monthly_kpi_submissions')->insert([
                'plan_id' => $plan->id, 'kpi_code' => $data['kpi_code'], 'month' => $data['month'],
                'attempt' => (int) $data['attempt'] + 1, 'source_revision' => $latest->source_revision ?? $plan->published_revision,
                'target_value' => $latest->target_value ?? $kpi['target_value'],
                'target_unit' => $latest->target_unit ?? $kpi['target_unit'], 'direction' => $latest->direction ?? $kpi['direction'],
                'actual_value' => $data['actual_value'], 'evidence' => $data['evidence'], 'evidence_url' => $data['evidence_url'] ?? null,
                'submitted_by' => $user, 'status' => $selfConfirm ? 'approved' : 'submitted', 'created_at' => now(),
                'reviewed_by' => null, 'reviewed_at' => $selfConfirm ? now() : null,
                'review_note' => $selfConfirm ? 'Monthly result confirmed by the HOF.' : null,
            ]);

            if (! $selfConfirm) {
                NotificationController::send($kpi['reviewer_id'], 'monthly-submitted:'.$plan->id.':'.$data['kpi_code'].':'.$data['month'].':'.((int) $data['attempt'] + 1), 'kpi_submission',
                    'Monthly result awaits your review: '.$kpi['kpi_title'], ['workspace' => 'results', 'page' => 'results', 'plan_id' => (int) $plan->id, 'kpi_code' => $data['kpi_code'], 'period' => sprintf('%04d-%02d', $plan->year, $data['month']), 'attempt' => (int) $data['attempt'] + 1]);
            }

            return $selfConfirm;
        });

        return response()->json(['message' => $selfConfirmed ? 'Monthly result confirmed by the HOF.' : 'Monthly result submitted for review.'], 201);
    }

    public function review(Request $request): JsonResponse
    {
        $this->authorizeWorkspace($request);
        $data = $request->validate([
            ...$this->contextRules(), 'month' => ['required', 'integer', 'between:1,12'],
            'attempt' => ['required', 'integer', 'min:1'],
            'decision' => ['required', 'in:approved,needs_correction'],
            'review_note' => ['required_if:decision,needs_correction', 'nullable', 'string', 'max:2000'],
        ]);
        DB::transaction(function () use ($request, $data): void {
            [$plan, $kpi] = $this->lockedContext($data);
            $user = $request->user()->id;
            abort_unless($kpi['reviewer_id'] === $user, 403, 'Only the designated reviewer can review a result.');
            $latest = $this->latest($data);
            abort_unless($latest && $latest->status === 'submitted' && (int) $latest->attempt === (int) $data['attempt'], 409, 'The monthly result changed. Refresh before reviewing.');
            abort_if((int) $latest->submitted_by === $user, 403, 'You cannot review your own submission.');
            DB::table('monthly_kpi_submissions')->where('id', $latest->id)->update([
                'status' => $data['decision'], 'review_note' => $data['review_note'] ?? null,
                'reviewed_by' => $user, 'reviewed_at' => now(),
            ]);
            NotificationController::send((int) $latest->submitted_by, 'monthly-reviewed:'.$latest->id, $data['decision'] === 'approved' ? 'kpi_approved' : 'kpi_needs_correction',
                ($data['decision'] === 'approved' ? 'Monthly result approved: ' : 'Monthly result needs correction: ').$kpi['kpi_title'], ['workspace' => 'results', 'page' => 'results', 'plan_id' => (int) $plan->id, 'kpi_code' => $data['kpi_code'], 'period' => sprintf('%04d-%02d', $plan->year, $data['month']), 'attempt' => (int) $data['attempt']]);
        });

        return response()->json(['message' => $data['decision'] === 'approved' ? 'Monthly result approved.' : 'Correction requested.']);
    }

    private function authorizeWorkspace(Request $request): void
    {
        abort_unless($request->user()?->is_active && in_array($request->user()->system_role, ['member', 'plan_editor', 'md'], true), 403);
    }

    private function contextRules(): array
    {
        return [
            'plan_id' => ['required', 'integer', 'min:1'], 'kpi_code' => ['required', 'string', 'max:64'],
            'context' => ['required', 'string', 'size:64'],
        ];
    }

    private function lockedContext(array $data): array
    {
        $plan = DB::table('performance_plans')->where('id', $data['plan_id'])->lockForUpdate()->first();
        abort_unless($plan, 404);
        $kpi = $this->monthlyKpis($plan)[$data['kpi_code']] ?? null;
        abort_unless($kpi && hash_equals($kpi['context'], $data['context']), 409, 'The published KPI or its ownership changed. Refresh and try again.');

        return [$plan, $kpi];
    }

    private function latest(array $data): ?object
    {
        return DB::table('monthly_kpi_submissions')->where('plan_id', $data['plan_id'])->where('kpi_code', $data['kpi_code'])
            ->where('month', $data['month'])->orderByDesc('attempt')->first();
    }

    private function canSubmit(?object $latest, int $reporter): bool
    {
        return ! $latest || $latest->status === 'needs_correction'
            || ($latest->status === 'submitted' && (int) $latest->submitted_by !== $reporter);
    }

    private function monthlyKpis(object $plan): array
    {
        $activities = collect(app(HofWorkController::class)->activities($plan))->where('frequency', 'Monthly')->all();
        if ($activities === []) {
            return [];
        }
        $delegations = DB::table('kpi_delegations')->where('plan_id', $plan->id)->get();
        $departments = DB::table('departments')->where('is_active', true)->pluck('name', 'id');
        $activeHofDepartments = $this->activeHofDepartments((int) $plan->owner_id);
        $rows = [];
        foreach (app(KpiAssignmentController::class)->summaries($activities, $delegations) as $kpi) {
            $code = $kpi['kpi_code'];
            $hof = (int) $plan->owner_id;
            $activeHof = $activeHofDepartments->contains($kpi['department_id']);
            $delegation = $kpi['delegation'];
            $activeOwner = DB::table('department_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')->join('departments as d', 'd.id', '=', 'm.department_id')
                ->where('m.user_id', $hof)->where('m.department_id', $kpi['department_id'])->where('u.is_active', true)->where('u.system_role', 'member')->where('d.is_active', true)->exists();
            $reporter = $activeOwner && (! $delegation || $kpi['valid']) ? (int) ($delegation->assignee_id ?? $hof) : null;
            $reviewer = $reporter !== null && $reporter !== $hof ? $hof : null;
            if (! $activeHof && $reporter === $hof) {
                $eligible = DB::table('reporting_relationships as r')->join('users as u', 'u.id', '=', 'r.manager_id')
                    ->join('department_memberships as m', fn ($join) => $join->on('m.user_id', '=', 'r.manager_id')->on('m.department_id', '=', 'r.department_id'))
                    ->where('r.user_id', $hof)->where('r.department_id', $kpi['department_id'])->where('u.is_active', true)->where('u.system_role', 'member')->pluck('u.id')->unique();
                $selected = DB::table('delivery_tasks as t')->join('work_assignments as a', 'a.id', '=', 't.assignment_id')
                    ->where('a.plan_id', $plan->id)->whereIn('a.activity_code', collect($kpi['activities'])->pluck('activity_code'))
                    ->whereColumn('t.generation', 'a.delivery_generation')->whereNull('t.parent_task_id')->where('t.assignee_id', $hof)->whereIn('t.reviewer_id', $eligible)->pluck('t.reviewer_id')->unique();
                $choices = $selected->isNotEmpty() ? $selected : $eligible;
                $reviewer = $choices->count() === 1 ? (int) $choices->first() : null;
            }
            $rows[$code] = [
                ...$kpi, 'department' => $departments[$kpi['department_id']] ?? 'Inactive department',
                'active_hof' => $activeHof, 'reporter_id' => $reporter, 'reviewer_id' => $reviewer,
                'context' => hash('sha256', json_encode([$plan->id, $code, $plan->published_revision, $reporter, $reviewer, $delegation->generation ?? null])),
            ];
        }

        return $rows;
    }

    private function activeHofDepartments(int $user): Collection
    {
        return DB::table('department_memberships as m')->join('departments as d', 'd.id', '=', 'm.department_id')
            ->join('users as u', 'u.id', '=', 'm.user_id')->where('m.user_id', $user)->where('m.responsibility', 'hof')
            ->where('d.is_active', true)->where('u.is_active', true)->where('u.system_role', 'member')->pluck('m.department_id');
    }

    private function archivedKpis(object $plan, Collection $submissions): array
    {
        if ($submissions->isEmpty()) {
            return [];
        }
        $hofDepartments = $this->activeHofDepartments((int) $plan->owner_id);
        $departments = DB::table('departments')->pluck('name', 'id');
        $versions = [];
        $rows = [];
        foreach ($submissions as $code => $history) {
            $revision = (int) $history->first()->source_revision;
            if (! isset($versions[$revision])) {
                $snapshot = clone $plan;
                $snapshot->published_revision = $revision;
                $versions[$revision] = collect(app(HofWorkController::class)->activities($snapshot))->keyBy('kpi_code');
            }
            $kpi = $versions[$revision]->get($code);
            if ($kpi) {
                $rows[$code] = [
                    ...$kpi, 'department' => $departments[$kpi['department_id']] ?? 'Inactive department',
                    'active_hof' => $hofDepartments->contains($kpi['department_id']),
                    'reporter_id' => null, 'reviewer_id' => null, 'context' => null,
                ];
            }
        }

        return $rows;
    }
}
