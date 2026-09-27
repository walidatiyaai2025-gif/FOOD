<?php

use App\Services\NotificationCampaignDispatcher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('foodex:version', function (): void {
    $this->info(trim((string) @file_get_contents(base_path('../VERSION'))));
});

Artisan::command('foodex:dispatch-scheduled-notifications', function (): void {
    $count = app(NotificationCampaignDispatcher::class)->dispatchDue();
    $this->info("Dispatched {$count} scheduled notification campaign(s).");
});

Schedule::command('foodex:dispatch-scheduled-notifications')
    ->everyMinute()
    ->withoutOverlapping();
