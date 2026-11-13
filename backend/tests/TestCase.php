<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function grantPlanningDepartments(User $editor, ?array $departments = null): void
    {
        foreach ($departments ?? DB::table('departments')->pluck('id')->all() as $department) {
            DB::table('department_memberships')->updateOrInsert(
                ['user_id' => $editor->id, 'department_id' => $department],
                ['responsibility' => 'employee', 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }
}
