<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_submissions', function (Blueprint $table): void {
            $table->timestamp('timeliness_submitted_at')->nullable();
            $table->date('timeliness_due_date')->nullable();
            $table->boolean('retain_timeliness')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('task_submissions', function (Blueprint $table): void {
            $table->dropColumn(['timeliness_submitted_at', 'timeliness_due_date', 'retain_timeliness']);
        });
    }
};
