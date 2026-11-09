<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('users')->where('system_role', 'md')->get(['id', 'name']) as $director) {
            $memberships = DB::table('department_memberships')->where('user_id', $director->id)->get();
            $reporting = DB::table('reporting_relationships')
                ->where(fn ($query) => $query->where('user_id', $director->id)->orWhere('manager_id', $director->id))->get();
            if ($memberships->isEmpty() && $reporting->isEmpty()) {
                continue;
            }
            DB::table('admin_activity')->insert([
                'actor_id' => null, 'action' => 'md.department_links_removed', 'subject' => $director->name,
                'before_data' => json_encode(['user_id' => $director->id, 'memberships' => $memberships, 'reporting' => $reporting], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
            DB::table('reporting_relationships')
                ->where(fn ($query) => $query->where('user_id', $director->id)->orWhere('manager_id', $director->id))->delete();
            DB::table('department_memberships')->where('user_id', $director->id)->delete();
        }
    }

    public function down(): void
    {
        // Removed department links remain recorded in the audit history.
    }
};
