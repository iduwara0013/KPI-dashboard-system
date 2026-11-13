<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extra_work_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->noActionOnDelete();
            $table->foreignId('assignee_id')->constrained('users')->noActionOnDelete();
            $table->foreignId('department_id')->constrained('departments')->noActionOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('title', 500);
            $table->string('instructions', 2000)->nullable();
            $table->string('priority', 20)->default('normal');
            $table->decimal('weight', 9, 4);
            $table->date('due_date');
            $table->decimal('reported_progress', 5, 2)->default(0);
            $table->decimal('confirmed_progress', 5, 2)->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['assignee_id', 'year']);
        });
        Schema::create('extra_work_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('assignment_id')->constrained('extra_work_assignments')->noActionOnDelete();
            $table->foreignId('parent_task_id')->nullable()->constrained('extra_work_tasks')->noActionOnDelete();
            $table->foreignId('manager_id')->constrained('users')->noActionOnDelete();
            $table->foreignId('assignee_id')->constrained('users')->noActionOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->noActionOnDelete();
            $table->string('title', 500);
            $table->string('instructions', 2000)->nullable();
            $table->decimal('weight', 7, 4);
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('assigned');
            $table->decimal('reported_progress', 5, 2)->default(0);
            $table->decimal('confirmed_progress', 5, 2)->default(0);
            $table->string('progress_note', 2000)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('extra_work_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained('extra_work_tasks')->noActionOnDelete();
            $table->foreignId('submitted_by')->constrained('users')->noActionOnDelete();
            $table->text('evidence');
            $table->string('evidence_url', 2000)->nullable();
            $table->string('decision', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->noActionOnDelete();
            $table->string('review_note', 2000)->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('timeliness_submitted_at')->nullable();
            $table->date('timeliness_due_date')->nullable();
            $table->boolean('retain_timeliness')->default(false);
        });
        Schema::create('extra_work_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('assignment_id')->constrained('extra_work_assignments')->noActionOnDelete();
            $table->foreignId('actor_id')->constrained('users')->noActionOnDelete();
            $table->unsignedInteger('version');
            $table->string('event', 40);
            $table->text('reason')->nullable();
            $table->text('snapshot');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extra_work_versions');
        Schema::dropIfExists('extra_work_submissions');
        Schema::dropIfExists('extra_work_tasks');
        Schema::dropIfExists('extra_work_assignments');
    }
};
