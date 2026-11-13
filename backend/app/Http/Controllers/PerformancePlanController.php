<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RequirePlanningAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use stdClass;

class PerformancePlanController extends Controller
{
    public function workspace(Request $request): JsonResponse
    {
        $editor = RequirePlanningAccess::canEdit($request->user());
        $memberships = $this->ownerMemberships($editor ? null : $request->user()->id);
        if ($editor) {
            $memberships = $memberships->whereIn('department_id', RequirePlanningAccess::departmentIds($request->user()))->values();
        }
        $ownerIds = $memberships->pluck('user_id')->unique();
        $plans = DB::table('performance_plans')
            ->when($editor, fn ($query) => $query->whereIn('owner_id', $ownerIds))
            ->when(! $editor, fn ($query) => $query->where('owner_id', $request->user()->id)->whereNotNull('published_revision'))
            ->orderByDesc('updated_at')->get()->map(function (stdClass $plan) use ($editor, $request): ?array {
                $document = $this->document($plan, ! $editor);

                if ($editor) {
                    $document = $this->scopedDocument($request, $document);
                    if ($document['kpas'] === [] && DB::table('performance_plan_items')->where('plan_id', $plan->id)->exists()) {
                        return null;
                    }
                }

                return [...$this->summary($plan, $document, $editor), 'can_edit' => $editor && RequirePlanningAccess::canManagePlan($request->user(), (int) $plan->id)];
            })->filter()->values();

        return response()->json([
            'can_edit' => $editor,
            'department_scoped' => $editor,
            'plans' => $plans,
            'owners' => DB::table('users')->whereIn('id', $ownerIds)->orderBy('name')
                ->get(['id', 'name', 'employee_number', 'designation']),
            'departments' => DB::table('departments')->whereIn('id', $editor ? RequirePlanningAccess::departmentIds($request->user()) : $memberships->pluck('department_id')->unique())
                ->orderBy('name')->get(['id', 'name', 'code', 'color']),
            'memberships' => $memberships,
        ]);
    }

