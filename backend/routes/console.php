<?php

use App\Services\B2BVanFulfillmentCutoverService;
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

Artisan::command(
    'foodex:cutover-b2b-van {--apply : Apply the cutover} {--rollback= : Roll back one cutover run UUID} {--json}',
    function (
        B2BVanFulfillmentMigrationAudit $audit,
        B2BVanFulfillmentCutoverService $cutover,
    ): int {
        $apply = (bool) $this->option('apply');
        $rollback = trim((string) ($this->option('rollback') ?? ''));

        if ($apply && $rollback !== '') {
            $this->error('Choose either --apply or --rollback, not both.');

            return 2;
        }

        if ($rollback !== '') {
            $result = $cutover->rollback($rollback);
        } elseif ($apply) {
            $result = $cutover->apply();
        } else {
            $result = $audit->report();
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        if (! $apply && $rollback === '') {
            $this->info('FOODEX B2B -> Van cutover preview (read-only)');
            $this->table(
                ['Metric', 'Count'],
                collect($result['counts'])->map(
                    fn ($value, $key): array => [(string) $key, (int) $value]
                )->values()->all(),
            );
            $this->warn('Preview only. Re-run with --apply to perform the cutover.');

            return 0;
        }

        $this->info('FOODEX B2B -> Van cutover run '.$result['run_id']);
        $this->line('Status: '.$result['status']);
        $this->line('Snapshots: '.$result['snapshot_count']);

        if (isset($result['summary']['postflight'])) {
            $this->table(
                ['Postflight metric', 'Count'],
                collect($result['summary']['postflight'])->map(
                    fn ($value, $key): array => [(string) $key, (int) $value]
                )->values()->all(),
            );
        }

        if (isset($result['summary']['rollback'])) {
            $this->table(
                ['Rollback metric', 'Count'],
                collect($result['summary']['rollback'])->map(
                    fn ($value, $key): array => [(string) $key, (int) $value]
                )->values()->all(),
            );
        }

        return $result['status'] === 'partial_rollback' ? 3 : 0;
    }
)->purpose('Safely cut over open B2B fulfillment from Driver to Van with snapshots and rollback.');
