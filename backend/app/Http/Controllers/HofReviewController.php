<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RequirePlanningAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HofReviewController extends Controller
{
    public static function eligible(int $reviewer, int $owner): bool
    {
        return $reviewer !== $owner && DB::table('users as u')->join('department_memberships as m', 'm.user_id', '=', 'u.id')->join('departments as d', 'd.id', '=', 'm.department_id')
            ->where('u.id', $reviewer)->where('u.is_active', true)->where('u.system_role', 'member')->where('m.responsibility', 'hof')->where('d.is_active', true)->exists();
    }

    public function workspace(Request $request): JsonResponse
    {
        abort_unless(RequirePlanningAccess::canEdit($request->user()), 403);
        $rows = [];
        foreach (DB::table('work_assignments as a')->join('users as u', 'u.id', '=', 'a.owner_id')->whereColumn('a.owner_id', 'a.assignee_id')->select('a.*', 'u.name as owner_name')->get() as $assignment) {
            $task = DB::table('delivery_tasks')->where('assignment_id', $assignment->id)->where('generation', $assignment->delivery_generation)->where('is_active', true)->first();
            $rows[] = [...(array) $assignment, 'task' => $task, 'activity' => app(DeliveryController::class)->context($assignment)['activity']['activity'] ?? $assignment->activity_code];
        }
        $reviewers = DB::table('users as u')->join('department_memberships as m', 'm.user_id', '=', 'u.id')->join('departments as d', 'd.id', '=', 'm.department_id')->where('u.is_active', true)->where('u.system_role', 'member')->where('m.responsibility', 'hof')->where('d.is_active', true)->select('u.id', 'u.name')->distinct()->orderBy('u.name')->get();

        return response()->json(['rows' => $rows, 'reviewers' => $reviewers]);
    }

    public function assign(Request $request, int $assignment): JsonResponse
    {
        abort_unless(RequirePlanningAccess::canEdit($request->user()), 403);
        $data = $request->validate(['version' => ['required', 'integer'], 'reviewer_id' => ['required', 'integer']]);
        DB::transaction(function () use ($request, $data, $assignment): void {
            $reference = DB::table('work_assignments')->find($assignment);
            abort_unless($reference, 404);
            DB::table('performance_plans')->where('id', $reference->plan_id)->lockForUpdate()->first();
            $record = DB::table('work_assignments')->where('id', $assignment)->lockForUpdate()->first();
            abort_unless((int) $record->owner_id === (int) $record->assignee_id, 422);
            abort_unless((int) $record->version === (int) $data['version'], 409, 'Assignment changed. Refresh before saving.');
            abort_unless(self::eligible((int) $data['reviewer_id'], (int) $record->owner_id), 422, 'Choose another active HOF as reviewer.');
            $activity = app(DeliveryController::class)->context($record)['activity'];
            abort_unless($activity && $record->source_signature === $activity['source_signature'] && app(HofWorkController::class)->recipientRole((int) $record->owner_id, (int) $record->owner_id, (int) $record->department_id) === $record->recipient_role, 409, 'The HOF must confirm the current assignment first.');
            $tasks = DB::table('delivery_tasks')->where('assignment_id', $assignment)->where('generation', $record->delivery_generation)->where('is_active', true);
            $old = (clone $tasks)->first();
            if ($old && (int) $old->reviewer_id === (int) $data['reviewer_id']) {
                return;
            }
            $tasks->update(['is_active' => false, 'updated_at' => now()]);
            DB::table('delivery_tasks')->insert(['assignment_id' => $assignment, 'generation' => $record->delivery_generation, 'manager_id' => $record->owner_id, 'assignee_id' => $record->owner_id, 'reviewer_id' => $data['reviewer_id'], 'title' => mb_substr($activity['activity'], 0, 500), 'weight' => 1, 'due_date' => $record->due_date, 'instructions' => $record->instructions, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('work_assignments')->where('id', $assignment)->update(['reported_progress' => 0, 'confirmed_progress' => 0, 'version' => $record->version + 1, 'updated_at' => now()]);
            DB::table('admin_activity')->insert(['actor_id' => $request->user()->id, 'action' => 'hof.reviewer_assigned', 'subject' => $record->activity_code, 'after_data' => json_encode($data), 'created_at' => now()]);
        });

        return response()->json(['message' => 'Reviewer assigned. Previous review history is retained; a new submission is required.']);
    }
}
