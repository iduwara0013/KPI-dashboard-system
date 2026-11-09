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
            $table->foreignId('parent_task_id')->nullable()->constrained('delivery_tasks')->noActionOnDelete();
            $table->decimal('confirmed_progress', 5, 2)->default(0);
        });
        DB::table('delivery_tasks')->where('status', 'approved')->update(['confirmed_progress' => 100]);
        foreach (DB::table('work_assignments')->whereColumn('owner_id', 'assignee_id')->get() as $assignment) {
            if (! DB::table('delivery_tasks')->where('assignment_id', $assignment->id)
                ->where('generation', $assignment->delivery_generation)->where('is_active', true)->exists()) {
                DB::table('delivery_tasks')->insert([
                    'assignment_id' => $assignment->id, 'generation' => $assignment->delivery_generation,
                    'manager_id' => $assignment->owner_id, 'assignee_id' => $assignment->owner_id,
                    'reviewer_id' => $assignment->owner_id, 'title' => $assignment->activity_code,
                    'weight' => 1, 'due_date' => $assignment->due_date, 'instructions' => $assignment->instructions,
                    'reported_progress' => $assignment->reported_progress,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('delivery_tasks', function (Blueprint $table): void {
            $table->dropForeign(['parent_task_id']);
            $table->dropColumn(['parent_task_id', 'confirmed_progress']);
        });
    }
};
