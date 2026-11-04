<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('title', 180);
            $table->foreignId('owner_id')->constrained('users')->noActionOnDelete();
            $table->foreignId('created_by')->constrained('users')->noActionOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedInteger('published_revision')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['owner_id', 'year'], 'personal_plan_owner_year_unique');
        });

        Schema::create('performance_plan_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained('performance_plans')->noActionOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('performance_plan_items')->noActionOnDelete();
            $table->foreignId('department_id')->constrained()->noActionOnDelete();
            $table->string('code', 64);
            $table->string('kind', 15);
            $table->string('title', 1000);
            $table->string('category', 100)->nullable();
            $table->decimal('weight', 7, 4)->nullable();
            $table->decimal('target_value', 18, 4)->nullable();
            $table->string('target_unit', 30)->nullable();
            $table->string('frequency', 30)->nullable();
            $table->string('direction', 20)->nullable();
            $table->date('due_date')->nullable();
            $table->string('notes', 1000)->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('changed_at')->nullable();
            $table->timestamps();
            $table->unique(['plan_id', 'code'], 'plan_item_code_unique');
        });

        Schema::create('performance_plan_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained('performance_plans')->noActionOnDelete();
            $table->foreignId('actor_id')->constrained('users')->noActionOnDelete();
            $table->unsignedInteger('version');
            $table->string('event', 20);
            $table->longText('snapshot');
            $table->longText('changes');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['plan_id', 'version'], 'plan_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_plan_versions');
        Schema::dropIfExists('performance_plan_items');
        Schema::dropIfExists('performance_plans');
    }
};
