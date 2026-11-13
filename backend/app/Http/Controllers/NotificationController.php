<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class NotificationController extends Controller
{
    private array $assignmentStates = [];

    private array $taskStates = [];

    private array $monthlyStates = [];

    private function assignmentState(int $id): array
    {
        if (! array_key_exists($id, $this->assignmentStates)) {
            $record = DB::table('work_assignments')->find($id);
            $this->assignmentStates[$id] = [$record, $record && app(DeliveryController::class)->context($record)['valid']];
        }

        return $this->assignmentStates[$id];
    }

    public static function send(int $user, string $key, string $type, string $message, array $target): void
    {
        if (! DB::table('users')->where('id', $user)->where('is_active', true)->exists()) {
            return;
        }
        DB::table('work_notifications')->upsert([[
            'user_id' => $user, 'event_key' => $key, 'type' => $type, 'message' => mb_substr($message, 0, 700),
            'target' => json_encode($target), 'read_at' => null, 'created_at' => now(),
        ]], ['user_id', 'event_key'], ['event_key']);
    }

    public static function remind(object $user): void
    {
        if (! $user->is_active) {
            return;
        }
        $today = now('Asia/Colombo')->toDateString();
        if ($user->system_role === 'md') {
            $counts = [];
            foreach (DB::table('work_assignments')->where('due_date', '<', $today)->where('confirmed_progress', '<', 100)->get() as $assignment) {
                $context = app(DeliveryController::class)->context($assignment);
                if ($context['valid']) {
                    $year = $context['activity']['year'];
                    $counts[$year] = ($counts[$year] ?? 0) + 1;
                }
            }
            foreach ($counts as $year => $count) {
                self::send((int) $user->id, 'md-overdue:'.$year.':'.$today, 'overdue', $count.' assigned activities are overdue across the organization for '.$year.'.', ['workspace' => 'md', 'page' => 'work', 'filter' => 'overdue', 'year' => $year]);
            }

            return;
        }
        if ($user->system_role !== 'member') {
            return;
        }
        $tasks = DB::table('delivery_tasks as t')->join('work_assignments as a', 'a.id', '=', 't.assignment_id')
            ->whereColumn('t.generation', 'a.delivery_generation')->where('t.is_active', true)->where('t.status', '!=', 'approved')
            ->where(fn ($q) => $q->where('t.assignee_id', $user->id)->orWhere('t.reviewer_id', $user->id))
            ->select('t.*', 'a.due_date as parent_due')->get();
        foreach ($tasks as $task) {
            $due = $task->due_date ?: $task->parent_due;
            if (! $due || substr((string) $due, 0, 10) > now('Asia/Colombo')->addDays(3)->toDateString()) {
                continue;
            }
            $assignment = DB::table('work_assignments')->find($task->assignment_id);
            if (! app(DeliveryController::class)->context($assignment)['valid'] || ! app(DeliveryController::class)->taskEligible($task, $assignment)) {
                continue;
            }
            $selfConfirm = app(DeliveryController::class)->selfConfirmable($task, $assignment);
            if ($selfConfirm && (int) $task->assignee_id !== (int) $user->id) {
                continue;
            }
            $review = ! $selfConfirm && (int) $task->reviewer_id === (int) $user->id && (int) $task->assignee_id !== (int) $user->id;
            if ($review && $task->status !== 'submitted') {
                continue;
            }
            $overdue = substr((string) $due, 0, 10) < $today;
            self::send((int) $user->id, ($overdue ? 'task-overdue:' : 'task-due:').$task->id.':'.$today, $overdue ? 'overdue' : 'due_soon', ($overdue ? ($review ? 'Overdue work awaits your review: ' : 'Your task is overdue: ') : ($review ? 'Review due soon: ' : 'Task due soon: ')).$task->title, ['workspace' => 'delivery', 'page' => $review ? 'reviews' : 'tasks', 'search' => $task->title]);
        }
        foreach (DB::table('work_assignments')->where('assignee_id', $user->id)->where('recipient_role', 'manager')->where('due_date', '<=', now('Asia/Colombo')->addDays(3)->toDateString())->where('confirmed_progress', '<', 100)->get() as $assignment) {
            $context = app(DeliveryController::class)->context($assignment);
            if ($context['valid']) {
                $overdue = substr((string) $assignment->due_date, 0, 10) < $today;
                self::send((int) $user->id, ($overdue ? 'activity-overdue:' : 'activity-due:').$assignment->id.':'.$assignment->delivery_generation.':'.$today, $overdue ? 'overdue' : 'due_soon', ($overdue ? 'Assigned activity is overdue: ' : 'Assigned activity is due soon: ').$context['activity']['activity'], ['workspace' => 'delivery', 'page' => 'activities', 'search' => $context['activity']['activity']]);
            }
        }
    }

    private static function target(object $row): array
    {
        $target = json_decode($row->target, true) ?: [];
        $parts = explode(':', $row->event_key);
        $id = isset($parts[1]) && ctype_digit($parts[1]) ? (int) $parts[1] : null;
        if (in_array($parts[0], ['task-assigned', 'submitted', 'task-overdue', 'task-due'], true)) {
            $target['task_id'] = $target['task_id'] ?? $id;
        } elseif ($parts[0] === 'reviewed') {
            $target['task_id'] = $target['task_id'] ?? DB::table('task_submissions')->where('id', $id)->value('task_id');
        } elseif (in_array($parts[0], ['assignment', 'activity-overdue', 'activity-due'], true)) {
            $target['assignment_id'] = $target['assignment_id'] ?? $id;
        } elseif ($parts[0] === 'kpi-assigned') {
            $target['delegation_id'] = $target['delegation_id'] ?? $id;
        }

        return $target;
    }

    private function describe(object $row): array
    {
        $target = self::target($row);
        $available = ! empty($target['workspace']);
        $required = false;
        $state = 'Information';
        if (($target['workspace'] ?? '') === 'extra' && ! empty($target['extra_task_id'])) {
            $extra = app(ExtraWorkController::class)->notificationState((int) $target['extra_task_id'], (int) $row->user_id, ($target['page'] ?? '') === 'reviews', isset($target['extra_submission_id']) ? (int) $target['extra_submission_id'] : null);
            $available = $extra['available'];
            $required = $extra['required'];
            $state = $extra['state'];
        } elseif (! empty($target['task_id'])) {
            $id = (int) $target['task_id'];
            if (! array_key_exists($id, $this->taskStates)) {
                $task = DB::table('delivery_tasks')->find($id);
                [$assignment, $valid] = $task ? $this->assignmentState((int) $task->assignment_id) : [null, false];
                $eligible = $valid && app(DeliveryController::class)->taskEligible($task, $assignment);
                $latest = $task ? DB::table('task_submissions')->where('task_id', $task->id)->orderByDesc('id')->first() : null;
                $this->taskStates[$id] = [$task, $eligible, $latest];
            }
            [$task, $eligible, $latest] = $this->taskStates[$id];
            $review = ($target['page'] ?? '') === 'reviews';
            $available = $eligible && ($review ? (int) $task->reviewer_id === (int) $row->user_id : (int) $task->assignee_id === (int) $row->user_id);
            if ($available) {
                $state = $task->status;
                if ($review) {
                    $required = $task->status === 'submitted' && $latest?->decision === 'pending'
                        && (empty($target['submission_id']) || (int) $target['submission_id'] === (int) $latest->id);
                    $target['submission_id'] = $target['submission_id'] ?? $latest?->id;
                } else {
                    $required = ! in_array($task->status, ['submitted', 'approved'], true);
                }
            }
        } elseif (! empty($target['assignment_id'])) {
            [$assignment, $valid] = $this->assignmentState((int) $target['assignment_id']);
            $available = $valid && (int) $assignment->assignee_id === (int) $row->user_id;
            if ($available) {
                $required = (float) $assignment->confirmed_progress < 100;
                $state = $required ? 'In progress' : 'Completed';
                if (($target['page'] ?? '') === 'tasks') {
                    $target['task_id'] = DB::table('delivery_tasks')->where('assignment_id', $assignment->id)
                        ->where('generation', $assignment->delivery_generation)->where('is_active', true)
                        ->where('assignee_id', $row->user_id)->whereNull('parent_task_id')->value('id');
                }
            }
        } elseif (! empty($target['delegation_id'])) {
            $delegation = DB::table('kpi_delegations')->find($target['delegation_id']);
            $assignments = $delegation ? DB::table('work_assignments')->where('delegation_id', $delegation->id)->where('assignee_id', $row->user_id)->get() : collect();
            $available = $delegation && (int) $delegation->assignee_id === (int) $row->user_id && $assignments->contains(fn ($assignment) => app(DeliveryController::class)->context($assignment)['valid']);
            $required = $available && $assignments->contains(fn ($assignment) => (float) $assignment->confirmed_progress < 100);
            $state = $required ? 'In progress' : 'Completed';
        }
        if (($target['workspace'] ?? '') === 'results') {
            $period = $target['period'] ?? '';
            if (! isset($this->monthlyStates[$period])) {
                $contextRequest = Request::create('/api/kpi-results/workspace', 'GET', ['period' => $period]);
                $contextRequest->setUserResolver(fn () => request()->user());
                $this->monthlyStates[$period] = app(MonthlyKpiResultController::class)->workspace($contextRequest)->getData(true)['rows'];
            }
            $result = collect($this->monthlyStates[$period])->first(fn ($result) => (int) $result['plan_id'] === (int) $target['plan_id'] && $result['kpi_code'] === $target['kpi_code']);
            $available = $result !== null;
            $state = $result['status'] ?? 'Unavailable';
            $required = $result && (int) $result['attempt'] === (int) ($target['attempt'] ?? 0)
                && ($row->type === 'kpi_submission' ? $result['can_review'] : ($row->type === 'kpi_needs_correction' && $result['can_submit']));
        }
        $category = match ($row->type) {
            'submission', 'kpi_submission' => 'reviews',
            'approved', 'needs_changes', 'kpi_approved', 'kpi_needs_correction' => 'decisions',
            'overdue', 'due_soon' => 'deadlines',
            default => 'assignments',
        };

        return [...(array) $row, 'target' => $target, 'category' => $category, 'action_required' => $required,
            'available' => (bool) $available, 'work_state' => $available ? $state : 'No longer available'];
    }

    public function index(Request $request): JsonResponse
    {
        $this->assignmentStates = [];
        $this->taskStates = [];
        $this->monthlyStates = [];
        abort_unless($request->user()?->is_active, 403);
        $data = $request->validate([
            'before' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'between:30,300'],
            'view' => ['nullable', Rule::in(['all', 'unread', 'actions', 'archived'])],
            'category' => ['nullable', Rule::in(['all', 'assignments', 'reviews', 'decisions', 'deadlines'])],
        ]);
        $query = DB::table('work_notifications')->where('user_id', $request->user()->id);
        $unread = (clone $query)->whereNull('archived_at')->whereNull('read_at')->count();
        $archived = ($data['view'] ?? '') === 'archived';
        $query = $archived ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at');
        if (($data['view'] ?? '') === 'unread') {
            $query->whereNull('read_at');
        }
        $types = ['assignments' => ['assignment'], 'reviews' => ['submission', 'kpi_submission'], 'decisions' => ['approved', 'needs_changes', 'kpi_approved', 'kpi_needs_correction'], 'deadlines' => ['overdue', 'due_soon']];
        if (isset($types[$data['category'] ?? ''])) {
            $query->whereIn('type', $types[$data['category']]);
        }
        $limit = (int) ($data['limit'] ?? 30);
        $rows = $query->when($data['before'] ?? null, fn ($q, $id) => $q->where('id', '<', $id))->orderByDesc('id')->lazy(100)
            ->map(fn ($row) => $this->describe($row))
            ->filter(fn ($row) => ($data['view'] ?? '') !== 'actions' || $row['action_required'])->take($limit + 1)->collect();
        $items = $rows->take($limit)->values();

        return response()->json(['items' => $items, 'unread' => $unread, 'next' => $rows->count() > $limit ? $items->last()['id'] : null]);
    }

    public function read(Request $request, ?int $notification = null): JsonResponse
    {
        abort_unless($request->user()?->is_active, 403);
        $query = DB::table('work_notifications')->where('user_id', $request->user()->id);
        if ($notification !== null) {
            abort_unless((clone $query)->where('id', $notification)->exists(), 404);
            $query->where('id', $notification);
        } else {
            $query->whereNull('archived_at');
        }
        $query->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['unread' => DB::table('work_notifications')->where('user_id', $request->user()->id)->whereNull('archived_at')->whereNull('read_at')->count()]);
    }

    public function archive(Request $request, int $notification): JsonResponse
    {
        abort_unless($request->user()?->is_active, 403);
        $data = $request->validate(['archived' => ['required', 'boolean']]);
        $query = DB::table('work_notifications')->where('user_id', $request->user()->id)->where('id', $notification);
        abort_unless((clone $query)->exists(), 404);
        $query->update(['archived_at' => $data['archived'] ? now() : null]);

        return response()->json(['unread' => DB::table('work_notifications')->where('user_id', $request->user()->id)->whereNull('archived_at')->whereNull('read_at')->count()]);
    }
}
