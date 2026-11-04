<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_assignments', function (Blueprint $table): void {
            $table->unsignedInteger('delivery_generation')->default(1);
            $table->decimal('confirmed_progress', 5, 2)->default(0);
        });
        Schema::create('delivery_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('assignment_id')->constrained('work_assignments')->noActionOnDelete();
            $table->unsignedInteger('generation');
            $table->foreignId('manager_id')->constrained('users')->noActionOnDelete();
            $table->foreignId('assignee_id')->constrained('users')->noActionOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->noActionOnDelete();
            $table->string('title', 500);
            $table->string('instructions', 2000)->nullable();
            $table->decimal('weight', 7, 4);
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('assigned');
            $table->decimal('reported_progress', 5, 2)->default(0);
            $table->string('progress_note', 2000)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['assignment_id', 'generation']);
        });
        Schema::create('task_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained('delivery_tasks')->noActionOnDelete();
            $table->foreignId('submitted_by')->constrained('users')->noActionOnDelete();
            $table->text('evidence');
            $table->string('evidence_url', 2000)->nullable();
            $table->string('decision', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->noActionOnDelete();
            $table->string('review_note', 2000)->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('reviewed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_submissions');
        Schema::dropIfExists('delivery_tasks');
        Schema::table('work_assignments', fn (Blueprint $table) => $table->dropColumn(['delivery_generation', 'confirmed_progress']));
    }
};
