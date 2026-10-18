<?php

use App\Domain\Analytics\ReadModel\AnalyticsFactMaterializer;
use App\Domain\SmartOrdering\LoyaltyService;
use App\Domain\SmartOrdering\SmartOrderingAnalyticsService;
use App\Domain\SmartOrdering\SmartOrderingRevisionService;
use App\Domain\SmartOrdering\WhatsApp\SmartOrderingScheduledReminderService;
use App\Domain\WhatsApp\CampaignService;
use App\Domain\WhatsApp\CertificationAuthority;
use App\Domain\WhatsApp\StateManager;
use App\Domain\WhatsApp\TransportRetryService;
use App\Enterprise\Scheduling\EnterpriseSchedulerAdapter;
use App\Models\User;
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

Artisan::command(
    'foodex:analytics-refresh {--from= : Backfill start date, YYYY-MM-DD} {--to= : Backfill end date, YYYY-MM-DD} {--store=* : Restrict to one or more store IDs}',
    function (AnalyticsFactMaterializer $materializer): int {
        $storeIds = collect((array) $this->option('store'))
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
        $from = trim((string) ($this->option('from') ?? ''));
        $to = trim((string) ($this->option('to') ?? ''));

        if (($from === '') !== ($to === '')) {
            $this->error('Provide both --from and --to for an analytics backfill.');

            return 2;
        }

        $result = $from !== ''
            ? $materializer->backfill($from, $to, $storeIds)
            : $materializer->refreshIncremental($storeIds);

        $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return 0;
    },
)->purpose('Materialize bounded canonical AI analytics facts without granting the model SQL access.');

Schedule::command('foodex:analytics-refresh')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10);

Artisan::command('foodex:dispatch-scheduled-notifications', function (): void {
    $count = app(NotificationCampaignDispatcher::class)->dispatchDue();
    $this->info("Dispatched {$count} scheduled notification campaign(s).");
});

Schedule::command('foodex:dispatch-scheduled-notifications')
    ->everyMinute()
    ->withoutOverlapping();

Artisan::command(
    'foodex:publish-scheduled-smart-ordering {--limit=25 : Maximum due revisions published per scheduler tick}',
    function (SmartOrderingRevisionService $revisions): int {
        $count = $revisions->dispatchDueScheduled((int) $this->option('limit'));
        $this->info("Published {$count} scheduled Smart Ordering revision(s).");

        return 0;
    },
)->purpose('Publish one bounded slice of due Smart Ordering revisions through the existing Laravel scheduler.');

Schedule::command('foodex:publish-scheduled-smart-ordering --limit=25')
    ->everyMinute()
    ->withoutOverlapping(2);

Artisan::command(
    'foodex:dispatch-enterprise-outbox {--limit=50 : Maximum Enterprise outbox rows claimed per scheduler tick}',
    function (EnterpriseSchedulerAdapter $enterprise): int {
        $count = $enterprise->dispatchDue((int) $this->option('limit'));
        $this->info("Dispatched {$count} Enterprise outbox message(s).");

        return 0;
    },
)->purpose('Dispatch one bounded Enterprise outbox slice through the existing FOODEX scheduler contract.');

Schedule::command('foodex:dispatch-enterprise-outbox --limit=50')
    ->everyMinute()
    ->withoutOverlapping(2);

Artisan::command('foodex:dispatch-flash-offer-notifications', function (): void {
    $count = app(FlashOfferNotificationDispatcher::class)->dispatchDue();
    $this->info("Dispatched {$count} Flash offer notification channel(s).");
});

Schedule::command('foodex:dispatch-flash-offer-notifications')
    ->everyMinute()
    ->withoutOverlapping();

Artisan::command(
    'foodex:certify-whatsapp-ga {user : SUPER_ADMIN user ID} {revision : Current WhatsApp module revision}',
    function (StateManager $manager): int {
        $user = User::query()->find((int) $this->argument('user'));
        if ($user === null) {
            $this->error('Certification actor not found.');

            return 2;
        }

        try {
            $manager->certifyGaReady($user, (int) $this->argument('revision'));
        } catch (Throwable $exception) {
            $this->error('WhatsApp GA certification rejected: '.$exception->getMessage());

            return 1;
        }

        $reference = app(CertificationAuthority::class)->validEvidenceReference();
        $this->info('WhatsApp CRM promoted to GA_READY with certificate '.$reference.'.');
        $this->warn('Emergency outbound stop is engaged. Production remains disabled until explicit Super Admin activation.');

        return 0;
    },
)->purpose('Apply the source-controlled #1407 WhatsApp CRM GA certificate without enabling production.');

Artisan::command('foodex:dispatch-smart-ordering-whatsapp-reminders', function (SmartOrderingScheduledReminderService $reminders): void {
    $count = $reminders->dispatchDue();
    $this->info("Queued {$count} Smart Ordering WhatsApp reminder(s).");
});

Schedule::command('foodex:dispatch-smart-ordering-whatsapp-reminders')
    ->everyMinute()
    ->withoutOverlapping();

Artisan::command('foodex:retry-whatsapp-transport', function (TransportRetryService $retry): void {
    $count = $retry->dispatchDue();
    $this->info("Dispatched {$count} due WhatsApp transport retry job(s).");
});

Schedule::command('foodex:retry-whatsapp-transport')
    ->everyMinute()
    ->withoutOverlapping();

Artisan::command('foodex:dispatch-whatsapp-campaigns', function (CampaignService $campaigns): void {
    $count = $campaigns->dispatchDue();
    $this->info("Queued {$count} WhatsApp campaign recipient(s).");
});

Schedule::command('foodex:dispatch-whatsapp-campaigns')
    ->everyMinute()
    ->withoutOverlapping();

Artisan::command('foodex:prune-smart-ordering-analytics', function (SmartOrderingAnalyticsService $analytics): void {
    $count = $analytics->pruneExpired();
    $this->info("Pruned {$count} expired Smart Ordering analytics event(s).");
});

Schedule::command('foodex:prune-smart-ordering-analytics')
    ->dailyAt('03:20')
    ->withoutOverlapping();

Artisan::command('foodex:expire-smart-ordering-loyalty', function (LoyaltyService $loyalty): void {
    $count = $loyalty->expireAllDue();
    $this->info("Expired {$count} Smart Ordering loyalty point(s).");
});

Schedule::command('foodex:expire-smart-ordering-loyalty')
    ->hourly()
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
