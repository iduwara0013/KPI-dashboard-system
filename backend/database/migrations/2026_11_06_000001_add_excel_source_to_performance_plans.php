<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performance_plans', function (Blueprint $table): void {
            $table->text('excel_source')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('performance_plans', function (Blueprint $table): void {
            $table->dropColumn('excel_source');
        });
    }
};
