<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
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
            if (! $due || substr((string) $due, 0, 10) >= $today) {
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
            self::send((int) $user->id, 'task-overdue:'.$task->id.':'.$today, 'overdue', ($review ? 'Overdue work awaits your review: ' : 'Your task is overdue: ').$task->title, ['workspace' => 'delivery', 'page' => $review ? 'reviews' : 'tasks', 'search' => $task->title]);
        }
        foreach (DB::table('work_assignments')->where('assignee_id', $user->id)->where('recipient_role', 'manager')->where('due_date', '<', $today)->where('confirmed_progress', '<', 100)->get() as $assignment) {
            $context = app(DeliveryController::class)->context($assignment);
            if ($context['valid']) {
                self::send((int) $user->id, 'activity-overdue:'.$assignment->id.':'.$assignment->delivery_generation.':'.$today, 'overdue', 'Assigned activity is overdue: '.$context['activity']['activity'], ['workspace' => 'delivery', 'page' => 'activities', 'search' => $context['activity']['activity']]);
            }
        }
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->is_active, 403);
        $data = $request->validate(['before' => ['nullable', 'integer', 'min:1']]);
        self::remind($request->user());
        $query = DB::table('work_notifications')->where('user_id', $request->user()->id);
        $unread = (clone $query)->whereNull('read_at')->count();
        $rows = $query->when($data['before'] ?? null, fn ($q, $id) => $q->where('id', '<', $id))->orderByDesc('id')->limit(31)->get();
        $more = $rows->count() > 30;
        $items = $rows->take(30)->map(fn ($row) => [...(array) $row, 'target' => json_decode($row->target, true)])->values();

        return response()->json(['items' => $items, 'unread' => $unread, 'next' => $more ? $items->last()['id'] : null]);
    }

    public function read(Request $request, ?int $notification = null): JsonResponse
    {
        abort_unless($request->user()?->is_active, 403);
        $query = DB::table('work_notifications')->where('user_id', $request->user()->id);
        if ($notification !== null) {
            abort_unless((clone $query)->where('id', $notification)->exists(), 404);
            $query->where('id', $notification);
        }
        $query->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['unread' => DB::table('work_notifications')->where('user_id', $request->user()->id)->whereNull('read_at')->count()]);
    }
}
