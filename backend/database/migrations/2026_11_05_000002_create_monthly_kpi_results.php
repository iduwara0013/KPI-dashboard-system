<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_kpi_reviewers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained('performance_plans')->noActionOnDelete();
            $table->string('kpi_code', 64);
            $table->foreignId('reviewer_id')->constrained('users')->noActionOnDelete();
            $table->timestamps();
            $table->unique(['plan_id', 'kpi_code'], 'monthly_kpi_reviewer_unique');
        });

        Schema::create('monthly_kpi_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained('performance_plans')->noActionOnDelete();
            $table->string('kpi_code', 64);
            $table->unsignedTinyInteger('month');
            $table->unsignedInteger('attempt');
            $table->unsignedInteger('source_revision');
            $table->decimal('target_value', 18, 4);
            $table->string('target_unit', 30);
            $table->string('direction', 20);
            $table->decimal('actual_value', 18, 4);
            $table->string('evidence', 2000);
            $table->string('evidence_url', 1000)->nullable();
            $table->foreignId('submitted_by')->constrained('users')->noActionOnDelete();
            $table->string('status', 20);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->noActionOnDelete();
            $table->string('review_note', 2000)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('created_at');
            $table->unique(['plan_id', 'kpi_code', 'month', 'attempt'], 'monthly_kpi_submission_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_kpi_submissions');
        Schema::dropIfExists('monthly_kpi_reviewers');
    }
};
