<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_handoffs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('root_delegation_id')->constrained('kpi_delegations')->noActionOnDelete();
            $table->unsignedInteger('root_generation');
            $table->foreignId('parent_id')->nullable()->constrained('kpi_handoffs')->noActionOnDelete();
            $table->foreignId('assigner_id')->constrained('users')->noActionOnDelete();
            $table->foreignId('assignee_id')->constrained('users')->noActionOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['root_delegation_id', 'root_generation', 'assigner_id'], 'kpi_handoff_owner_unique');
        });
        Schema::create('kpi_handoff_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('handoff_id')->constrained('kpi_handoffs')->noActionOnDelete();
            $table->foreignId('task_id')->unique()->constrained('delivery_tasks')->noActionOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_handoff_activities');
        Schema::dropIfExists('kpi_handoffs');
    }
};
