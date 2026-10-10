<?php

use App\Services\B2BVanFulfillmentMigrationAudit;
use App\Services\FlashOfferNotificationDispatcher;
use App\Services\FlashOfferService;
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

Artisan::command('foodex:dispatch-flash-offer-notifications', function (): void {
    $count = app(FlashOfferNotificationDispatcher::class)->dispatchDue();
    $this->info("Dispatched {$count} Flash offer notification channel(s).");
});

Schedule::command('foodex:dispatch-flash-offer-notifications')
    ->everyMinute()
    ->withoutOverlapping();

Artisan::command('foodex:expire-flash-reservations', function (): void {
    $count = app(FlashOfferService::class)->expireDue();
    $this->info("Expired {$count} Flash reservation(s).");
});

Schedule::command('foodex:expire-flash-reservations')
    ->everyMinute()
    ->withoutOverlapping();

Artisan::command('foodex:audit-b2b-van-cutover {--json}', function (B2BVanFulfillmentMigrationAudit $audit): int {
    $report = $audit->report();

    if ((bool) $this->option('json')) {
        $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return 0;
    }

    $this->info('FOODEX B2B -> Van cutover dry-run (read-only)');
    $this->table(
        ['Metric', 'Count'],
        collect($report['counts'])->map(fn ($value, $key): array => [(string) $key, (int) $value])->values()->all(),
    );

    if ($report['rows'] !== []) {
        $this->table(
            ['Order', 'Channel', 'Expected', 'Issues'],
            collect($report['rows'])->map(fn (array $row): array => [
                $row['order_number'].' (#'.$row['order_id'].')',
                $row['channel'],
                $row['expected_actor'],
                implode(', ', $row['issues']),
            ])->all(),
        );
    }

    $this->warn('Dry-run only: no assignments or order state were modified.');

    return 0;
})->purpose('Inventory open B2B/B2C fulfillment actor contradictions without changing data.');
