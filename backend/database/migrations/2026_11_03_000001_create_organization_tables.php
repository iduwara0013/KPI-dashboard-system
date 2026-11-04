<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('employee_number', 40)->nullable()->unique();
            $table->string('designation', 120)->nullable();
            $table->string('system_role', 30)->default('member');
            $table->boolean('is_active')->default(true);
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('code', 20)->unique();
            $table->string('description', 500)->nullable();
            $table->string('color', 7)->default('#23796b');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('department_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->noActionOnDelete();
            $table->foreignId('department_id')->constrained()->noActionOnDelete();
            $table->string('responsibility', 20);
            $table->timestamps();
            $table->unique(['user_id', 'department_id']);
        });

        Schema::create('reporting_relationships', function (Blueprint $table) {
            $table->id();
            // NO ACTION prevents deletion and supports SQL Server's cascade-path rules.
            $table->foreignId('department_id')->constrained()->noActionOnDelete();
            $table->foreignId('user_id')->constrained()->noActionOnDelete();
            $table->foreignId('manager_id')->constrained('users')->noActionOnDelete();
            $table->timestamps();
            $table->unique(['department_id', 'user_id', 'manager_id'], 'reporting_scope_unique');
        });

        Schema::create('admin_activity', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->noActionOnDelete();
            $table->string('action', 40);
            $table->string('subject', 180);
            $table->text('before_data')->nullable();
            $table->text('after_data')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_activity');
        Schema::dropIfExists('reporting_relationships');
        Schema::dropIfExists('department_memberships');
        Schema::dropIfExists('departments');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn([
            'employee_number', 'designation', 'system_role', 'is_active',
        ]));
    }
};
