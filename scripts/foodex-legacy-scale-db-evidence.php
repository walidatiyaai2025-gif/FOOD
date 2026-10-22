#!/usr/bin/env php
<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Performance\Support\ExplainEvidenceTool;
use Tests\Performance\Support\PercentileStatistics;
use Tests\Performance\Support\SlowQueryProbe;

require __DIR__.'/../backend/vendor/autoload.php';

$app = require __DIR__.'/../backend/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$output = $argv[1] ?? null;
if (! is_string($output) || trim($output) === '') {
    fwrite(STDERR, "Usage: php scripts/foodex-legacy-scale-db-evidence.php <output.json>\n");
    exit(2);
}

$activeScenario = null;
$events = [];

DB::listen(function (QueryExecuted $query) use (&$activeScenario, &$events): void {
    if (! is_string($activeScenario)) {
        return;
    }

    $events[$activeScenario][] = [
        'sql' => $query->sql,
        'bindings' => $query->bindings,
        'time_ms' => (float) $query->time,
        'connection' => $query->connectionName,
    ];
});

$scenarios = [
    'customer_common_list' => [
        'limit_ms' => 1000.0,
        'growing_tables' => ['customers'],
        'run' => static fn () => DB::table('customers')
            ->where('email', 'like', 'perf-c-legacy4x-%@example.test')
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'name', 'phone', 'email']),
    ],
    'orders_common_list' => [
        'limit_ms' => 1000.0,
        'growing_tables' => ['orders'],
        'run' => static fn () => DB::table('orders')
            ->where('order_number', 'like', 'PERF-LEGACY4X-%')
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'order_number', 'status', 'grand_total', 'created_at']),
    ],
    'inventory_common_list' => [
        'limit_ms' => 1000.0,
        'growing_tables' => ['inventories', 'products', 'warehouses'],
        'run' => static fn () => DB::table('inventories')
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->join('products', 'products.id', '=', 'inventories.product_id')
            ->where('warehouses.code', 'like', 'PERF-%')
            ->orderBy('inventories.id')
            ->limit(100)
            ->get([
                'inventories.id',
                'inventories.quantity',
                'inventories.reserved_quantity',
                'products.sku',
                'warehouses.code',
            ]),
    ],
    'finance_common_list' => [
        'limit_ms' => 1000.0,
        'growing_tables' => ['invoices'],
        'run' => static fn () => DB::table('invoices')
            ->where('invoice_number', 'like', 'PERF-LEGACY4X-%')
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'invoice_number', 'status', 'total', 'issued_at']),
    ],
    'management_report_bounded' => [
        'limit_ms' => 3000.0,
        'growing_tables' => ['orders'],
        'run' => static fn () => DB::table('orders')
            ->where('order_number', 'like', 'PERF-LEGACY4X-%')
            ->whereBetween('created_at', ['2026-01-01 00:00:00', '2026-12-31 23:59:59'])
            ->selectRaw('status, COUNT(*) as order_count, SUM(grand_total) as revenue')
            ->groupBy('status')
            ->orderBy('status')
            ->get(),
    ],
    'field_tracking_current' => [
        'limit_ms' => 1000.0,
        // This is a bounded current-state projection, not tracking history:
        // driver_current_locations has UNIQUE(driver_id), so it can hold at most
        // one mutable row per driver. MySQL may correctly prefer a 25-row scan
        // over the received_at index in CI; that is not an unbounded growth risk.
        'growing_tables' => [],
        'growth_note' => 'Bounded current-state table: UNIQUE(driver_id) keeps one row per driver; full-scan rejection applies to historical/growing tables.',
        'run' => static fn () => DB::table('driver_current_locations')
            ->orderByDesc('received_at')
            ->limit(50)
            ->get(['driver_id', 'store_id', 'channel', 'received_at']),
    ],
    'b2b_accounts_common_list' => [
        'limit_ms' => 1000.0,
        'growing_tables' => ['b2b_accounts'],
        'run' => static fn () => DB::table('b2b_accounts')
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'customer_id', 'status', 'credit_limit']),
    ],
];

$evidence = [
    'schema_version' => 1,
    'issue' => 2274,
    'profile' => getenv('FOODEX_PERF_PROFILE') ?: 'legacy4x',
    'generated_at' => now()->toISOString(),
    'driver' => DB::connection()->getDriverName(),
    'scenarios' => [],
];

$failed = false;
$explain = new ExplainEvidenceTool;

foreach ($scenarios as $name => $scenario) {
    $durations = [];
    $activeScenario = null;

    for ($warmup = 0; $warmup < 2; $warmup++) {
        $scenario['run']();
    }

    $events[$name] = [];
    $activeScenario = $name;

    for ($sample = 0; $sample < 20; $sample++) {
        $started = hrtime(true);
        $scenario['run']();
        $durations[] = (hrtime(true) - $started) / 1_000_000;
    }

    $activeScenario = null;
    $summary = PercentileStatistics::summarize($durations);
    $probe = new SlowQueryProbe(250.0);

    foreach ($events[$name] as $event) {
        $probe->record($event['sql'], $event['time_ms'], $event['connection']);
    }

    $slow = $probe->snapshot();
    $slowestEvent = null;
    foreach ($events[$name] as $event) {
        if ($slowestEvent === null || $event['time_ms'] > $slowestEvent['time_ms']) {
            $slowestEvent = $event;
        }
    }

    $plan = null;
    if (is_array($slowestEvent)) {
        $plan = $explain->capture(
            $slowestEvent['sql'],
            $slowestEvent['bindings'],
            $scenario['growing_tables'],
            $slowestEvent['connection'],
        );
    }

    $passesLatency = $summary['p95'] <= $scenario['limit_ms'];
    $passesSlowQuery = $slow['flagged_query_count'] === 0;
    $passesPlan = ! is_array($plan) || $plan['investigation_required'] === false;
    $passes = $passesLatency && $passesSlowQuery && $passesPlan;
    $failed = $failed || ! $passes;

    $evidence['scenarios'][$name] = [
        'samples' => count($durations),
        'limit_p95_ms' => $scenario['limit_ms'],
        'growth_note' => $scenario['growth_note'] ?? null,
        'metrics' => $summary,
        'queries' => [
            'count' => count($events[$name]),
            ...$slow,
        ],
        'explain' => $plan,
        'pass' => $passes,
    ];
}

$evidence['pass'] = ! $failed;

$directory = dirname($output);
if (! is_dir($directory)) {
    mkdir($directory, 0777, true);
}

file_put_contents(
    $output,
    json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
);

echo json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

exit($failed ? 1 : 0);
