<?php

use App\Http\Controllers\EmployeeDirectoryImportController;
use App\Http\Controllers\NotificationController;
use App\Http\Middleware\ProductionSecurity;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('employees:initial-passwords {source} {--apply}', function () {
    try {
        $result = app(EmployeeDirectoryImportController::class)->initializePasswords($this->argument('source'), (bool) $this->option('apply'), fn ($message) => $this->info($message));
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return 0;
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return 1;
    }
})->purpose('Initialize CSV employees with EPF passwords and require first-login password change');

Artisan::command('employees:verify {source} {--md-epf=}', function () {
    $result = app(EmployeeDirectoryImportController::class)->verify($this->argument('source'), $this->option('md-epf') ?: null);
    $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return $result['mismatches'] === [] ? 0 : 1;
})->purpose('Reconcile imported employee fields and department memberships against the source CSV');

Artisan::command('employees:replace {source} {--apply : Replace local application data after saving a verified encrypted backup} {--md-epf= : Explicitly approved MD employee number}', function () {
    try {
        $result = app(EmployeeDirectoryImportController::class)->replace($this->argument('source'), (bool) $this->option('apply'), $this->option('md-epf') ?: null, fn ($message) => $this->info($message));
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return 0;
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return 1;
    }
})->purpose('Preview or replace the local employee directory, retaining system administrators');

Artisan::command('security:check', function () {
    $issues = ProductionSecurity::configurationIssues();
    if (! app()->environment('production')) {
        array_unshift($issues, 'APP_ENV must be production for deployment.');
    }
    foreach ($issues as $issue) {
        $this->error($issue);
    }
    if ($issues !== []) {
        return 1;
    }
    $this->info('Production security configuration checks passed.');

    return 0;
})->purpose('Validate production transport, session and database security configuration');

Artisan::command('notifications:overdue', function () {
    foreach (User::where('is_active', true)->whereIn('system_role', ['member', 'md'])->cursor() as $user) {
        NotificationController::remind($user);
    }
    $this->info('Deadline reminders updated.');
})->purpose('Generate deduplicated in-app deadline and overdue reminders');
Schedule::command('notifications:overdue')->hourly()->timezone('Asia/Colombo')->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
