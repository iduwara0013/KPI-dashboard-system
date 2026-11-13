<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class EmployeeDirectoryImportController extends Controller
{
    /** Child records precede their referenced parents; infrastructure tables are excluded. */
    private const TABLES = [
        'work_notifications', 'monthly_kpi_submissions', 'monthly_kpi_reviewers', 'work_replacements', 'work_change_requests',
        'extra_work_versions', 'extra_work_submissions', 'extra_work_tasks', 'extra_work_assignments',
        'task_submissions', 'delivery_tasks', 'work_assignments', 'kpi_delegations',
        'personal_weight_proposals', 'personal_allocation_versions', 'personal_allocations',
        'performance_plan_versions', 'performance_plan_items', 'performance_plans',
        'reporting_relationships', 'department_memberships', 'admin_activity', 'departments',
        'password_reset_tokens', 'sessions', 'users',
    ];

    private function read(string $source): array
    {
        $handle = fopen($source, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The employee CSV could not be opened.');
        }
        try {
            $header = fgetcsv($handle, 0, ',', '"', '');
            if (! $header) {
                throw new RuntimeException('The employee CSV is empty.');
            }
            $header[0] = ltrim($header[0], "\xEF\xBB\xBF");
            $required = ['epf', 'firstname', 'lastname', 'contact', 'function', 'personnel_area', 'designation'];
            if (array_slice($header, 0, 7) !== $required) {
                throw new RuntimeException('Unexpected CSV columns. Expected: '.implode(', ', $required));
            }
            $rows = $seen = [];
            $line = 1;
            while (($values = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $line++;
                if (array_filter($values, fn ($value) => trim((string) $value) !== '') === []) {
                    continue;
                }
                if (count($values) < 7 || array_filter(array_slice($values, 7), fn ($value) => trim((string) $value) !== '') !== []) {
                    throw new RuntimeException("Unexpected data on CSV line {$line}.");
                }
                $row = array_combine($required, array_map(fn ($value) => trim((string) $value), array_slice($values, 0, 7)));
                $name = trim($row['firstname'].' '.$row['lastname']);
                if (! preg_match('/^[0-9]{1,40}$/D', $row['epf']) || isset($seen[$row['epf']]) || $row['firstname'] === '' || $row['lastname'] === '' || $row['designation'] === '' || $row['personnel_area'] === ''
                    || mb_strlen($name) > 120 || mb_strlen($row['designation']) > 120 || mb_strlen($row['function']) > 120 || mb_strlen($row['personnel_area']) > 120 || mb_strlen($row['contact']) > 40
                    || ! mb_check_encoding(implode('', $row), 'UTF-8')) {
                    throw new RuntimeException("Missing, duplicate or invalid employee data on CSV line {$line}.");
                }
                $seen[$row['epf']] = true;
                $rows[] = [...$row, 'name' => $name];
            }
            if ($rows === []) {
                throw new RuntimeException('No employee records were found.');
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    private function write(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents, LOCK_EX) !== strlen($contents)) {
            throw new RuntimeException('The private import output could not be saved. No replacement should proceed.');
        }
        @chmod($path, 0600);
    }

    /** Reset only the explicitly supplied directory employees; administrators are never reset. */
    public function initializePasswords(string $source, bool $apply = false, ?callable $progress = null): array
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Directory password initialization is limited to local and isolated testing databases.');
        }
        $rows = $this->read($source);
        $users = DB::table('users')->whereIn('employee_number', array_column($rows, 'epf'))->orderBy('id')->get();
        if ($users->count() !== count($rows) || $users->contains('system_role', 'super_admin')) {
            throw new RuntimeException('Every source EPF must match an existing non-administrator employee.');
        }
        $report = ['employees' => $users->count(), 'active_employees' => $users->where('is_active', true)->count(), 'applied' => false];
        if (! $apply) {
            return $report;
        }
        $hashes = [];
        foreach ($users as $index => $user) {
            $hashes[$user->id] = Hash::make((string) $user->employee_number);
            if ($progress && (($index + 1) % 100 === 0 || $index === $users->count() - 1)) {
                $progress('Prepared '.($index + 1).' of '.$users->count().' initial passwords.');
            }
        }
        $directory = storage_path('app/private/employee-imports/password-initialization-'.now()->format('Ymd-His').'-'.Str::random(8));
        File::ensureDirectoryExists($directory, 0700, true);
        $backupPath = $directory.'/previous-passwords.encrypted';
        DB::transaction(function () use ($users, $hashes, $backupPath): void {
            $locked = DB::table('users')->whereIn('id', $users->pluck('id'))->orderBy('id')->lockForUpdate()->get();
            foreach ($locked as $user) {
                $previous = $users->firstWhere('id', $user->id);
                if (! $previous || $user->system_role === 'super_admin' || $user->employee_number !== $previous->employee_number || $user->password !== $previous->password || (bool) $user->must_change_password !== (bool) $previous->must_change_password) {
                    throw new RuntimeException('An employee account changed during preparation. No passwords were reset.');
                }
            }
            if ($locked->count() !== $users->count()) {
                throw new RuntimeException('An employee account was removed during preparation.');
            }
            $backup = json_encode($locked->map(fn ($user) => collect((array) $user)->only(['id', 'employee_number', 'password', 'remember_token', 'must_change_password', 'password_changed_at'])->all())->all(), JSON_THROW_ON_ERROR);
            $this->write($backupPath, Crypt::encryptString($backup));
            if (! hash_equals(hash('sha256', $backup), hash('sha256', Crypt::decryptString(file_get_contents($backupPath))))) {
                throw new RuntimeException('The password backup could not be verified.');
            }
            foreach ($locked as $user) {
                DB::table('users')->where('id', $user->id)->update(['password' => $hashes[$user->id], 'remember_token' => Str::random(60), 'must_change_password' => true, 'password_changed_at' => null, 'updated_at' => now()]);
            }
            DB::table('sessions')->whereIn('user_id', $users->pluck('id'))->delete();
            DB::table('password_reset_tokens')->whereIn('email', $users->pluck('email'))->delete();
            $saved = DB::table('users')->whereIn('id', $users->pluck('id'))->get();
            if ($saved->count() !== $users->count() || $saved->contains(fn ($user) => ! $user->must_change_password || ! hash_equals($hashes[$user->id], $user->password))) {
                throw new RuntimeException('Password initialization did not reconcile and was rolled back.');
            }
            DB::table('admin_activity')->insert(['actor_id' => null, 'action' => 'passwords.initialized', 'subject' => $users->count().' employee passwords initialized for mandatory first-login change', 'created_at' => now()]);
        });

        return [...$report, 'applied' => true, 'verified_accounts' => $users->count(), 'backup' => $backupPath];
    }

    /** Reconcile saved employee details and department memberships against the original source. */
    public function verify(string $source, ?string $mdEpf = null): array
    {
        $rows = $this->read($source);
        $users = DB::table('users')->whereIn('employee_number', array_column($rows, 'epf'))->get()->keyBy('employee_number');
        $memberships = DB::table('department_memberships as m')->join('departments as d', 'd.id', '=', 'm.department_id')->whereIn('m.user_id', $users->pluck('id'))
            ->get(['m.user_id', 'm.responsibility', 'm.responsibility_title', 'd.name'])->groupBy('user_id');
        $differences = [];
        foreach ($rows as $row) {
            $user = $users->get($row['epf']);
            $expected = ['name' => $row['name'], 'designation' => $row['designation'], 'contact' => $row['contact'] ?: null, 'personnel_area' => $row['personnel_area'],
                'source_function' => $row['function'] ?: null, 'grade' => null, 'system_role' => $row['epf'] === $mdEpf ? 'md' : 'member'];
            $fields = $user ? array_keys(array_filter($expected, fn ($value, $key) => $value !== $user->$key, ARRAY_FILTER_USE_BOTH)) : ['missing_employee'];
            if ($user) {
                if ((bool) $user->is_active !== ($row['function'] !== '' || $row['epf'] === $mdEpf)) {
                    $fields[] = 'is_active';
                }
                $assigned = $memberships->get($user->id, collect());
                $hasDepartment = $row['function'] !== '' && $row['epf'] !== $mdEpf;
                if ($assigned->count() !== ($hasDepartment ? 1 : 0) || ($hasDepartment && ($assigned->first()?->name !== $row['function'] || $assigned->first()?->responsibility !== 'employee' || $assigned->first()?->responsibility_title !== $row['designation']))) {
                    $fields[] = 'department_membership';
                }
            }
            if ($fields !== []) {
                $differences[] = ['epf' => $row['epf'], 'fields' => $fields];
            }
        }

        return ['source_sha256' => hash_file('sha256', $source), 'source_employees' => count($rows), 'verified_employees' => count($rows) - count($differences),
            'mismatches' => $differences, 'department_count' => DB::table('departments')->count(), 'user_count' => DB::table('users')->count(),
            'active_imported_accounts' => $users->where('is_active', true)->count(), 'membership_count' => $memberships->flatten(1)->count(),
            'reporting_links' => DB::table('reporting_relationships')->count(), 'planned_work' => DB::table('performance_plans')->count(), 'extra_work' => DB::table('extra_work_assignments')->count()];
    }

    /** A dry run always precedes destructive execution; apply requires an explicit CLI option. */
    public function replace(string $source, bool $apply = false, ?string $mdEpf = null, ?callable $progress = null): array
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('This replacement command is limited to local and isolated testing databases.');
        }
        $rows = $this->read($source);
        $adminIds = DB::table('users')->where('system_role', 'super_admin')->pluck('id');
        if ($adminIds->isEmpty() || ! DB::table('users')->whereIn('id', $adminIds)->where('is_active', true)->exists()) {
            throw new RuntimeException('An active system administrator must be retained.');
        }
        if (DB::table('users')->whereIn('id', $adminIds)->whereIn('employee_number', array_column($rows, 'epf'))->exists()) {
            throw new RuntimeException('A retained administrator EPF conflicts with the CSV.');
        }
        if ($mdEpf !== null && count(array_filter($rows, fn ($row) => $row['epf'] === $mdEpf && $row['designation'] === 'MANAGING DIRECTOR')) !== 1) {
            throw new RuntimeException('The selected MD EPF must identify exactly one Managing Director in the CSV.');
        }
        $functions = array_values(array_unique(array_filter(array_column($rows, 'function'), fn ($value) => $value !== '')));
        sort($functions);
        $report = ['source_sha256' => hash_file('sha256', $source), 'employees' => count($rows), 'departments' => count($functions), 'preserved_administrators' => $adminIds->count(),
            'unassigned_epfs' => array_column(array_filter($rows, fn ($row) => $row['function'] === ''), 'epf'), 'md_epf' => $mdEpf,
            'current_counts' => collect(self::TABLES)->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all(), 'applied' => false];
        if (! $apply) {
            return $report;
        }
        $directory = storage_path('app/private/employee-imports/'.now()->format('Ymd-His').'-'.Str::random(8));
        File::ensureDirectoryExists($directory, 0700, true);
        $credentials = fopen('php://temp', 'w+');
        fputcsv($credentials, ['EPF number', 'Full name', 'Department', 'Initial password', 'Account status'], ',', '"', '');
        $prepared = [];
        foreach ($rows as $index => $row) {
            $password = $row['epf'];
            $active = $row['function'] !== '' || $row['epf'] === $mdEpf;
            $prepared[] = ['name' => $row['name'], 'employee_number' => $row['epf'], 'designation' => $row['designation'], 'contact' => $row['contact'] ?: null,
                'personnel_area' => $row['personnel_area'], 'source_function' => $row['function'] ?: null, 'grade' => null,
                'email' => $row['epf'].'@directory.cpstl.invalid', 'password' => Hash::make($password), 'system_role' => $row['epf'] === $mdEpf ? 'md' : 'member', 'is_active' => $active, 'must_change_password' => true];
            $safe = fn ($value) => preg_match('/^[=+@\-\t\r]/', $value) ? "'".$value : $value;
            fputcsv($credentials, [$row['epf'], $safe($row['name']), $safe($row['function']), $password, $active ? 'Active' : 'Inactive - department required'], ',', '"', '');
            if ($progress && (($index + 1) % 100 === 0 || $index === count($rows) - 1)) {
                $progress('Prepared '.($index + 1).' of '.count($rows).' employee accounts.');
            }
        }
        rewind($credentials);
        $credentialContents = stream_get_contents($credentials);
        fclose($credentials);
        $backupPath = $directory.'/previous-data.encrypted';
        $credentialPath = $directory.'/initial-credentials.csv';
        try {
            DB::transaction(function () use ($source, $report, $adminIds, $functions, $prepared, $backupPath, $credentialPath, $credentialContents): void {
                DB::table('users')->orderBy('id')->lockForUpdate()->get(['id']);
                if (hash_file('sha256', $source) !== $report['source_sha256']) {
                    throw new RuntimeException('The source CSV changed during preparation.');
                }
                $backup = ['format' => 'cpstl-employee-replacement-v1', 'created_at' => now()->toIso8601String(), 'database' => DB::connection()->getDatabaseName(), 'tables' => []];
                foreach (self::TABLES as $table) {
                    $backup['tables'][$table] = DB::table($table)->get()->all();
                }
                $backupJson = json_encode($backup, JSON_THROW_ON_ERROR);
                $this->write($backupPath, Crypt::encryptString($backupJson));
                if (! hash_equals(hash('sha256', $backupJson), hash('sha256', Crypt::decryptString(file_get_contents($backupPath))))) {
                    throw new RuntimeException('The previous data backup could not be verified.');
                }
                $this->write($credentialPath, $credentialContents);
                foreach (self::TABLES as $table) {
                    if ($table === 'users') {
                        DB::table($table)->whereNotIn('id', $adminIds)->delete();
                    } elseif (in_array($table, ['delivery_tasks', 'extra_work_tasks', 'performance_plan_items'], true)) {
                        foreach (DB::table($table)->orderByDesc('id')->pluck('id') as $id) {
                            DB::table($table)->where('id', $id)->delete();
                        }
                    } else {
                        DB::table($table)->delete();
                    }
                }
                $departmentIds = [];
                foreach ($functions as $index => $name) {
                    $departmentIds[$name] = DB::table('departments')->insertGetId(['name' => $name, 'code' => 'FN-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                        'description' => 'Function from the employee directory dated 03.11.2026.', 'color' => '#23796b', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
                }
                foreach ($prepared as $attributes) {
                    $id = DB::table('users')->insertGetId([...$attributes, 'created_at' => now(), 'updated_at' => now()]);
                    if ($attributes['system_role'] !== 'md' && $attributes['source_function'] !== null) {
                        DB::table('department_memberships')->insert(['user_id' => $id, 'department_id' => $departmentIds[$attributes['source_function']], 'responsibility' => 'employee',
                            'responsibility_title' => $attributes['designation'], 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
                foreach ($adminIds as $id) {
                    DB::table('users')->where('id', $id)->update(['remember_token' => Str::random(60), 'updated_at' => now()]);
                }
                DB::table('admin_activity')->insert(['actor_id' => $adminIds->first(), 'action' => 'employees.replaced', 'subject' => count($prepared).' employees imported from the verified CSV',
                    'after_data' => json_encode(['source_sha256' => $report['source_sha256'], 'employees' => count($prepared), 'departments' => count($functions), 'md_epf' => $report['md_epf']]), 'created_at' => now()]);
                if (DB::table('users')->count() !== count($prepared) + $adminIds->count() || DB::table('departments')->count() !== count($functions)) {
                    throw new RuntimeException('The imported counts did not reconcile. The replacement was rolled back.');
                }
            });
        } catch (\Throwable $exception) {
            File::delete($credentialPath);
            throw $exception;
        }

        return [...$report, 'applied' => true, 'backup' => $backupPath, 'credentials' => $credentialPath,
            'memberships' => DB::table('department_memberships')->count(), 'users_after' => DB::table('users')->count()];
    }
}
