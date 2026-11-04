<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained('performance_plans')->noActionOnDelete();
            $table->string('activity_code', 64);
            $table->foreignId('owner_id')->constrained('users')->noActionOnDelete();
            $table->foreignId('assignee_id')->constrained('users')->noActionOnDelete();
            $table->foreignId('department_id')->constrained()->noActionOnDelete();
            $table->unsignedInteger('source_revision');
            $table->string('source_signature', 64);
            $table->unsignedInteger('version')->default(1);
            $table->date('due_date')->nullable();
            $table->string('instructions', 1000)->nullable();
            $table->decimal('reported_progress', 5, 2)->default(0);
            $table->string('progress_note', 1000)->nullable();
            $table->timestamps();
            $table->unique(['plan_id', 'activity_code'], 'activity_assignment_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_assignments');
    }
};
