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
            ' | details='.$report['source']['posted_details'].
            ' | recognized headers='.$report['source']['recognized_stock_headers'].
            ' | recognized details='.$report['source']['recognized_stock_details']
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
            ' | items='.$report['mapping_coverage']['used_items'].
            ' | unmapped_items='.count($report['mapping_coverage']['unmapped_items'])
        );

        if ($report['quantity'] !== null) {
            $this->line(
                'Quantity basis: lines='.$report['quantity']['lines'].
                ' | TransPkgQty1='.$report['quantity']['using_trans_pkg_qty1'].
                ' | fallback='.$report['quantity']['using_fallback'].
                ' | negative_raw='.$report['quantity']['negative_raw_qty'].
                ' | conversion_mismatch='.$report['quantity']['transpkg_vs_conversion_mismatch']
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
            $this->table(
                ['Store', 'Item', 'Quantity', 'Lines'],
                collect($report['rebuild_preview']['negative_samples'])->map(static fn (array $row): array => [
                    $row['store_id'],
                    $row['item_id'],
                    $row['quantity'],
                    $row['movement_lines'],
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
