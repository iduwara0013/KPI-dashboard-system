<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_change_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained('delivery_tasks')->noActionOnDelete();
            $table->foreignId('extra_assignment_id')->constrained('extra_work_assignments')->noActionOnDelete();
            $table->foreignId('requested_by')->constrained('users')->noActionOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->noActionOnDelete();
            $table->foreignId('assignee_id')->constrained('users')->noActionOnDelete();
            $table->foreignId('department_id')->constrained('departments')->noActionOnDelete();
            $table->unsignedSmallInteger('year');
            $table->decimal('requested_fraction', 7, 4);
            $table->text('reason');
            $table->string('decision', 30)->default('pending');
            $table->text('decision_note')->nullable();
            $table->text('snapshot');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['reviewer_id', 'decision']);
        });
        Schema::create('work_replacements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_id')->unique()->constrained('work_change_requests')->noActionOnDelete();
            $table->foreignId('task_id')->unique()->constrained('delivery_tasks')->noActionOnDelete();
            $table->foreignId('extra_assignment_id')->unique()->constrained('extra_work_assignments')->noActionOnDelete();
            $table->decimal('fraction', 7, 4);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_replacements');
        Schema::dropIfExists('work_change_requests');
    }
};
