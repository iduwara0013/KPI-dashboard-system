<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_tasks', function (Blueprint $table): void {
            $table->string('work_kind', 16)->default('task');
        });
        foreach (DB::table('work_assignments')->orderBy('id')->get() as $assignment) {
            $snapshot = DB::table('performance_plan_versions')->where('plan_id', $assignment->plan_id)->where('version', $assignment->source_revision)->value('snapshot');
            $title = null;
            foreach (json_decode($snapshot ?? '{}', true)['kpas'] ?? [] as $kpa) {
                foreach ($kpa['kpis'] as $kpi) {
                    foreach ($kpi['activities'] as $activity) {
                        if ($activity['code'] === $assignment->activity_code) {
                            $title = $activity['title'];
                        }
                    }
                }
            }
            $tasks = DB::table('delivery_tasks')->where('assignment_id', $assignment->id)->where('generation', $assignment->delivery_generation)->where('is_active', true)->get()->keyBy('id');
            foreach ($tasks as $task) {
                if ((int) $task->manager_id === (int) $task->assignee_id || ! DB::table('department_memberships')->where('user_id', $task->assignee_id)->where('department_id', $assignment->department_id)->where('responsibility', 'manager')->exists()) {
                    continue;
                }
                $ancestor = $task;
                $whole = true;
                while ($ancestor) {
                    if ($ancestor->title !== $title || abs((float) $ancestor->weight - 1) > .00005) {
                        $whole = false;
                        break;
                    }
                    $ancestor = $ancestor->parent_task_id ? $tasks->get($ancestor->parent_task_id) : null;
                }
                if ($whole) {
                    DB::table('delivery_tasks')->where('id', $task->id)->update(['work_kind' => 'activity']);
                }
            }
        }
        DB::table('delivery_tasks')->whereIn('id', DB::table('kpi_handoff_activities')->select('task_id'))->update(['work_kind' => 'activity']);
    }

    public function down(): void
    {
        Schema::table('delivery_tasks', fn (Blueprint $table) => $table->dropColumn('work_kind'));
    }
};
