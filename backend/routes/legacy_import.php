<?php

use App\Services\LegacyFoodexImportService;
use Illuminate\Support\Facades\Artisan;

Artisan::command(
    'foodex:legacy-preflight {--json : Output machine-readable JSON}',
    function (LegacyFoodexImportService $importer): int {
        try {
            $report = $importer->preflight();
        } catch (Throwable $exception) {
            $this->error('Legacy Foodex preflight failed: '.$exception->getMessage());

            return 1;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $this->info('Legacy Foodex SQL Server preflight');
        $this->line('Source DB: '.$report['source_database']);
        $this->line('Target driver: '.$report['target']['database_driver']);
        $this->line('Transaction range: '.($report['transaction_date_range']['first'] ?? 'n/a').' -> '.($report['transaction_date_range']['last'] ?? 'n/a'));
        $this->line(
            'Stock snapshot: '.
            ($report['stock_snapshot']['first_date'] ?? 'n/a').' -> '.
            ($report['stock_snapshot']['last_date'] ?? 'n/a').
            ' | rows='.$report['stock_snapshot']['rows'].
            ' | stores='.$report['stock_snapshot']['stores'].
            ' | items='.$report['stock_snapshot']['items'].
            ' | cutover_usable='.($report['stock_snapshot']['usable_as_cutover_stock'] ? 'yes' : 'no')
        );
        $this->line('Tracking schema ready: '.($report['target']['tracking_ready'] ? 'yes' : 'no'));
        $this->line(
            'Target clean baseline: '.($report['target']['clean_baseline_ready'] ? 'yes' : 'no').
            ' | business_rows='.$report['target']['business_total']
        );
        $this->line(
            'Legacy masters rerun ready: '.(($report['target']['legacy_masters_rerun_ready'] ?? false) ? 'yes' : 'no')
        );

        $this->table(
            ['Price matrix check', 'Candidates', 'Non-linear'],
            collect($report['price_matrix_consistency'])
                ->map(fn (array $row, string $key): array => [
                    $key,
                    (int) $row['candidate_rows'],
                    (int) $row['mismatch_rows'],
                ])
                ->values()
                ->all(),
        );

        $this->table(
            ['Source table', 'Rows'],
            collect($report['source_counts'])
                ->map(fn ($count, $table): array => [(string) $table, (int) $count])
                ->values()
                ->all(),
        );

        if ($report['target']['b2b_stores'] !== []) {
            $this->table(
                ['B2B store ID', 'Code', 'Name'],
                collect($report['target']['b2b_stores'])
                    ->map(fn (array $store): array => [$store['id'], $store['code'], $store['name']])
                    ->all(),
            );
        } else {
            $this->warn('No active B2B store exists yet. Apply mode will resolve/create the canonical wholesale principal.');
        }

        return 0;
    },
)->purpose('Read-only validation of the legacy Foodex SQL Server source and the MySQL migration target.');

Artisan::command(
    'foodex:legacy-import {phase=masters : Import phase} {--apply : Persist changes; omitted means dry-run} {--json : Output machine-readable JSON}',
    function (LegacyFoodexImportService $importer): int {
        $phase = strtolower(trim((string) $this->argument('phase')));
        if (! in_array($phase, ['masters', 'extended-masters'], true)) {
            $this->error('Supported phases: masters, extended-masters.');

            return 2;
        }

        try {
            $result = $phase === 'masters'
                ? $importer->importMasters((bool) $this->option('apply'))
                : $importer->importExtendedMasters((bool) $this->option('apply'));
        } catch (Throwable $exception) {
            $this->error('Legacy Foodex import failed: '.$exception->getMessage());

            return 1;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        if (! (bool) $this->option('apply')) {
            $this->info('Legacy Foodex '.$phase.' dry-run');
            $this->table(
                ['Planned entity', 'Rows'],
                collect($result['planned'])
                    ->map(fn ($count, $entity): array => [(string) $entity, (int) $count])
                    ->values()
                    ->all(),
            );
            $this->warn('Dry-run only. Re-run with --apply after reviewing these counts.');

            return 0;
        }

        $this->info('Legacy Foodex '.$phase.' import completed.');
        $this->line('Run ID: '.$result['summary']['run_id']);
        $this->line('Wholesale store ID: '.$result['summary']['store_id']);

        foreach ($result['summary'] as $section => $summary) {
            if (! is_array($summary)) {
                continue;
            }

            $this->line($section.': '.json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return 0;
    },
)->purpose('Idempotently migrate legacy Foodex master data into canonical FOODEX tables with provenance and quarantine.');


Artisan::command(
    'foodex:legacy-stock-diagnostics {--deep : Run the slower quantity/rebuild preview} {--json : Output machine-readable JSON}',
    function (LegacyFoodexImportService $importer): int {
        $deep = (bool) $this->option('deep');

        if (! (bool) $this->option('json')) {
            $this->info('Legacy Foodex stock diagnostics: '.($deep ? 'DEEP' : 'QUICK').' mode');
            if (! $deep) {
                $this->line('Heavy quantity/rebuild aggregation is skipped. Use --deep only after quick diagnostics pass.');
            }
        }

        try {
            $report = $importer->stockDiagnostics(
                $deep,
                function (string $stage, string $state, ?float $elapsed): void {
                    if ((bool) $this->option('json')) {
                        return;
                    }

                    if ($state === 'start') {
                        $this->line('>> '.$stage.' ...');

                        return;
                    }

                    $this->line('   '.$stage.' DONE in '.number_format((float) $elapsed, 3).'s');
                },
            );
        } catch (Throwable $exception) {
            $this->error('Legacy Foodex stock diagnostics failed: '.$exception->getMessage());

            return 1;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $this->line(
            'Posted source: headers='.$report['source']['posted_headers'].
            ' | details='.($report['source']['posted_details'] ?? 'n/a').
            ' | recognized headers='.$report['source']['recognized_stock_headers'].
            ' | recognized details='.($report['source']['recognized_stock_details'] ?? 'n/a')
        );
        $this->line(
            'Opening balances='.$report['source']['opening_balance_headers'].
            ' | car wrappers 17/18='.$report['source']['car_wrapper_headers_17_18'].
            ' | linked car headers='.$report['source']['linked_car_headers'].
            ' | car source headers='.$report['source']['car_source_headers']
        );

        $this->table(
            ['Type', 'Name', 'Headers', 'Posted', 'Details', 'Sign', 'First', 'Last'],
            collect($report['types'])->map(static fn (array $row): array => [
                $row['type'],
                $row['name'],
                $row['headers'],
                $row['posted_headers'],
                $row['details'],
                $row['recognized_stock_type'] ? $row['stock_sign'] : 'n/a',
                $row['first_date'] ?? 'n/a',
                $row['last_date'] ?? 'n/a',
            ])->all(),
        );

        $this->line(
            'Mapping coverage: locations='.$report['mapping_coverage']['used_locations'].
            ' | unmapped_locations='.count($report['mapping_coverage']['unmapped_locations']).
            ' | items='.($report['mapping_coverage']['used_items'] ?? 'n/a').
            ' | mapped_products='.($report['mapping_coverage']['mapped_products'] ?? 'n/a').
            ' | unmapped_items='.count($report['mapping_coverage']['unmapped_items'])
        );

        if ($report['quantity'] !== null) {
            $this->line(
                'Quantity basis: lines='.$report['quantity']['lines'].
                ' | TransPkgQty1='.$report['quantity']['using_trans_pkg_qty1'].
                ' | fallback='.$report['quantity']['using_fallback'].
                ' | negative_raw='.$report['quantity']['negative_raw_qty'].
                ' | conversion_mismatch='.$report['quantity']['transpkg_vs_conversion_mismatch'].
                ' | unresolved_package_conversions='.($report['quantity']['unresolved_package_conversions'] ?? 0)
            );
        } else {
            $this->line('Quantity basis: skipped in QUICK mode.');
        }

        $this->table(
            ['Pair', 'Groups', 'Complete', 'Missing A', 'Missing B', 'Duplicate sides'],
            collect($report['pairing'])->map(static fn (array $row): array => [
                $row['label'].' ('.$row['type_a'].'/'.$row['type_b'].')',
                $row['groups'],
                $row['complete_groups'],
                $row['missing_type_a'],
                $row['missing_type_b'],
                $row['duplicate_side_groups'],
            ])->all(),
        );

        if ($report['rebuild_preview'] !== null) {
            $this->line(
                'Rebuild preview: positions='.$report['rebuild_preview']['positions'].
                ' | positive='.$report['rebuild_preview']['positive_positions'].
                ' | zero='.$report['rebuild_preview']['zero_positions'].
                ' | negative='.$report['rebuild_preview']['negative_positions']
            );
        } else {
            $this->line('Rebuild preview: skipped in QUICK mode.');
        }

        if ($report['unknown_posted_types'] !== []) {
            $this->warn('Posted transaction types outside the provisional stock rules exist. Apply remains disabled.');
            $this->table(
                ['Type', 'Name', 'Posted headers', 'Details'],
                collect($report['unknown_posted_types'])->map(static fn (array $row): array => [
                    $row['type'],
                    $row['name'],
                    $row['posted_headers'],
                    $row['details'],
                ])->all(),
            );
        }

        if ($report['mapping_coverage']['unmapped_locations'] !== []) {
            $this->warn('Unmapped source locations: '.implode(', ', array_slice($report['mapping_coverage']['unmapped_locations'], 0, 30)));
        }
        if ($report['mapping_coverage']['unmapped_items'] !== []) {
            $this->warn('Unmapped source items: '.implode(', ', array_slice($report['mapping_coverage']['unmapped_items'], 0, 30)));
        }
        if (($report['rebuild_preview']['negative_samples'] ?? []) !== []) {
            $this->warn('Negative reconstructed positions exist; review is required before stock apply.');
            if (($report['rebuild_preview']['negative_store_summary'] ?? []) !== []) {
                $this->table(
                    ['Store', 'Name', 'Negative positions', 'Total negative qty', 'By type'],
                    collect($report['rebuild_preview']['negative_store_summary'])->map(static fn (array $row): array => [
                        $row['store_id'],
                        $row['name'],
                        $row['negative_positions'],
                        $row['total_negative_quantity'],
                        json_encode($row['by_type'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ])->all(),
                );
            }

            $this->table(
                ['Store', 'Name', 'Item', 'Quantity', 'Lines', 'First', 'Last', 'By type'],
                collect($report['rebuild_preview']['negative_samples'])->map(static fn (array $row): array => [
                    $row['store_id'],
                    $row['store_name'] ?? '',
                    $row['item_id'],
                    $row['quantity'],
                    $row['movement_lines'],
                    $row['first_date'] ?? 'n/a',
                    $row['last_date'] ?? 'n/a',
                    json_encode($row['by_type'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ])->all(),
            );
        }
        if (($report['quantity']['unresolved_package_samples'] ?? []) !== []) {
            $this->warn('Package 4/5 lines without TransPkgQty1 cannot be reconstructed safely.');
            $this->table(
                ['Detail', 'Header', 'Item', 'Package', 'Qty'],
                collect($report['quantity']['unresolved_package_samples'])->map(static fn (array $row): array => [
                    $row['detail_id'],
                    $row['header_id'],
                    $row['item_id'],
                    $row['package'],
                    $row['qty'],
                ])->all(),
            );
        }

        $this->table(
            ['Stage', 'Seconds'],
            collect($report['timings_seconds'])
                ->map(static fn ($seconds, $stage): array => [$stage, number_format((float) $seconds, 3)])
                ->values()
                ->all(),
        );

        $this->warn(
            $deep
                ? 'DEEP diagnostics completed. Stock apply remains intentionally disabled.'
                : 'QUICK diagnostics completed. No stock was written.'
        );

        return 0;
    },
)->purpose('Diagnose legacy Foodex transaction semantics before rebuilding 2026 inventory.');


Artisan::command(
    'foodex:legacy-stock-source-diagnostics {--json : Output machine-readable JSON}',
    function (LegacyFoodexImportService $importer): int {
        try {
            $report = $importer->stockSourceDiagnostics(
                function (string $stage, string $state, ?float $elapsed): void {
                    if ((bool) $this->option('json')) {
                        return;
                    }

                    if ($state === 'start') {
                        $this->line('>> '.$stage.' ...');

                        return;
                    }

                    $this->line('   '.$stage.' DONE in '.number_format((float) $elapsed, 3).'s');
                },
            );
        } catch (Throwable $exception) {
            $this->error('Legacy Foodex stock source diagnostics failed: '.$exception->getMessage());

            return 1;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $this->info('Legacy Foodex stock source diagnostics');

        $this->table(
            ['Table', 'Rows'],
            collect($report['table_counts'])->map(
                static fn ($rows, $table): array => [$table, $rows ?? 'n/a']
            )->values()->all(),
        );

        if ($report['item_stock_snapshot'] !== null) {
            $s = $report['item_stock_snapshot'];
            $this->line(
                'wh_ItemStockOnHand: rows='.$s['rows'].
                ' | stores='.$s['stores'].
                ' | items='.$s['items'].
                ' | dates='.($s['first_date'] ?? 'n/a').' -> '.($s['last_date'] ?? 'n/a').
                ' | negative='.$s['negative_rows'].
                ' | zero='.$s['zero_rows'].
                ' | positive='.$s['positive_rows']
            );
        }

        $p = $report['previous_year_flags'];
        $this->line(
            'PrevYear: flagged='.$p['prev_year_headers'].
            ' | current='.$p['current_year_headers'].
            ' | headers_20251231='.$p['headers_20251231'].
            ' | type11_20251231='.$p['type11_20251231'].
            ' | prev_year_20251231='.$p['prev_year_20251231']
        );

        if ($report['stock_related_modules'] === []) {
            $this->warn('No stock-related SQL view/procedure/function was discovered from module metadata.');
        } else {
            $this->table(
                ['Schema', 'Object', 'Type', 'Reason', 'References'],
                collect($report['stock_related_modules'])->map(static fn (array $row): array => [
                    $row['schema'],
                    $row['name'],
                    $row['type'],
                    $row['reason'],
                    implode(', ', $row['references'] ?? []),
                ])->all(),
            );
        }

        if (($report['timings_seconds'] ?? []) !== []) {
            $this->table(
                ['Stage', 'Seconds'],
                collect($report['timings_seconds'])
                    ->map(static fn ($seconds, $stage): array => [$stage, number_format((float) $seconds, 3)])
                    ->values()
                    ->all(),
            );
        }

        $this->warn('Read-only discovery only. No FOODEX inventory rows were written.');

        return 0;
    },
)->purpose('Discover the legacy Foodex authoritative current-stock calculation/source.');


Artisan::command(
    'foodex:legacy-stock-routine-diagnostics {--json : Output machine-readable JSON}',
    function (LegacyFoodexImportService $importer): int {
        try {
            $report = $importer->stockRoutineDiagnostics(
                function (string $stage, string $state, ?float $elapsed): void {
                    if ((bool) $this->option('json')) {
                        return;
                    }

                    if ($state === 'start') {
                        $this->line('>> '.$stage.' ...');

                        return;
                    }

                    $this->line('   '.$stage.' DONE in '.number_format((float) $elapsed, 3).'s');
                },
            );
        } catch (Throwable $exception) {
            $this->error('Legacy Foodex stock routine diagnostics failed: '.$exception->getMessage());

            return 1;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $this->info('Legacy Foodex stock routine diagnostics');

        foreach ($report['objects'] as $object) {
            $this->newLine();
            $this->line('=== '.$object['schema'].'.'.$object['name'].' ['.$object['type'].'] ===');
            $this->line(
                'definition_length='.$object['definition_length'].
                ' | transaction_types='.
                ($object['transaction_types_detected'] === []
                    ? 'n/a'
                    : implode(',', $object['transaction_types_detected']))
            );

            $activeSignals = collect($object['signals'])
                ->filter(static fn ($enabled): bool => (bool) $enabled)
                ->keys()
                ->implode(', ');
            $this->line('signals: '.($activeSignals !== '' ? $activeSignals : 'none'));

            if ($object['parameters'] !== []) {
                $this->table(
                    ['#', 'Parameter', 'Type', 'Length', 'Precision', 'Scale', 'Output'],
                    collect($object['parameters'])->map(static fn (array $row): array => [
                        $row['id'],
                        $row['name'],
                        $row['type'],
                        $row['max_length'],
                        $row['precision'],
                        $row['scale'],
                        $row['is_output'] ? 'yes' : '',
                    ])->all(),
                );
            }

            if ($object['columns'] !== []) {
                $this->table(
                    ['#', 'Column', 'Type'],
                    collect($object['columns'])->map(static fn (array $row): array => [
                        $row['id'],
                        $row['name'],
                        $row['type'],
                    ])->all(),
                );
            }

            $this->line('--- definition ---');
            $this->line($object['definition'] !== '' ? $object['definition'] : '[definition unavailable]');
            $this->line('--- end definition ---');
        }

        if (($report['timings_seconds'] ?? []) !== []) {
            $this->newLine();
            $this->table(
                ['Stage', 'Seconds'],
                collect($report['timings_seconds'])
                    ->map(static fn ($seconds, $stage): array => [$stage, number_format((float) $seconds, 3)])
                    ->values()
                    ->all(),
            );
        }

        $this->warn('Read-only routine inspection only. No FOODEX inventory rows were written.');

        return 0;
    },
)->purpose('Inspect the canonical legacy Foodex stock routines and views used to calculate current stock.');
