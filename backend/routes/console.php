<?php

use App\Http\Controllers\NotificationController;
use App\Http\Middleware\ProductionSecurity;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

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
