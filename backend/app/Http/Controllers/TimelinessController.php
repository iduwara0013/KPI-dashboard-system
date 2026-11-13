<?php

namespace App\Http\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TimelinessController extends Controller
{
    public function effectiveDue(object $task, object $assignment, ?Collection $tasks = null): ?string
    {
        while (true) {
            if ($task->due_date) {
                return substr((string) $task->due_date, 0, 10);
            }
            if ($task->parent_task_id === null) {
                break;
            }
            $parent = $tasks ? $tasks->get($task->parent_task_id) : DB::table('delivery_tasks')->find($task->parent_task_id);
            if (! $parent || (int) $parent->id >= (int) $task->id) {
                break;
            }
            $task = $parent;
        }

        return $assignment->due_date ? substr((string) $assignment->due_date, 0, 10) : null;
    }

    public function lateDays(string $due, string $submitted): int
    {
        $day = (new DateTimeImmutable($submitted, new DateTimeZone(config('app.timezone', 'UTC'))))
            ->setTimezone(new DateTimeZone('Asia/Colombo'))->format('Y-m-d');

        return max(0, (int) (new DateTimeImmutable($due))->diff(new DateTimeImmutable($day))->format('%r%a'));
    }

    public function percentage(int $days): int
    {
        return match (true) {
            $days <= 0 => 100,
            $days <= 3 => 90,
            $days <= 7 => 75,
            default => 50,
        };
    }

    public function combine(array $items): array
    {
        $assessed = $scheduled = $points = $estimated = 0;
        foreach ($items as $item) {
            $weight = (float) $item['weight'];
            $timing = $item['timeliness'] ?? [];
            $finalWeight = $weight * ($timing['assessed_weight'] ?? 0);
            $dueWeight = $weight * ($timing['scheduled_weight'] ?? 0);
            $assessed += $finalWeight;
            $scheduled += $dueWeight;
            $points += $finalWeight * ($timing['score'] ?? 0);
            $estimated += $dueWeight * ($timing['estimate'] ?? 0);
        }

        return ['score' => $assessed > 0 ? round($points / $assessed, 2) : null,
            'estimate' => $scheduled > 0 ? round($estimated / $scheduled, 2) : null,
            'assessed_weight' => $assessed, 'scheduled_weight' => $scheduled,
            'coverage' => $scheduled > 0 ? round($assessed / $scheduled * 100, 2) : 0];
    }

    public function forAssignment(object $assignment, ?Collection $providedTasks = null, ?Collection $providedSubmissions = null, string $source = 'planned'): array
    {
        $request = request();
        $cacheKey = 'timeliness.'.$source.'.'.$assignment->id.'.'.$assignment->delivery_generation;
        if ($request->isMethod('GET') && $request->attributes->has($cacheKey)) {
            return $request->attributes->get($cacheKey);
        }
        $tasks = $providedTasks?->sortByDesc('id')->keyBy('id') ?? DB::table('delivery_tasks')->where('assignment_id', $assignment->id)
            ->where('generation', $assignment->delivery_generation)->where('is_active', true)->orderByDesc('id')->get()->keyBy('id');
        $submissions = ($providedSubmissions ?? DB::table('task_submissions')->whereIn('task_id', $tasks->keys())->orderByDesc('id')->get())->sortByDesc('id')->groupBy('task_id');
        $timings = [];
        foreach ($tasks as $task) {
            $children = $tasks->where('parent_task_id', $task->id);
            if ($children->isNotEmpty()) {
                $timings[$task->id] = abs($children->sum('weight') - 1) < 0.00005
                    ? $this->combine($children->map(fn ($child) => ['weight' => $child->weight, 'timeliness' => $timings[$child->id]])->all())
                    : $this->combine([]);

                continue;
            }
            $latest = $submissions->get($task->id, collect())->first();
            $submitted = $latest && (in_array($task->status, ['approved', 'submitted'], true) || ($task->status === 'needs_changes' && $latest->retain_timeliness))
                ? ($latest->timeliness_submitted_at ?? $latest->submitted_at) : null;
            $due = $submitted && $latest->timeliness_submitted_at !== null
                ? ($latest->timeliness_due_date ? substr((string) $latest->timeliness_due_date, 0, 10) : null)
                : $this->effectiveDue($task, $assignment, $tasks);
            $days = $due ? $this->lateDays($due, $submitted ?? now()->toDateTimeString()) : null;
            $approved = $task->status === 'approved' && $latest?->decision === 'approved' && $submitted !== null;
            $score = $days === null ? null : $this->percentage($days);
            $timings[$task->id] = [
                'score' => $approved ? $score : null, 'estimate' => $score,
                'assessed_weight' => $approved && $due ? 1 : 0, 'scheduled_weight' => $due ? 1 : 0,
                'coverage' => $approved && $due ? 100 : 0, 'late_days' => $days,
                'due_date' => $due, 'submitted_at' => $submitted,
                'status' => ! $due ? 'no_deadline' : ($approved ? 'approved' : ($task->status === 'submitted' ? 'awaiting_review' : 'provisional')),
                'legacy' => $latest && $latest->timeliness_submitted_at === null,
            ];
            if ($source === 'planned') {
                $timings[$task->id] = app(WorkChangeController::class)->replacementTiming((int) $task->id, $timings[$task->id]);
            }
        }
        $roots = $tasks->whereNull('parent_task_id');

        $result = ['tasks' => $timings, 'summary' => abs($roots->sum('weight') - 1) < 0.00005
            ? $this->combine($roots->map(fn ($task) => ['weight' => $task->weight, 'timeliness' => $timings[$task->id]])->all()) : $this->combine([])];
        if ($request->isMethod('GET')) {
            $request->attributes->set($cacheKey, $result);
        }

        return $result;
    }
}
