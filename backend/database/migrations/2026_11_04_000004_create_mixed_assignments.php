<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_delegations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained('performance_plans')->noActionOnDelete();
            $table->string('kpi_code', 64);
            $table->foreignId('owner_id')->constrained('users')->noActionOnDelete();
            $table->foreignId('assignee_id')->constrained('users')->noActionOnDelete();
            $table->foreignId('department_id')->constrained()->noActionOnDelete();
            $table->string('recipient_role', 20)->default('manager');
            $table->unsignedInteger('source_revision');
            $table->string('source_signature', 64);
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('generation')->default(1);
            $table->date('due_date')->nullable();
            $table->string('instructions', 1000)->nullable();
            $table->timestamps();
            $table->unique(['plan_id', 'kpi_code']);
        });
        Schema::table('work_assignments', function (Blueprint $table): void {
            $table->foreignId('delegation_id')->nullable()->constrained('kpi_delegations')->noActionOnDelete();
            $table->string('ownership_mode', 15)->default('activity');
            $table->string('recipient_role', 20)->default('manager');
        });
        DB::table('work_assignments')->whereColumn('owner_id', 'assignee_id')->update(['recipient_role' => 'own']);
        Schema::create('personal_weight_proposals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->noActionOnDelete();
            $table->foreignId('actor_id')->constrained('users')->noActionOnDelete();
            $table->string('source_key', 120);
            $table->decimal('weight', 7, 4);
            $table->timestamps();
            $table->unique(['owner_id', 'source_key']);
        });
        Schema::create('personal_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->noActionOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 20)->default('pending');
            $table->longText('draft_payload');
            $table->longText('published_payload')->nullable();
            $table->unsignedInteger('published_version')->nullable();
            $table->timestamps();
            $table->unique(['owner_id', 'year']);
        });
        Schema::create('personal_allocation_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('allocation_id')->constrained('personal_allocations')->noActionOnDelete();
            $table->foreignId('actor_id')->constrained('users')->noActionOnDelete();
            $table->unsignedInteger('version');
            $table->string('event', 20);
            $table->longText('snapshot');
            $table->string('review_note', 1000)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_allocation_versions');
        Schema::dropIfExists('personal_allocations');
        Schema::dropIfExists('personal_weight_proposals');
        Schema::table('work_assignments', function (Blueprint $table): void {
            $table->dropForeign(['delegation_id']);
            $table->dropColumn(['delegation_id', 'ownership_mode', 'recipient_role']);
        });
        Schema::dropIfExists('kpi_delegations');
    }
};
