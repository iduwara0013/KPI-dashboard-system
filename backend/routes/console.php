<?php

use App\Http\Controllers\NotificationController;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('notifications:overdue', function () {
    foreach (User::where('is_active', true)->whereIn('system_role', ['member', 'md'])->cursor() as $user) {
        NotificationController::remind($user);
    }
    $this->info('Overdue reminders updated.');
})->purpose('Generate deduplicated in-app overdue reminders');
Schedule::command('notifications:overdue')->dailyAt('08:00')->timezone('Asia/Colombo')->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
