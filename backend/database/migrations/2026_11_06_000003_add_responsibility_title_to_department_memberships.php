<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('department_memberships', function (Blueprint $table): void {
            $table->string('responsibility_title', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('department_memberships', function (Blueprint $table): void {
            $table->dropColumn('responsibility_title');
        });
    }
};