    public function show(Request $request, int $plan): JsonResponse
    {
        $record = $this->findAuthorized($request, $plan);
        $editor = RequirePlanningAccess::canEdit($request->user());
        $document = $this->document($record, ! $editor);
        $canManage = $editor && RequirePlanningAccess::canManagePlan($request->user(), $plan);
        if ($editor) {
            $document = $this->scopedDocument($request, $document);
            abort_if($document['kpas'] === [] && DB::table('performance_plan_items')->where('plan_id', $plan)->exists(), 403);
        }
        $versions = DB::table('performance_plan_versions')
            ->join('users', 'users.id', '=', 'performance_plan_versions.actor_id')
            ->where('plan_id', $plan)->when(! $editor, fn ($query) => $query->where('event', 'published'))
            ->orderByDesc('version')->get(['performance_plan_versions.id', 'version', 'event', 'changes', 'performance_plan_versions.created_at', 'users.name as actor_name'])
            ->map(function (stdClass $version): stdClass {
                $version->changes = json_decode($version->changes, true);

                return $version;
            });

        return response()->json([
            'plan' => $document,
            'can_edit' => $canManage,
            'department_view_only' => $editor && ! $canManage,
            'published_revision' => $record->published_revision,
            'issues' => $canManage ? $this->publicationIssues($document) : [],
            'versions' => $editor && ! $canManage ? [] : $versions,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->createDraft($request, $request->all());
    }

    public function createDraft(Request $request, array $document, ?array $excelSource = null): JsonResponse
    {
        $this->requireEditor($request);
        $data = $this->validatedDocument($document);
        abort_unless(RequirePlanningAccess::documentInScope($request->user(), $data), 403, 'Choose a person and departments within your assigned editor scope.');
        $id = DB::transaction(function () use ($request, $data, $excelSource): int {
            DB::table('users')->where('id', $data['owner_id'])->lockForUpdate()->first();
            if (DB::table('performance_plans')->where('code', $data['code'])->exists()) {
                throw ValidationException::withMessages(['code' => 'This plan ID already exists.']);
            }
            if (DB::table('performance_plans')->where('owner_id', $data['owner_id'])->where('year', $data['year'])->exists()) {
                throw ValidationException::withMessages(['owner_id' => 'This person already has a plan for this year. Open the existing plan to review it.']);
            }
            $this->validateOwnership($data);
            $id = DB::table('performance_plans')->insertGetId([
                'code' => $data['code'], 'title' => $data['title'], 'owner_id' => $data['owner_id'],
                'created_by' => $request->user()->id, 'year' => $data['year'], 'status' => 'draft',
                'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
                'excel_source' => $excelSource ? json_encode($excelSource, JSON_THROW_ON_ERROR) : null,
            ]);
            $changes = $this->saveItems($id, $data);
            $this->recordVersion($request, $id, 'created', $changes);

            return $id;
        });

        return response()->json(['id' => $id], 201);
    }

    public function update(Request $request, int $plan): JsonResponse
    {
        $this->requireEditor($request);
        $this->findAuthorized($request, $plan);
        $this->requireManagedPlan($request, $plan);
        $data = $this->validatedDocument($request->all(), true);
        abort_unless(RequirePlanningAccess::documentInScope($request->user(), $data), 403);
        DB::transaction(function () use ($request, $plan, $data): void {
            $record = DB::table('performance_plans')->where('id', $plan)->lockForUpdate()->first();
            abort_unless($record, 404, 'Plan not found.');
            abort_unless((int) $record->revision === (int) $data['revision'], 409, 'Someone changed this plan. Reload it before saving again.');
            if ($record->code !== $data['code'] || (int) $record->owner_id !== $data['owner_id'] || (int) $record->year !== $data['year']) {
                throw ValidationException::withMessages(['code' => 'The plan ID, owner and year cannot change after creation.']);
            }
            $this->validateOwnership($data);
            $changes = $this->saveItems($plan, $data);
            if ($record->title !== $data['title']) {
                $changes[] = ['type' => 'updated', 'code' => $record->code, 'title' => 'Plan title', 'fields' => [
                    'title' => ['before' => $record->title, 'after' => $data['title']],
                ]];
            }
            if ($changes === []) {
                return;
            }
            DB::table('performance_plans')->where('id', $plan)->update([
                'title' => $data['title'], 'status' => 'draft', 'revision' => $record->revision + 1, 'updated_at' => now(),
            ]);
            $this->recordVersion($request, $plan, 'updated', $changes);
        });

        return response()->json(['id' => $plan]);
    }

    public function publish(Request $request, int $plan): JsonResponse
    {
        $this->requireEditor($request);
        $this->requireManagedPlan($request, $plan);
        $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($request, $plan): void {
            $record = DB::table('performance_plans')->where('id', $plan)->lockForUpdate()->first();
            abort_unless($record, 404);
            abort_unless((int) $record->revision === (int) $request->integer('revision'), 409, 'Someone changed this plan. Reload it before publishing.');
            abort_if($record->status === 'published', 409, 'This version is already published.');
            $document = $this->document($record);
            $memberships = $this->validateOwnership($document);
            $issues = $this->publicationIssues($document);
            if ($issues !== []) {
                throw ValidationException::withMessages(['publication' => $issues]);
            }
            DB::table('performance_plans')->where('id', $plan)->update([
                'status' => 'published', 'revision' => $record->revision + 1,
                'published_revision' => $record->revision + 1, 'published_at' => now(), 'updated_at' => now(),
            ]);
            $metadata = $record->excel_source === null ? [
                'execution_department_ids' => $memberships->pluck('department_id')->map(fn ($id) => (int) $id)->all(),
                'hof_department_ids' => $memberships->where('responsibility', 'hof')->pluck('department_id')->map(fn ($id) => (int) $id)->all(),
            ] : [];
            $this->recordVersion($request, $plan, 'published', [], $metadata);
        });

        return response()->json(['id' => $plan]);
    }

    public function deletionPreview(Request $request, int $plan): JsonResponse
    {
        $this->requireEditor($request);
        $record = $this->findAuthorized($request, $plan);
        $this->requireManagedPlan($request, $plan);
        $details = $this->deletionDetails($record);

        return response()->json([
            'plan' => ['id' => $plan, 'code' => $record->code, 'title' => $record->title, 'status' => $record->status],
            'revision' => (int) $record->revision, 'counts' => $details['counts'], 'deletion_token' => $details['token'],
        ]);
    }

    private function deletionDetails(stdClass $plan): array
    {
        $assignments = DB::table('work_assignments')->where('plan_id', $plan->id)->orderBy('id')
            ->get(['id', 'version', 'delivery_generation']);
        $assignmentIds = DB::table('work_assignments')->where('plan_id', $plan->id)->select('id');
        $taskIds = DB::table('delivery_tasks')->whereIn('assignment_id', $assignmentIds)->select('id');
        $tasks = DB::table('delivery_tasks')->whereIn('assignment_id', $assignmentIds)->orderBy('id')
            ->get(['id', 'version', 'parent_task_id', 'status', 'is_active']);
        $submissions = DB::table('task_submissions')->whereIn('task_id', $taskIds)->orderBy('id')->get(['id', 'decision']);
        $delegations = DB::table('kpi_delegations')->where('plan_id', $plan->id)->orderBy('id')->get(['id', 'version', 'generation']);
        $monthly = DB::table('monthly_kpi_submissions')->where('plan_id', $plan->id)->orderBy('id')->get(['id', 'status']);
        $counts = [
            'items' => DB::table('performance_plan_items')->where('plan_id', $plan->id)->count(),
            'revisions' => DB::table('performance_plan_versions')->where('plan_id', $plan->id)->count(),
            'assignments' => $assignments->count(), 'tasks' => $tasks->count(), 'submissions' => $submissions->count(),
            'kpi_assignments' => $delegations->count(), 'monthly_results' => $monthly->count(),
        ];

        return [
            'counts' => $counts,
            'token' => hash('sha256', json_encode([
                $plan->id, $plan->revision, $plan->published_revision, $counts,
                $assignments, $tasks, $submissions, $delegations, $monthly,
            ], JSON_THROW_ON_ERROR)),
            'assignment_ids' => $assignments->pluck('id')->all(), 'task_ids' => $tasks->pluck('id')->all(),
            'submission_ids' => $submissions->pluck('id')->all(), 'delegation_ids' => $delegations->pluck('id')->all(),
        ];
    }

    public function destroy(Request $request, int $plan): JsonResponse
    {
        $this->requireEditor($request);
        $this->requireManagedPlan($request, $plan);
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'], 'deletion_token' => ['nullable', 'string', 'size:64'],
        ]);

