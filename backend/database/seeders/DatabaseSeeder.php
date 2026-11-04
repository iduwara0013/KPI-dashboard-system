<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Demo seeding is only allowed in local or testing environments.');
        }

        foreach ([
            ['IT', 'Information Technology', 'Infrastructure, service delivery and office technology.', '#23796b'],
            ['DIG', 'Digital Services', 'Digital platforms, automation and application delivery.', '#7863b7'],
            ['FIN', 'Finance', 'Financial planning, reporting and budget stewardship.', '#b78639'],
            ['OPS', 'Operations', 'Operational delivery and coordination across the office.', '#467ea5'],
        ] as [$code, $name, $description, $color]) {
            DB::table('departments')->updateOrInsert(['code' => $code], compact('name', 'description', 'color') + [
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $ids = DB::table('departments')->pluck('id', 'code');
        $people = [
            ['SYS001', 'System Administrator', 'System Administrator', 'super_admin', []],
            ['EMP001', 'Nimal Perera', 'Chief Information Officer', 'member', ['IT' => 'hof', 'DIG' => 'hof']],
            ['EMP002', 'Dilini Fernando', 'Deputy General Manager', 'member', ['FIN' => 'hof']],
            ['EMP003', 'Ruwan Silva', 'Head of Operations', 'member', ['OPS' => 'hof']],
            ['EMP004', 'Kamal Jayawardena', 'IT Manager', 'member', ['IT' => 'manager']],
            ['EMP005', 'Ayesha Rahman', 'Digital Services Manager', 'member', ['DIG' => 'manager']],
            ['EMP006', 'Tharindu Senanayake', 'Finance Manager', 'member', ['FIN' => 'manager']],
            ['EMP007', 'Shanika Dias', 'Systems Executive', 'member', ['IT' => 'employee', 'DIG' => 'employee']],
            ['EMP008', 'Kasun Bandara', 'Technical Officer', 'member', ['IT' => 'employee']],
            ['EMP009', 'Amali De Silva', 'Accounts Executive', 'member', ['FIN' => 'employee']],
            ['EMP010', 'Dinuka Wijesinghe', 'Operations Executive', 'member', ['OPS' => 'employee']],
            ['EMP011', 'Piumi Gunasekara', 'Planning Officer', 'plan_editor', ['OPS' => 'employee']],
        ];
        $users = [];
        foreach ($people as [$number, $name, $designation, $role, $memberships]) {
            $user = User::firstOrCreate(['employee_number' => $number], [
                'name' => $name, 'designation' => $designation, 'system_role' => $role,
                'email' => $number === 'SYS001' ? 'admin@pulse.test' : strtolower($number).'@example.test',
                'password' => env('DEMO_ADMIN_PASSWORD', 'Pulse-Local-2026!'), 'is_active' => true,
            ]);
            $users[$number] = $user->id;
            foreach ($memberships as $code => $responsibility) {
                DB::table('department_memberships')->updateOrInsert([
                    'user_id' => $user->id, 'department_id' => $ids[$code],
                ], ['responsibility' => $responsibility, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        foreach ([['IT', 'EMP004', 'EMP001'], ['DIG', 'EMP005', 'EMP001'], ['FIN', 'EMP006', 'EMP002'],
            ['IT', 'EMP007', 'EMP004'], ['DIG', 'EMP007', 'EMP005'], ['IT', 'EMP008', 'EMP004'],
            ['FIN', 'EMP009', 'EMP006'], ['OPS', 'EMP010', 'EMP003'], ['OPS', 'EMP011', 'EMP003']] as [$code, $employee, $manager]) {
            DB::table('reporting_relationships')->updateOrInsert([
                'department_id' => $ids[$code], 'user_id' => $users[$employee], 'manager_id' => $users[$manager],
            ], ['created_at' => now(), 'updated_at' => now()]);
        }
        if (! DB::table('admin_activity')->exists()) {
            DB::table('admin_activity')->insert([
                'actor_id' => $users['SYS001'], 'action' => 'workspace.initialized',
                'subject' => 'Local demo organization', 'created_at' => now(),
            ]);
        }
    }
}
