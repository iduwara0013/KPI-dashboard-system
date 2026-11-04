<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RequireDeliveryAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PersonalAllocationController extends Controller
{
    private function authorize(Request $request, int $owner): bool
    {
        $editor = $request->user()?->is_active && $request->user()->system_role === 'plan_editor';
        abort_unless($editor || ($request->user()?->is_active && $request->user()->id === $owner && RequireDeliveryAccess::roles($request->user()) !== []), 403);
        abort_unless(DB::table('users')->where('id', $owner)->where('is_active', true)->where('system_role', 'member')->exists(), 422, 'Choose an active team member.');

        return $editor;
    }

    public function proposeWeight(int $actor, int $owner, string $key, float $weight): void
    {
        DB::table('personal_weight_proposals')->updateOrInsert(['owner_id' => $owner, 'source_key' => $key], ['actor_id' => $actor, 'weight' => round($weight, 4), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function sources(int $owner, int $year): array
    {
        $sources = [];
        $kpis = collect(app(KpiAssignmentController::class)->forRecipient($owner))->where('year', $year);
        $covered = [];
        foreach ($kpis as $kpi) {
            if ((int) $kpi['delegation']->owner_id === $owner) {
                continue;
            }
            $covered[] = $kpi['plan_id'].':'.$kpi['kpi_code'];
            $sources[] = $this->source('kpi:'.$kpi['delegation']->id.':'.$kpi['delegation']->generation, 'Linked KPA · delegated KPI', $kpi['kpi_title'], $kpi['department_id'], $kpi['valid'], $kpi['source_signature'], $kpi['confirmed_progress']);
        }
        foreach (DB::table('work_assignments')->where('assignee_id', $owner)->where('recipient_role', 'manager')->get() as $assignment) {
            $context = app(DeliveryController::class)->context($assignment);
            $row = $context['activity'];
            if (! $row || $row['year'] !== $year || in_array($row['plan_id'].':'.$row['kpi_code'], $covered, true)) {
                continue;
            }
            $sources[] = $this->source('assignment:'.$assignment->id.':'.$assignment->delivery_generation, 'Assigned activity', $row['activity'], $row['department_id'], $context['valid'], $row['source_signature'], $context['valid'] ? (float) $assignment->confirmed_progress : 0);
        }
        foreach (DB::table('delivery_tasks')->where('assignee_id', $owner)->where('is_active', true)->get() as $task) {
            $assignment = DB::table('work_assignments')->find($task->assignment_id);
            if (! $assignment || (int) $task->generation !== (int) $assignment->delivery_generation || (int) $assignment->owner_id === $owner || ((int) $assignment->assignee_id === $owner && $assignment->recipient_role === 'manager')) {
                continue;
            }
            $context = app(DeliveryController::class)->context($assignment);
            $row = $context['activity'];
            if (! $row || $row['year'] !== $year || in_array($row['plan_id'].':'.$row['kpi_code'], $covered, true)) {
                continue;
            }
            $valid = $context['valid'] && app(DeliveryController::class)->employeeEligible($owner, $assignment);
            $token = $row['source_signature'].':'.$task->title.':'.$task->weight.':'.$task->reviewer_id;
            $sources[] = $this->source('task:'.$task->id.':'.$assignment->delivery_generation, $assignment->recipient_role === 'employee' ? 'Direct HOF work' : 'Employee task', $task->title, $row['department_id'], $valid, $token, $valid && $task->status === 'approved' ? 100 : 0);
        }
        foreach (DB::table('performance_plans')->where('owner_id', $owner)->where('year', $year)->whereNotNull('published_revision')->get() as $plan) {
            $rows = app(KpiAssignmentController::class)->rowsForPlan($plan);
            foreach (collect($rows)->groupBy('kpa_code') as $group) {
                $first = $group->first();
                $active = DB::table('department_memberships as m')->join('departments as d', 'd.id', '=', 'm.department_id')->where('m.user_id', $owner)->where('m.department_id', $first['department_id'])->where('m.responsibility', 'hof')->where('d.is_active', true)->exists();
                $progress = $group->sum(fn ($row) => $row['kpi_weight'] * $row['activity_weight'] * (($row['source_changed'] || ! $row['assignee_eligible']) ? 0 : (float) ($row['assignment']->confirmed_progress ?? 0)));
                $source = $this->source('hof:'.$plan->id.':'.$first['kpa_code'], 'HOF responsibility', $first['kpa_title'], $first['department_id'], $active, json_encode($group->pluck('source_signature')->all()), $progress);
                $source['suggested_weight'] = $first['kpa_weight'];
                $sources[] = $source;
            }
        }
        $proposals = DB::table('personal_weight_proposals')->where('owner_id', $owner)->get()->keyBy('source_key');
        foreach ($sources as &$source) {
            if ($proposals->has($source['key'])) {
                $source['suggested_weight'] = (float) $proposals[$source['key']]->weight;
            }
        }
        unset($source);

        return $sources;
    }

    private function source(string $key, string $kind, string $title, int $department, bool $valid, string $token, float $progress): array
    {
        return ['key' => $key, 'kind' => $kind, 'title' => $title, 'department_id' => $department, 'valid' => $valid,
            'token' => hash('sha256', $token), 'confirmed_progress' => $progress, 'suggested_weight' => null];
    }

    private function score(?array $entries, array $sources): array
    {
        if (! $entries || count($entries) !== count($sources) || abs(array_sum(array_column($entries, 'weight')) - 1) > 0.00005) {
            return ['current' => false, 'confirmed_completion' => null];
        }
        $map = collect($sources)->keyBy('key');
        $score = 0;
        foreach ($entries as $entry) {
            $source = $map->get($entry['key']);
            if (! $source || ! $source['valid'] || $source['token'] !== $entry['token']) {
                return ['current' => false, 'confirmed_completion' => null];
            }
            $score += $entry['weight'] * $source['confirmed_progress'];
        }

        return ['current' => true, 'confirmed_completion' => round($score, 4)];
    }

    public function workspace(Request $request): JsonResponse
    {
        $editor = $request->user()?->is_active && $request->user()->system_role === 'plan_editor';
        abort_unless($editor || RequireDeliveryAccess::roles($request->user()) !== [], 403);
        $people = DB::table('users as u')->join('department_memberships as m', 'm.user_id', '=', 'u.id')->join('departments as d', 'd.id', '=', 'm.department_id')
            ->where('u.is_active', true)->where('u.system_role', 'member')->where('d.is_active', true)
            ->when(! $editor, fn ($query) => $query->where('u.id', $request->user()->id))->distinct()->orderBy('u.name')->get(['u.id', 'u.name', 'u.employee_number']);

        return response()->json(['can_publish' => $editor, 'people' => $people,
            'plans' => DB::table('personal_allocations')->when(! $editor, fn ($query) => $query->where('owner_id', $request->user()->id))->orderByDesc('updated_at')->get(['id', 'owner_id', 'year', 'version', 'status', 'published_version']),
            'departments' => DB::table('departments')->where('is_active', true)->get(['id', 'name', 'code']),
        ]);
    }

    public function show(Request $request, int $owner, int $year): JsonResponse
    {
        $editor = $this->authorize($request, $owner);
        abort_unless($year >= 2000 && $year <= 2100, 422);
        $sources = $this->sources($owner, $year);
        $record = DB::table('personal_allocations')->where('owner_id', $owner)->where('year', $year)->first();
        $draft = $record ? json_decode($record->draft_payload, true) : [];
        $published = $record?->published_payload ? json_decode($record->published_payload, true) : null;
        $entries = array_map(function ($source) use ($draft): array {
            $old = collect($draft)->firstWhere('key', $source['key']);

            return [...$source, 'weight' => $old && $old['token'] === $source['token'] ? $old['weight'] : $source['suggested_weight']];
        }, $sources);

        return response()->json(['id' => $record->id ?? null, 'owner_id' => $owner, 'year' => $year, 'version' => (int) ($record->version ?? 0), 'status' => $record->status ?? 'not_started',
            'published_version' => $record->published_version ?? null, 'can_publish' => $editor, 'entries' => $entries,
            'published_entries' => $published, 'score' => $this->score($published, $sources),
            'history' => $record ? DB::table('personal_allocation_versions as v')->join('users as u', 'u.id', '=', 'v.actor_id')->where('allocation_id', $record->id)->orderByDesc('v.id')->get(['v.version', 'v.event', 'v.snapshot', 'v.review_note', 'v.created_at', 'u.name as actor_name']) : [],
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate(['owner_id' => ['required', 'integer'], 'year' => ['required', 'integer', 'between:2000,2100'], 'version' => ['required', 'integer', 'min:0'],
            'entries' => ['required', 'array', 'min:1', 'max:1000'], 'entries.*.key' => ['required', 'string', 'max:120'], 'entries.*.token' => ['required', 'string', 'size:64'], 'entries.*.weight' => ['nullable', 'numeric', 'between:0,1']]);
        $editor = $this->authorize($request, (int) $data['owner_id']);
        DB::transaction(function () use ($request, $data, $editor): void {
            DB::table('users')->where('id', $data['owner_id'])->lockForUpdate()->first();
            $old = DB::table('personal_allocations')->where('owner_id', $data['owner_id'])->where('year', $data['year'])->lockForUpdate()->first();
            abort_unless((int) ($old->version ?? 0) === (int) $data['version'], 409, 'This allocation changed. Reload before saving.');
            $sources = $this->sources((int) $data['owner_id'], (int) $data['year']);
            $entries = $this->validatedEntries($data['entries'], $sources);
            $version = ($old->version ?? 0) + 1;
            $attributes = ['draft_payload' => json_encode($entries), 'version' => $version, 'status' => $editor ? 'draft' : 'pending', 'updated_at' => now()];
            if ($old) {
                DB::table('personal_allocations')->where('id', $old->id)->update($attributes);
                $id = $old->id;
            } else {
                $id = DB::table('personal_allocations')->insertGetId([...$attributes, 'owner_id' => $data['owner_id'], 'year' => $data['year'], 'created_at' => now()]);
            }
            DB::table('personal_allocation_versions')->insert(['allocation_id' => $id, 'actor_id' => $request->user()->id, 'version' => $version, 'event' => $editor ? 'draft_saved' : 'proposed', 'snapshot' => json_encode(['entries' => $entries]), 'created_at' => now()]);
        });

        return response()->json(['message' => 'Personal weights saved for review.']);
    }

    private function validatedEntries(array $entries, array $sources): array
    {
        $map = collect($sources)->keyBy('key');
        $keys = [];
        $normalized = [];
        foreach ($entries as $entry) {
            $source = $map->get($entry['key']);
            abort_unless($source && $source['token'] === $entry['token'] && ! in_array($entry['key'], $keys, true), 409, 'The assigned work changed. Reload the allocation to review its current responsibilities.');
            $keys[] = $entry['key'];
            $normalized[] = [...$source, 'weight' => isset($entry['weight']) ? round((float) $entry['weight'], 4) : null];
        }
        if (count($keys) !== count($sources) || array_sum(array_column($normalized, 'weight')) > 1.00005) {
            throw ValidationException::withMessages(['entries' => 'Include every responsibility and keep the proposed allocation at or below 100%.']);
        }

        return $normalized;
    }

    public function publish(Request $request, int $allocation): JsonResponse
    {
        abort_unless($request->user()?->is_active && $request->user()->system_role === 'plan_editor', 403, 'Only the Plan Editor can approve a complete personal allocation.');
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'review_note' => ['nullable', 'string', 'max:1000']]);
        DB::transaction(function () use ($request, $data, $allocation): void {
            $record = DB::table('personal_allocations')->where('id', $allocation)->lockForUpdate()->first();
            abort_unless($record, 404);
            $this->authorize($request, (int) $record->owner_id);
            abort_unless((int) $record->version === (int) $data['version'] && $record->status !== 'published', 409, 'This allocation changed or is already published. Reload it.');
            $sources = $this->sources((int) $record->owner_id, (int) $record->year);
            $entries = $this->validatedEntries(json_decode($record->draft_payload, true), $sources);
            if ($entries === [] || collect($entries)->contains(fn ($entry) => $entry['weight'] === null || ! $entry['valid']) || abs(array_sum(array_column($entries, 'weight')) - 1) > 0.00005) {
                throw ValidationException::withMessages(['allocation' => 'Every source must be current and every weight specified, totaling exactly 100%.']);
            }
            $previous = $record->published_payload ? json_decode($record->published_payload, true) : null;
            $snapshot = ['entries' => $entries, 'score_at_publication' => $this->score($entries, $sources), 'previous_entries' => $previous, 'previous_score_at_revision' => $this->score($previous, $sources)];
            DB::table('personal_allocations')->where('id', $record->id)->update(['published_payload' => json_encode($entries), 'published_version' => $record->version + 1, 'version' => $record->version + 1, 'status' => 'published', 'updated_at' => now()]);
            DB::table('personal_allocation_versions')->insert(['allocation_id' => $record->id, 'actor_id' => $request->user()->id, 'version' => $record->version + 1, 'event' => 'published', 'snapshot' => json_encode($snapshot), 'review_note' => $data['review_note'] ?? null, 'created_at' => now()]);
        });

        return response()->json(['message' => 'The 100% personal allocation was approved and published.']);
    }
}