        DB::transaction(function () use ($request, $plan, $data): void {
            $record = DB::table('performance_plans')->where('id', $plan)->lockForUpdate()->first();
            abort_unless($record, 404, 'Plan not found.');
            abort_unless((int) $record->revision === (int) $data['revision'], 409,
                'Someone changed this plan. Refresh the list before removing it.');
            $details = $this->deletionDetails($record);
            $hasWork = $details['counts']['assignments'] > 0 || $details['counts']['kpi_assignments'] > 0 || $details['counts']['monthly_results'] > 0;
            if ($record->published_revision !== null || $hasWork || isset($data['deletion_token'])) {
                abort_unless(hash_equals($details['token'], $data['deletion_token'] ?? ''), 409,
                    'The plan or its linked work changed. Reload the deletion details and confirm again.');
            }
            $document = $this->document($record);
            $assignmentIds = DB::table('work_assignments')->where('plan_id', $plan)->select('id');
            $taskIds = DB::table('delivery_tasks')->whereIn('assignment_id', $assignmentIds)->select('id');
            DB::table('task_submissions')->whereIn('task_id', $taskIds)->delete();
            DB::table('delivery_tasks')->whereIn('assignment_id', $assignmentIds)->delete();
            DB::table('work_assignments')->where('plan_id', $plan)->delete();
            DB::table('kpi_delegations')->where('plan_id', $plan)->delete();
            DB::table('monthly_kpi_submissions')->where('plan_id', $plan)->delete();
            DB::table('monthly_kpi_reviewers')->where('plan_id', $plan)->delete();
            $this->removeWorkReferences($plan, $details);

            foreach (['activity', 'kpi', 'kpa'] as $kind) {
                DB::table('performance_plan_items')->where('plan_id', $plan)->where('kind', $kind)->delete();
            }
            DB::table('performance_plan_versions')->where('plan_id', $plan)->delete();
            DB::table('performance_plans')->where('id', $plan)->delete();

            DB::table('admin_activity')->insert([
                'actor_id' => $request->user()->id, 'action' => 'plan.removed', 'subject' => $record->code,
                'before_data' => json_encode([
                    'plan_id' => $plan, 'title' => $record->title, 'owner_id' => $record->owner_id,
                    'year' => $record->year, 'revision' => $record->revision,
                    'document' => $document, 'removed_records' => $details['counts'],
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        });

        return response()->json(['message' => 'Plan deleted.']);
    }

    private function removeWorkReferences(int $plan, array $details): void
    {
        $sources = [
            'hof' => [$plan], 'assignment' => $details['assignment_ids'], 'task' => $details['task_ids'],
            'kpi' => $details['delegation_ids'],
        ];
        $proposalIds = DB::table('personal_weight_proposals')->get(['id', 'source_key'])->filter(function ($proposal) use ($sources): bool {
            $parts = explode(':', $proposal->source_key);

            return in_array($parts[1] ?? null, $sources[$parts[0]] ?? []);
        })->pluck('id')->all();
        $events = [
            'assignment' => $details['assignment_ids'], 'activity-overdue' => $details['assignment_ids'],
            'hof-reviewer' => $details['assignment_ids'], 'task-assigned' => $details['task_ids'],
            'submitted' => $details['task_ids'], 'task-overdue' => $details['task_ids'],
            'reviewed' => $details['submission_ids'], 'kpi-assigned' => $details['delegation_ids'],
        ];
        $notificationIds = DB::table('work_notifications')->get(['id', 'event_key'])->filter(function ($notification) use ($events): bool {
            $parts = explode(':', $notification->event_key);

            return in_array($parts[1] ?? null, $events[$parts[0]] ?? []);
        })->pluck('id')->all();
        foreach (array_chunk($proposalIds, 500) as $ids) {
            DB::table('personal_weight_proposals')->whereIn('id', $ids)->delete();
        }
        foreach (array_chunk($notificationIds, 500) as $ids) {
            DB::table('work_notifications')->whereIn('id', $ids)->delete();
        }
    }

    private function requireEditor(Request $request): void
    {
        abort_unless(RequirePlanningAccess::canEdit($request->user()), 403, 'Only plan editors can change performance plans.');
    }

    private function findAuthorized(Request $request, int $id): stdClass
    {
        $record = DB::table('performance_plans')->find($id);
        abort_unless($record, 404);
        abort_unless((RequirePlanningAccess::canEdit($request->user()) && RequirePlanningAccess::ownerIds($request->user())->contains((int) $record->owner_id)) ||
            ($request->user()->system_role === 'member' && (int) $record->owner_id === $request->user()->id && $record->published_revision !== null), 403);

        return $record;
    }

    private function requireManagedPlan(Request $request, int $plan): void
    {
        abort_unless(RequirePlanningAccess::canManagePlan($request->user(), $plan), 403, 'Mixed-department plans are view-only outside your full department scope.');
    }

    private function scopedDocument(Request $request, array $document): array
    {
        $departments = RequirePlanningAccess::departmentIds($request->user());
        if (! RequirePlanningAccess::documentInScope($request->user(), $document)) {
            $document['excel_source'] = null;
        }
        $document['kpas'] = array_values(array_filter($document['kpas'], fn ($kpa) => $departments->contains((int) $kpa['department_id'])));

        return $document;
    }

    private function validatedDocument(array $document, bool $updating = false): array
    {
        $data = Validator::make($document, [
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'title' => ['required', 'string', 'max:180'],
            'owner_id' => ['required', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'year' => ['required', 'integer', 'between:2000,2100'],
            'revision' => [$updating ? 'required' : 'nullable', 'integer', 'min:1'],
            'kpas' => ['present', 'array', 'max:20'],
            'kpas.*.code' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'kpas.*.title' => ['required', 'string', 'max:1000'],
            'kpas.*.department_id' => ['required', 'integer', 'exists:departments,id'],
            'kpas.*.category' => ['nullable', 'string', 'max:100'],
            'kpas.*.weight' => ['nullable', 'numeric', 'between:0,1'],
            'kpas.*.notes' => ['nullable', 'string', 'max:1000'],
            'kpas.*.kpis' => ['present', 'array', 'max:30'],
            'kpas.*.kpis.*.code' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'kpas.*.kpis.*.title' => ['required', 'string', 'max:1000'],
            'kpas.*.kpis.*.weight' => ['nullable', 'numeric', 'between:0,1'],
            'kpas.*.kpis.*.target_value' => ['nullable', 'numeric', 'between:-9999999999999,9999999999999'],
            'kpas.*.kpis.*.target_unit' => ['nullable', Rule::in(['percent', 'count', 'LKR', 'days', 'hours', 'other'])],
            'kpas.*.kpis.*.frequency' => ['nullable', Rule::in(['Monthly', 'Quarterly', 'Annually', 'On completion'])],
            'kpas.*.kpis.*.direction' => ['nullable', Rule::in(['higher', 'lower'])],
            'kpas.*.kpis.*.notes' => ['nullable', 'string', 'max:1000'],
            'kpas.*.kpis.*.activities' => ['present', 'array', 'max:50'],
            'kpas.*.kpis.*.activities.*.code' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'kpas.*.kpis.*.activities.*.title' => ['required', 'string', 'max:1000'],
            'kpas.*.kpis.*.activities.*.weight' => ['nullable', 'numeric', 'between:0,1'],
            'kpas.*.kpis.*.activities.*.due_date' => ['nullable', 'date_format:Y-m-d'],
            'kpas.*.kpis.*.activities.*.notes' => ['nullable', 'string', 'max:1000'],
        ])->validate();
        $data['owner_id'] = (int) $data['owner_id'];
        $data['year'] = (int) $data['year'];
        $data['code'] = strtoupper($data['code']);
        $codes = [];
        $normalize = function (array &$item) use (&$codes): void {
            $item['code'] = strtoupper($item['code']);
            if (isset($codes[$item['code']])) {
                throw ValidationException::withMessages(['kpas' => 'Each KPA, KPI and activity must have a different item ID.']);
            }
            $codes[$item['code']] = true;
            if (count($codes) > 1000) {
                throw ValidationException::withMessages(['kpas' => 'A plan can contain up to 1,000 items.']);
            }
            $item['weight'] = isset($item['weight']) ? round((float) $item['weight'], 4) : null;
            if (isset($item['target_value'])) {
                $item['target_value'] = round((float) $item['target_value'], 4);
            }
        };
        foreach ($data['kpas'] as &$kpa) {
            $normalize($kpa);
            $kpa['department_id'] = (int) $kpa['department_id'];
            foreach ($kpa['kpis'] as &$kpi) {
                $normalize($kpi);
                foreach ($kpi['activities'] as &$activity) {
                    $normalize($activity);
                }
                unset($activity);
            }
            unset($kpi);
        }
        unset($kpa);

        return $data;
    }

    private function ownerMemberships(?int $owner = null): Collection
    {
        return DB::table('department_memberships')
            ->join('departments', 'departments.id', '=', 'department_memberships.department_id')
            ->join('users', 'users.id', '=', 'department_memberships.user_id')
            ->where('users.system_role', 'member')->whereIn('responsibility', ['hof', 'manager', 'employee'])
            ->where('departments.is_active', true)->where('users.is_active', true)
            ->when($owner !== null, fn ($query) => $query->where('user_id', $owner))
            ->get(['user_id', 'department_id', 'responsibility']);
    }

    private function validateOwnership(array $document): Collection
    {
        $memberships = $this->ownerMemberships($document['owner_id']);
        if ($memberships->isEmpty()) {
            throw ValidationException::withMessages(['owner_id' => 'Select an active person with an active department assignment.']);
        }
        foreach ($document['kpas'] as $kpa) {
            if (! $memberships->contains('department_id', $kpa['department_id'])) {
                throw ValidationException::withMessages(['kpas' => 'Each KPA must belong to one of the plan owner’s active departments.']);
            }
        }

        return $memberships;
    }

    private function publicationIssues(array $document): array
    {
        $issues = [];
        $checkWeights = function (array $items, string $label) use (&$issues): void {
            if ($items === []) {
                $issues[] = $label.': add at least one item.';
            } elseif (count(array_filter($items, fn ($item) => $item['weight'] === null)) > 0) {
                $issues[] = $label.': enter every weight.';
            } elseif (abs(array_sum(array_column($items, 'weight')) - 1) > 0.00005) {
                $issues[] = $label.': weights must total 100%.';
            }
        };
        $checkWeights($document['kpas'], 'Personal plan KPA allocation');
        foreach ($document['kpas'] as $kpa) {
            $checkWeights($kpa['kpis'], $kpa['code'].' KPI allocation');
            foreach ($kpa['kpis'] as $kpi) {
                if ($kpi['target_value'] === null || ! $kpi['target_unit']) {
                    $issues[] = $kpi['code'].': enter a target value and unit.';
                }
                if (! $kpi['frequency'] || ! $kpi['direction']) {
                    $issues[] = $kpi['code'].': choose monitoring frequency and target direction.';
                }
                $checkWeights($kpi['activities'], $kpi['code'].' activity allocation');
            }
        }

        return $issues;
    }

    private function saveItems(int $planId, array $document): array
    {
        $existing = DB::table('performance_plan_items')->where('plan_id', $planId)->get()->keyBy('code');
        $seen = [];
        $changes = [];
        $write = function (array $item, string $kind, int $departmentId, ?int $parentId, int $order) use ($planId, $existing, &$seen, &$changes): int {
            $attributes = [
                'parent_id' => $parentId, 'department_id' => $departmentId, 'kind' => $kind, 'title' => $item['title'],
                'category' => $item['category'] ?? null, 'weight' => $item['weight'],
                'target_value' => $item['target_value'] ?? null, 'target_unit' => $item['target_unit'] ?? null,
                'frequency' => $item['frequency'] ?? null, 'direction' => $item['direction'] ?? null,
                'due_date' => $item['due_date'] ?? null, 'notes' => $item['notes'] ?? null,
                'display_order' => $order, 'is_active' => true,
            ];
            $old = $existing->get($item['code']);
            if ($old && $old->kind !== $kind) {
                throw ValidationException::withMessages(['kpas' => 'An existing item ID cannot change between KPA, KPI and activity.']);
            }
            $fields = [];
            if ($old) {
                foreach ($attributes as $field => $value) {
                    $before = $old->{$field};
                    $same = in_array($field, ['weight', 'target_value'], true)
                        ? (($before === null && $value === null) || ($before !== null && $value !== null && abs((float) $before - (float) $value) < 0.00001))
                        : ((string) ($before ?? '') === (string) ($value ?? ''));
                    if (! $same) {
                        $fields[$field] = ['before' => $before, 'after' => $value];
                    }
                }
            }
            $changed = ! $old || $fields !== [];
            if ($old && $changed) {
                DB::table('performance_plan_items')->where('id', $old->id)->update([...$attributes, 'updated_at' => now(), 'changed_at' => now()]);
                $id = (int) $old->id;
            } elseif ($old) {
                $id = (int) $old->id;
            } else {
                $id = DB::table('performance_plan_items')->insertGetId([
                    ...$attributes, 'plan_id' => $planId, 'code' => $item['code'],
                    'created_at' => now(), 'updated_at' => now(), 'changed_at' => now(),
                ]);
            }
            if ($changed) {
                $changes[] = ['type' => $old && $old->is_active ? 'updated' : 'added', 'code' => $item['code'], 'title' => $item['title'], 'fields' => $fields];
            }
            $seen[] = $item['code'];

            return $id;
        };
        foreach ($document['kpas'] as $i => $kpa) {
            $kpaId = $write($kpa, 'kpa', $kpa['department_id'], null, $i);
            foreach ($kpa['kpis'] as $j => $kpi) {
                $kpiId = $write($kpi, 'kpi', $kpa['department_id'], $kpaId, $j);
                foreach ($kpi['activities'] as $k => $activity) {
                    $write($activity, 'activity', $kpa['department_id'], $kpiId, $k);
                }
            }
        }
        foreach ($existing as $old) {
            if ($old->is_active && ! in_array($old->code, $seen, true)) {
                DB::table('performance_plan_items')->where('id', $old->id)->update(['is_active' => false, 'changed_at' => now(), 'updated_at' => now()]);
                $changes[] = ['type' => 'removed', 'code' => $old->code, 'title' => $old->title, 'fields' => []];
            }
        }

        return $changes;
    }

    private function document(stdClass $plan, bool $published = false): array
    {
        if ($published) {
            $snapshot = DB::table('performance_plan_versions')->where('plan_id', $plan->id)->where('version', $plan->published_revision)->value('snapshot');
            abort_unless($snapshot, 404);

            return json_decode($snapshot, true);
        }
        $items = DB::table('performance_plan_items')->where('plan_id', $plan->id)->where('is_active', true)->orderBy('display_order')->get();
        $convert = fn (stdClass $item): array => [
            'code' => $item->code, 'title' => $item->title, 'department_id' => (int) $item->department_id,
            'category' => $item->category, 'weight' => $item->weight === null ? null : (float) $item->weight,
            'target_value' => $item->target_value === null ? null : (float) $item->target_value,
            'target_unit' => $item->target_unit, 'frequency' => $item->frequency, 'direction' => $item->direction,
            'due_date' => $item->due_date ? substr((string) $item->due_date, 0, 10) : null, 'notes' => $item->notes,
            'changed_at' => $item->changed_at,
        ];
        $kpas = [];
        foreach ($items->where('kind', 'kpa') as $kpa) {
            $kpis = [];
            foreach ($items->where('kind', 'kpi')->where('parent_id', $kpa->id) as $kpi) {
                $kpis[] = [...$convert($kpi), 'activities' => $items->where('kind', 'activity')->where('parent_id', $kpi->id)->map($convert)->values()->all()];
            }
            $kpas[] = [...$convert($kpa), 'kpis' => $kpis];
        }

        return [
            'id' => (int) $plan->id, 'code' => $plan->code, 'title' => $plan->title, 'owner_id' => (int) $plan->owner_id,
            'year' => (int) $plan->year, 'status' => $plan->status, 'revision' => (int) $plan->revision,
            'published_at' => $plan->published_at, 'kpas' => $kpas,
            'excel_source' => $plan->excel_source ? json_decode($plan->excel_source, true) : null,
        ];
    }

    private function summary(stdClass $plan, array $document, bool $editor): array
    {
        $kpis = collect($document['kpas'])->flatMap(fn ($kpa) => $kpa['kpis']);

        return [
            'id' => (int) $plan->id, 'code' => $plan->code, 'title' => $document['title'], 'year' => (int) $plan->year,
            'owner_id' => (int) $plan->owner_id, 'status' => $editor ? $plan->status : 'published',
            'revision' => $document['revision'], 'published_revision' => $plan->published_revision,
            'updated_at' => $editor ? $plan->updated_at : $plan->published_at,
            'kpa_count' => count($document['kpas']), 'kpi_count' => $kpis->count(),
            'activity_count' => $kpis->sum(fn ($kpi) => count($kpi['activities'])),
            'weight_total' => array_sum(array_column($document['kpas'], 'weight')),
            'imported' => $plan->excel_source !== null,
        ];
    }

    private function recordVersion(Request $request, int $id, string $event, array $changes, array $metadata = []): void
    {
        $record = DB::table('performance_plans')->find($id);
        DB::table('performance_plan_versions')->insert([
            'plan_id' => $id, 'actor_id' => $request->user()->id, 'version' => $record->revision,
            'event' => $event, 'snapshot' => json_encode([...$this->document($record), ...$metadata]),
            'changes' => json_encode($changes), 'created_at' => now(),
        ]);
        DB::table('admin_activity')->insert([
            'actor_id' => $request->user()->id, 'action' => 'plan.'.$event, 'subject' => $record->code.' — '.$record->title,
            'after_data' => json_encode(['plan_id' => $id, 'revision' => $record->revision, 'changes' => $changes]), 'created_at' => now(),
        ]);
    }
}
