<?php

use App\Services\LegacyFoodexImportService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

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
        if (! in_array($phase, ['masters', 'extended-masters', 'stock', 'sales-history', 'purchase-history', 'operational-history'], true)) {
            $this->error('Supported phases: masters, extended-masters, stock, sales-history, purchase-history, operational-history.');

            return 2;
        }

        $progress = function (string $stage, string $state, ?float $elapsed): void {
            if ((bool) $this->option('json')) {
                return;
            }

            if ($state === 'start') {
                $this->line('>> '.$stage.' ...');

                return;
            }

            $this->line('   '.$stage.' DONE in '.number_format((float) $elapsed, 3).'s');
        };

        try {
            $result = match ($phase) {
                'masters' => $importer->importMasters((bool) $this->option('apply')),
                'extended-masters' => $importer->importExtendedMasters((bool) $this->option('apply')),
                'stock' => $importer->importStock((bool) $this->option('apply'), $progress),
                'sales-history' => $importer->importSalesHistory((bool) $this->option('apply'), $progress),
                'purchase-history' => $importer->importPurchaseHistory((bool) $this->option('apply'), $progress),
                'operational-history' => $importer->importOperationalHistory((bool) $this->option('apply'), $progress),
            };
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
            if ($phase === 'sales-history') {
                if (($result['diagnostics'] ?? []) !== []) {
                    $this->newLine();
                    $this->info('Sales-history diagnostics');
                    $this->table(
                        ['Metric', 'Count'],
                        collect($result['diagnostics'])
                            ->map(fn ($count, $metric): array => [(string) $metric, (int) $count])
                            ->values()
                            ->all(),
                    );
                }

                $samples = $result['samples'] ?? [];

                if (($samples['sales_headers_without_lines'] ?? []) !== []) {
                    $this->warn('Sales headers without lines: '.implode(', ', $samples['sales_headers_without_lines']));
                }

                if (($samples['line_total_mismatches'] ?? []) !== []) {
                    $this->newLine();
                    $this->warn('Line-total mismatch samples');
                    $this->table(
                        ['Header', 'Header total', 'Detail sum', 'Difference', 'Detail count'],
                        collect($samples['line_total_mismatches'])->map(static fn (array $row): array => [
                            $row['header_id'],
                            $row['header_total'],
                            $row['detail_value_sum'],
                            $row['difference'],
                            $row['detail_count'],
                        ])->all(),
                    );
                }

                if (($samples['sales_zero_qty_lines'] ?? []) !== []) {
                    $this->newLine();
                    $this->warn('Zero-quantity sales line samples');
                    $this->table(
                        ['Detail', 'Header', 'Item', 'Value'],
                        collect($samples['sales_zero_qty_lines'])->map(static fn (array $row): array => [
                            $row['detail_id'],
                            $row['header_id'],
                            $row['item_id'],
                            $row['value'],
                        ])->all(),
                    );
                }

                if (($samples['paid_vs_cash_visa'] ?? []) !== []) {
                    $this->newLine();
                    $this->warn('Paid vs PaidCash+PaidByVisa samples');
                    $this->table(
                        ['Header', 'Type', 'Date', 'PayType', 'Net', 'Paid', 'Cash', 'Visa', 'Cash+Visa', 'Balance'],
                        collect($samples['paid_vs_cash_visa'])->map(static fn (array $row): array => [
                            $row['header_id'],
                            $row['type'],
                            $row['date'],
                            $row['pay_type'],
                            $row['net_total'],
                            $row['paid'],
                            $row['paid_cash'],
                            $row['paid_visa'],
                            $row['cash_plus_visa'],
                            $row['balance'],
                        ])->all(),
                    );
                }

                if (($samples['payment_balance_mismatches'] ?? []) !== []) {
                    $this->newLine();
                    $this->warn('NetTotal - Paid vs Balance samples');
                    $this->table(
                        ['Header', 'Type', 'Date', 'Net', 'Paid', 'Balance', 'Net-Paid', 'Cash', 'Visa', 'PayType'],
                        collect($samples['payment_balance_mismatches'])->map(static fn (array $row): array => [
                            $row['header_id'],
                            $row['type'],
                            $row['date'],
                            $row['net_total'],
                            $row['paid'],
                            $row['balance'],
                            $row['net_minus_paid'],
                            $row['paid_cash'],
                            $row['paid_visa'],
                            $row['pay_type'],
                        ])->all(),
                    );
                }
            }

            if ($phase === 'purchase-history') {
                $samples = $result['samples'] ?? [];

                if (($samples['nonzero_headers_without_lines'] ?? []) !== []) {
                    $this->warn(
                        'Non-zero procurement headers without lines: '.
                        implode(', ', $samples['nonzero_headers_without_lines'])
                    );
                }

                if (($samples['invalid_supplier_headers'] ?? []) !== []) {
                    $this->newLine();
                    $this->warn('Invalid/unmapped supplier header samples');
                    $this->table(
                        ['Header', 'Type', 'Supplier', 'Location', 'Total', 'NetTotal'],
                        collect($samples['invalid_supplier_headers'])->map(static fn (array $row): array => [
                            $row['header_id'],
                            $row['type'],
                            $row['supplier_id'],
                            $row['location_id'],
                            $row['total'],
                            $row['net_total'],
                        ])->all(),
                    );
                }

                if (($samples['missing_source_supplier_headers'] ?? []) !== []) {
                    $this->newLine();
                    $this->line('Source purchases with SupplierId=0 will be preserved without inventing a supplier.');
                    $this->table(
                        ['Header', 'Type', 'Supplier', 'Location', 'Total', 'NetTotal'],
                        collect($samples['missing_source_supplier_headers'])->map(static fn (array $row): array => [
                            $row['header_id'],
                            $row['type'],
                            $row['supplier_id'],
                            $row['location_id'],
                            $row['total'],
                            $row['net_total'],
                        ])->all(),
                    );
                }

                if (($samples['invalid_location_headers'] ?? []) !== []) {
                    $this->newLine();
                    $this->warn('Invalid/unmapped location header samples');
                    $this->table(
                        ['Header', 'Type', 'Supplier', 'Location', 'Total', 'NetTotal'],
                        collect($samples['invalid_location_headers'])->map(static fn (array $row): array => [
                            $row['header_id'],
                            $row['type'],
                            $row['supplier_id'],
                            $row['location_id'],
                            $row['total'],
                            $row['net_total'],
                        ])->all(),
                    );
                }

                if (($samples['line_total_mismatches'] ?? []) !== []) {
                    $this->newLine();
                    $this->warn('Purchase line-total mismatch samples');
                    $this->table(
                        ['Header', 'Type', 'Header total', 'Detail sum', 'Difference', 'Detail count'],
                        collect($samples['line_total_mismatches'])->map(static fn (array $row): array => [
                            $row['header_id'],
                            $row['type'],
                            $row['header_total'],
                            $row['detail_value_sum'],
                            $row['difference'],
                            $row['detail_count'],
                        ])->all(),
                    );
                }

                if (($samples['zero_effect_headers_skipped'] ?? []) !== []) {
                    $this->line(
                        'Zero-effect procurement headers to skip: '.
                        implode(', ', $samples['zero_effect_headers_skipped'])
                    );
                }
            }

            if ($phase === 'operational-history') {
                $this->newLine();
                $this->info('Operational-history type counts');
                $this->table(
                    ['Type', 'Headers', 'Details'],
                    collect($result['type_counts'] ?? [])->map(
                        static fn ($count, $type): array => [
                            (string) $type,
                            (int) $count,
                            (int) (($result['detail_counts_by_type'][$type] ?? 0)),
                        ],
                    )->values()->all(),
                );

                $this->newLine();
                $this->info('Operational pairing diagnostics');
                $this->table(
                    ['Metric', 'Count'],
                    collect($result['pairing'] ?? [])->map(
                        static fn ($count, $metric): array => [(string) $metric, (int) $count],
                    )->values()->all(),
                );

                $samples = $result['samples'] ?? [];
                if (($samples['pair_anomalies'] ?? []) !== []) {
                    $this->newLine();
                    $this->warn('Operational pair anomaly samples (preserved as history; no stock replay)');
                    $this->table(
                        ['Family', 'Key', 'Headers', 'Types'],
                        collect($samples['pair_anomalies'])->map(static fn (array $row): array => [
                            $row['family'],
                            $row['key'],
                            $row['headers'],
                            json_encode($row['types'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ])->all(),
                    );
                }

                if (($samples['invalid_location_headers'] ?? []) !== []) {
                    $this->newLine();
                    $this->warn('Invalid/unmapped operational location samples');
                    $this->table(
                        ['Header', 'Type', 'Location'],
                        collect($samples['invalid_location_headers'])->map(static fn (array $row): array => [
                            $row['header_id'],
                            $row['type'],
                            $row['location_id'],
                        ])->all(),
                    );
                }
            }

            $this->warn('Dry-run only. Re-run with --apply after reviewing these counts.');

            return 0;
        }

        $this->info('Legacy Foodex '.$phase.' import completed.');
        $this->line('Run ID: '.$result['summary']['run_id']);

        if (array_key_exists('store_id', $result['summary'])) {
            $this->line('Wholesale store ID: '.$result['summary']['store_id']);
        }

        if (array_key_exists('authority', $result['summary'])) {
            $this->line('Authority: '.$result['summary']['authority']);
        }

        foreach ($result['summary'] as $section => $summary) {
            if (! is_array($summary)) {
                continue;
            }

            $this->line($section.': '.json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return 0;
    },
)->purpose('Migrate legacy Foodex masters, stock baseline, sales/procurement history, or immutable operational history with provenance and safety gates.');


Artisan::command(
    'foodex:legacy-customer360-backfill {--apply : Persist changes; omitted means dry-run} {--json : Output machine-readable JSON}',
    function (LegacyFoodexImportService $importer): int {
        try {
            $result = $importer->backfillCustomer360((bool) $this->option('apply'));
        } catch (Throwable $exception) {
            $this->error('Legacy Foodex Customer 360 backfill failed: '.$exception->getMessage());

            return 1;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        if (! (bool) $this->option('apply')) {
            $this->info('Legacy Foodex Customer 360 backfill dry-run');
            $this->table(
                ['Planned entity', 'Rows'],
                collect($result['planned'])
                    ->map(fn ($count, $entity): array => [(string) $entity, (int) $count])
                    ->values()
                    ->all(),
            );
            $this->warn('Dry-run only. Run again with --apply after reviewing the mapping counts.');

            return 0;
        }

        $this->info('Legacy Foodex Customer 360 backfill completed.');
        $this->line('Run ID: '.$result['summary']['run_id']);
        $this->line(
            'Created='.$result['summary']['created'].
            ' | Updated='.$result['summary']['updated'].
            ' | Mapped='.$result['summary']['mapped']
        );

        return 0;
    },
)->purpose('Materialize provenance-owned legacy Foodex customers into Customer 360 without creating login users.');


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


Artisan::command(
    'foodex:legacy-stock-verify {--json : Output machine-readable JSON}',
    function (): int {
        $latestRun = DB::table('legacy_import_runs')
            ->where('source_system', 'foodex_sqlserver')
            ->where('phase', 'stock')
            ->orderByDesc('started_at')
            ->first(['id', 'status', 'started_at', 'finished_at', 'summary', 'error']);

        $inventory = [
            'total' => (int) DB::table('inventories')->count(),
            'positive' => (int) DB::table('inventories')->where('quantity', '>', 0)->count(),
            'zero' => (int) DB::table('inventories')->where('quantity', '=', 0)->count(),
            'negative' => (int) DB::table('inventories')->where('quantity', '<', 0)->count(),
            'reserved_nonzero' => (int) DB::table('inventories')->where('reserved_quantity', '<>', 0)->count(),
        ];

        $baselineMovements = (int) DB::table('stock_movements')
            ->where('type', 'legacy_opening_balance')
            ->where('reference_type', 'legacy_foodex_stock_baseline')
            ->count();

        $allMovements = (int) DB::table('stock_movements')->count();

        $mappings = (int) DB::table('legacy_import_mappings')
            ->where('source_system', 'foodex_sqlserver')
            ->where('source_table', 'wh_vw_ItemStock2Now')
            ->where('target_table', 'inventories')
            ->distinct()
            ->count('target_id');

        $orphanMappings = (int) DB::table('legacy_import_mappings as m')
            ->leftJoin('inventories as i', 'i.id', '=', 'm.target_id')
            ->where('m.source_system', 'foodex_sqlserver')
            ->where('m.source_table', 'wh_vw_ItemStock2Now')
            ->where('m.target_table', 'inventories')
            ->whereNull('i.id')
            ->count();

        $report = [
            'latest_run' => $latestRun === null ? null : [
                'id' => (string) $latestRun->id,
                'status' => (string) $latestRun->status,
                'started_at' => $latestRun->started_at,
                'finished_at' => $latestRun->finished_at,
                'summary' => $latestRun->summary === null ? null : json_decode((string) $latestRun->summary, true),
                'error' => $latestRun->error,
            ],
            'inventories' => $inventory,
            'stock_movements' => [
                'total' => $allMovements,
                'legacy_baseline' => $baselineMovements,
            ],
            'legacy_inventory_mappings' => $mappings,
            'orphan_mappings' => $orphanMappings,
            'healthy' => $latestRun !== null
                && (string) $latestRun->status === 'completed'
                && $inventory['negative'] === 0
                && $inventory['reserved_nonzero'] === 0
                && $inventory['total'] === $baselineMovements
                && $inventory['total'] === $mappings
                && $allMovements === $baselineMovements
                && $orphanMappings === 0,
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $report['healthy'] ? 0 : 1;
        }

        $this->info('Legacy Foodex stock verification');

        if ($report['latest_run'] === null) {
            $this->error('No stock import run exists.');

            return 1;
        }

        $this->line(
            'Latest stock run: '.$report['latest_run']['id'].
            ' | status='.$report['latest_run']['status']
        );

        $this->table(
            ['Metric', 'Count'],
            [
                ['inventories.total', $inventory['total']],
                ['inventories.positive', $inventory['positive']],
                ['inventories.zero', $inventory['zero']],
                ['inventories.negative', $inventory['negative']],
                ['inventories.reserved_nonzero', $inventory['reserved_nonzero']],
                ['stock_movements.total', $allMovements],
                ['stock_movements.legacy_baseline', $baselineMovements],
                ['legacy_inventory_mappings', $mappings],
                ['orphan_mappings', $orphanMappings],
            ],
        );

        if ($report['healthy']) {
            $this->info('Stock baseline verification PASSED.');

            return 0;
        }

        $this->error('Stock baseline verification FAILED. Do not rerun/apply additional stock until reviewed.');

        return 1;
    },
)->purpose('Verify the committed legacy Foodex stock baseline after import.');


Artisan::command(
    'foodex:legacy-sales-header-diagnostics {headerId* : Legacy wh_TransHeader IDs} {--json : Output machine-readable JSON}',
    function (LegacyFoodexImportService $importer): int {
        try {
            $report = $importer->salesHistoryHeaderDiagnostics(
                (array) $this->argument('headerId'),
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
            $this->error('Legacy Foodex sales header diagnostics failed: '.$exception->getMessage());

            return 1;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $this->info('Legacy Foodex sales header diagnostics');

        foreach ($report['headers'] as $headerId => $row) {
            $this->newLine();
            $this->line('=== Header '.$headerId.' ===');

            if ($row['header'] === null) {
                $this->error('Header not found.');
                continue;
            }

            $h = $row['header'];
            $this->table(
                ['Type', 'Date', 'Customer', 'Store', 'PayType', 'Paid', 'Cash', 'Visa', 'Total', 'NetTotal', 'Balance', 'Status'],
                [[
                    $h['TransType'] ?? '',
                    $h['TransDate'] ?? '',
                    $h['CustomerId'] ?? '',
                    $h['StoreId'] ?? '',
                    $h['PayType'] ?? '',
                    $h['Paid'] ?? '',
                    $h['PaidCash'] ?? '',
                    $h['PaidByVisa'] ?? '',
                    $h['Total'] ?? '',
                    $h['NetTotal'] ?? '',
                    $h['Balance'] ?? '',
                    $h['Status'] ?? '',
                ]],
            );

            $this->line(
                'details_total='.$row['details_total'].
                ' | active='.$row['details_active'].
                ' | value_sum_all='.$row['detail_value_sum_all'].
                ' | value_sum_active='.$row['detail_value_sum_active'].
                ' | base_qty_sum_active='.$row['detail_base_qty_sum_active']
            );

            if ($row['details'] !== []) {
                $this->table(
                    ['Detail', 'Status', 'Item', 'Pkg', 'Qty', 'BaseQty', 'Price', 'BasePrice', 'Value', 'Tax'],
                    collect($row['details'])->map(static fn (array $d): array => [
                        $d['DetailId'] ?? '',
                        $d['Status'] ?? '',
                        $d['ItemID'] ?? '',
                        $d['Package'] ?? '',
                        $d['Qty'] ?? '',
                        $d['TransPkgQty1'] ?? '',
                        $d['Price'] ?? '',
                        $d['TransPkgPrice1'] ?? '',
                        $d['Value'] ?? '',
                        $d['DetailTax'] ?? '',
                    ])->all(),
                );
            }
        }

        $this->warn('Read-only source inspection only.');

        return 0;
    },
)->purpose('Inspect specific legacy Foodex sales/return headers and their detail rows.');


Artisan::command(
    'foodex:legacy-sales-history-verify {--json : Output machine-readable JSON}',
    function (): int {
        $latestRun = DB::table('legacy_import_runs')
            ->where('source_system', 'foodex_sqlserver')
            ->where('phase', 'sales-history')
            ->orderByDesc('started_at')
            ->first(['id', 'status', 'started_at', 'finished_at', 'summary', 'error']);

        if ($latestRun === null) {
            $this->error('No sales-history import run exists.');

            return 1;
        }

        $summary = $latestRun->summary === null
            ? []
            : (json_decode((string) $latestRun->summary, true) ?: []);
        $planned = (array) ($summary['planned'] ?? []);

        $expectedOrders = (int) ($planned['sales_headers_materialized'] ?? 0);
        $expectedItems = (int) ($planned['sales_details'] ?? 0);
        $expectedPayments = (int) ($planned['payments'] ?? 0);
        $expectedReturns = (int) ($planned['return_ledger_entries'] ?? 0);
        $expectedSkipped = (int) ($planned['zero_effect_sales_headers_skipped'] ?? 0);

        $counts = [
            'orders' => (int) DB::table('orders')->count(),
            'order_items' => (int) DB::table('order_items')->count(),
            'order_status_history' => (int) DB::table('order_status_history')->count(),
            'invoices' => (int) DB::table('invoices')->count(),
            'invoice_items' => (int) DB::table('invoice_items')->count(),
            'payments' => (int) DB::table('payments')->count(),
            'return_ledger_entries' => (int) DB::table('customer_account_ledger_entries')
                ->where('source', 'legacy_foodex_import')
                ->where('entry_type', 'return')
                ->count(),
        ];

        $mappingCounts = [
            'orders' => (int) DB::table('legacy_import_mappings')
                ->where('source_system', 'foodex_sqlserver')
                ->where('source_table', 'wh_TransHeader')
                ->where('target_table', 'orders')
                ->distinct()
                ->count('target_id'),
            'invoices' => (int) DB::table('legacy_import_mappings')
                ->where('source_system', 'foodex_sqlserver')
                ->where('source_table', 'wh_TransHeader')
                ->where('target_table', 'invoices')
                ->distinct()
                ->count('target_id'),
            'returns' => (int) DB::table('legacy_import_mappings')
                ->where('source_system', 'foodex_sqlserver')
                ->where('source_table', 'wh_TransHeader')
                ->where('target_table', 'customer_account_ledger_entries')
                ->distinct()
                ->count('target_id'),
        ];

        $skipped = (int) DB::table('legacy_import_quarantine')
            ->where('source_system', 'foodex_sqlserver')
            ->where('source_table', 'wh_TransHeader')
            ->where('reason', 'zero_effect_empty_sales_header_skipped')
            ->whereNotNull('resolved_at')
            ->count();

        $orphanOrderMappings = (int) DB::table('legacy_import_mappings as m')
            ->leftJoin('orders as t', 't.id', '=', 'm.target_id')
            ->where('m.source_system', 'foodex_sqlserver')
            ->where('m.source_table', 'wh_TransHeader')
            ->where('m.target_table', 'orders')
            ->whereNull('t.id')
            ->count();

        $orphanInvoiceMappings = (int) DB::table('legacy_import_mappings as m')
            ->leftJoin('invoices as t', 't.id', '=', 'm.target_id')
            ->where('m.source_system', 'foodex_sqlserver')
            ->where('m.source_table', 'wh_TransHeader')
            ->where('m.target_table', 'invoices')
            ->whereNull('t.id')
            ->count();

        $orphanReturnMappings = (int) DB::table('legacy_import_mappings as m')
            ->leftJoin('customer_account_ledger_entries as t', 't.id', '=', 'm.target_id')
            ->where('m.source_system', 'foodex_sqlserver')
            ->where('m.source_table', 'wh_TransHeader')
            ->where('m.target_table', 'customer_account_ledger_entries')
            ->whereNull('t.id')
            ->count();

        $latestStockRun = DB::table('legacy_import_runs')
            ->where('source_system', 'foodex_sqlserver')
            ->where('phase', 'stock')
            ->where('status', 'completed')
            ->orderByDesc('started_at')
            ->first(['summary']);
        $stockSummary = $latestStockRun?->summary === null
            ? []
            : (json_decode((string) $latestStockRun->summary, true) ?: []);
        $expectedInventory = (int) data_get($stockSummary, 'inventories.target_total', 0);
        $currentInventory = (int) DB::table('inventories')->count();
        $currentNegativeInventory = (int) DB::table('inventories')->where('quantity', '<', 0)->count();

        $healthy = (string) $latestRun->status === 'completed'
            && $counts['orders'] === $expectedOrders
            && $counts['invoices'] === $expectedOrders
            && $counts['order_status_history'] === $expectedOrders
            && $counts['order_items'] === $expectedItems
            && $counts['invoice_items'] === $expectedItems
            && $counts['payments'] === $expectedPayments
            && $counts['return_ledger_entries'] === $expectedReturns
            && $mappingCounts['orders'] === $expectedOrders
            && $mappingCounts['invoices'] === $expectedOrders
            && $mappingCounts['returns'] === $expectedReturns
            && $skipped === $expectedSkipped
            && $orphanOrderMappings === 0
            && $orphanInvoiceMappings === 0
            && $orphanReturnMappings === 0
            && $currentInventory === $expectedInventory
            && $currentNegativeInventory === 0;

        $report = [
            'latest_run' => [
                'id' => (string) $latestRun->id,
                'status' => (string) $latestRun->status,
                'error' => $latestRun->error,
            ],
            'expected' => [
                'orders_and_invoices' => $expectedOrders,
                'items' => $expectedItems,
                'payments' => $expectedPayments,
                'returns' => $expectedReturns,
                'skipped_zero_effect_headers' => $expectedSkipped,
            ],
            'actual' => $counts,
            'mappings' => $mappingCounts,
            'skipped_zero_effect_headers' => $skipped,
            'orphan_mappings' => [
                'orders' => $orphanOrderMappings,
                'invoices' => $orphanInvoiceMappings,
                'returns' => $orphanReturnMappings,
            ],
            'stock_guard' => [
                'expected_inventories' => $expectedInventory,
                'current_inventories' => $currentInventory,
                'negative_inventories' => $currentNegativeInventory,
            ],
            'healthy' => $healthy,
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $healthy ? 0 : 1;
        }

        $this->info('Legacy Foodex sales-history verification');
        $this->line('Latest run: '.$report['latest_run']['id'].' | status='.$report['latest_run']['status']);

        $this->table(
            ['Metric', 'Expected', 'Actual'],
            [
                ['orders', $expectedOrders, $counts['orders']],
                ['invoices', $expectedOrders, $counts['invoices']],
                ['order_status_history', $expectedOrders, $counts['order_status_history']],
                ['order_items', $expectedItems, $counts['order_items']],
                ['invoice_items', $expectedItems, $counts['invoice_items']],
                ['payments', $expectedPayments, $counts['payments']],
                ['return_ledger_entries', $expectedReturns, $counts['return_ledger_entries']],
                ['order_mappings', $expectedOrders, $mappingCounts['orders']],
                ['invoice_mappings', $expectedOrders, $mappingCounts['invoices']],
                ['return_mappings', $expectedReturns, $mappingCounts['returns']],
                ['skipped_zero_effect_headers', $expectedSkipped, $skipped],
                ['inventories_unchanged', $expectedInventory, $currentInventory],
                ['negative_inventories', 0, $currentNegativeInventory],
                ['orphan_order_mappings', 0, $orphanOrderMappings],
                ['orphan_invoice_mappings', 0, $orphanInvoiceMappings],
                ['orphan_return_mappings', 0, $orphanReturnMappings],
            ],
        );

        if ($healthy) {
            $this->info('Sales-history verification PASSED.');

            return 0;
        }

        $this->error('Sales-history verification FAILED. Do not continue to the next migration phase.');

        return 1;
    },
)->purpose('Verify imported Foodex sales history, provenance mappings, and unchanged stock baseline.');


Artisan::command(
    'foodex:legacy-purchase-history-verify {--json : Output machine-readable JSON}',
    function (): int {
        $latestRun = DB::table('legacy_import_runs')
            ->where('source_system', 'foodex_sqlserver')
            ->where('phase', 'purchase-history')
            ->orderByDesc('started_at')
            ->first(['id', 'status', 'summary', 'error']);

        if ($latestRun === null) {
            $this->error('No purchase-history import run exists.');

            return 1;
        }

        $summary = $latestRun->summary === null
            ? []
            : (json_decode((string) $latestRun->summary, true) ?: []);
        $planned = (array) ($summary['planned'] ?? []);

        $expectedDocuments = (int) ($planned['materialized_documents'] ?? 0);
        $expectedLines = (int) ($planned['purchase_details'] ?? 0)
            + (int) ($planned['purchase_return_details'] ?? 0);
        $expectedSkipped = (int) ($planned['zero_effect_headers_skipped'] ?? 0);

        $actualDocuments = (int) DB::table('legacy_procurement_documents')->count();
        $actualLines = (int) DB::table('legacy_procurement_document_lines')->count();

        $documentMappings = (int) DB::table('legacy_import_mappings')
            ->where('source_system', 'foodex_sqlserver')
            ->where('source_table', 'wh_TransHeader')
            ->where('target_table', 'legacy_procurement_documents')
            ->distinct()
            ->count('target_id');

        $lineMappings = (int) DB::table('legacy_import_mappings')
            ->where('source_system', 'foodex_sqlserver')
            ->where('source_table', 'wh_TransDetails')
            ->where('target_table', 'legacy_procurement_document_lines')
            ->distinct()
            ->count('target_id');

        $skipped = (int) DB::table('legacy_import_quarantine')
            ->where('source_system', 'foodex_sqlserver')
            ->where('source_table', 'wh_TransHeader')
            ->where('reason', 'zero_effect_empty_procurement_header_skipped')
            ->whereNotNull('resolved_at')
            ->count();

        $orphanDocumentMappings = (int) DB::table('legacy_import_mappings as m')
            ->leftJoin('legacy_procurement_documents as t', 't.id', '=', 'm.target_id')
            ->where('m.source_system', 'foodex_sqlserver')
            ->where('m.source_table', 'wh_TransHeader')
            ->where('m.target_table', 'legacy_procurement_documents')
            ->whereNull('t.id')
            ->count();

        $orphanLineMappings = (int) DB::table('legacy_import_mappings as m')
            ->leftJoin('legacy_procurement_document_lines as t', 't.id', '=', 'm.target_id')
            ->where('m.source_system', 'foodex_sqlserver')
            ->where('m.source_table', 'wh_TransDetails')
            ->where('m.target_table', 'legacy_procurement_document_lines')
            ->whereNull('t.id')
            ->count();

        $latestStockRun = DB::table('legacy_import_runs')
            ->where('source_system', 'foodex_sqlserver')
            ->where('phase', 'stock')
            ->where('status', 'completed')
            ->orderByDesc('started_at')
            ->first(['summary']);
        $stockSummary = $latestStockRun?->summary === null
            ? []
            : (json_decode((string) $latestStockRun->summary, true) ?: []);
        $expectedInventory = (int) data_get($stockSummary, 'inventories.target_total', 0);
        $currentInventory = (int) DB::table('inventories')->count();
        $negativeInventory = (int) DB::table('inventories')->where('quantity', '<', 0)->count();

        $healthy = (string) $latestRun->status === 'completed'
            && $actualDocuments === $expectedDocuments
            && $actualLines === $expectedLines
            && $documentMappings === $expectedDocuments
            && $lineMappings === $expectedLines
            && $skipped === $expectedSkipped
            && $orphanDocumentMappings === 0
            && $orphanLineMappings === 0
            && $currentInventory === $expectedInventory
            && $negativeInventory === 0;

        $report = [
            'run_id' => (string) $latestRun->id,
            'status' => (string) $latestRun->status,
            'expected_documents' => $expectedDocuments,
            'actual_documents' => $actualDocuments,
            'expected_lines' => $expectedLines,
            'actual_lines' => $actualLines,
            'document_mappings' => $documentMappings,
            'line_mappings' => $lineMappings,
            'skipped_zero_effect_headers' => $skipped,
            'orphan_document_mappings' => $orphanDocumentMappings,
            'orphan_line_mappings' => $orphanLineMappings,
            'expected_inventories' => $expectedInventory,
            'current_inventories' => $currentInventory,
            'negative_inventories' => $negativeInventory,
            'healthy' => $healthy,
            'error' => $latestRun->error,
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $healthy ? 0 : 1;
        }

        $this->info('Legacy Foodex purchase-history verification');
        $this->line('Latest run: '.$report['run_id'].' | status='.$report['status']);
        $this->table(
            ['Metric', 'Expected', 'Actual'],
            [
                ['documents', $expectedDocuments, $actualDocuments],
                ['lines', $expectedLines, $actualLines],
                ['document_mappings', $expectedDocuments, $documentMappings],
                ['line_mappings', $expectedLines, $lineMappings],
                ['skipped_zero_effect_headers', $expectedSkipped, $skipped],
                ['inventories_unchanged', $expectedInventory, $currentInventory],
                ['negative_inventories', 0, $negativeInventory],
                ['orphan_document_mappings', 0, $orphanDocumentMappings],
                ['orphan_line_mappings', 0, $orphanLineMappings],
            ],
        );

        if ($healthy) {
            $this->info('Purchase-history verification PASSED.');

            return 0;
        }

        $this->error('Purchase-history verification FAILED. Do not continue to the next migration phase.');

        return 1;
    },
)->purpose('Verify immutable Foodex purchase/return history and prove inventory remained unchanged.');


Artisan::command(
    'foodex:legacy-operational-history-verify {--json : Output machine-readable JSON}',
    function (): int {
        $latestRun = DB::table('legacy_import_runs')
            ->where('source_system', 'foodex_sqlserver')
            ->where('phase', 'operational-history')
            ->orderByDesc('started_at')
            ->first(['id', 'status', 'summary', 'error']);

        if ($latestRun === null) {
            $this->error('No operational-history import run exists.');

            return 1;
        }

        $summary = $latestRun->summary === null
            ? []
            : (json_decode((string) $latestRun->summary, true) ?: []);
        $planned = (array) ($summary['planned'] ?? []);

        $expectedDocuments = (int) ($planned['materialized_documents'] ?? 0);
        $expectedLines = (int) ($planned['operational_details'] ?? 0);

        $actualDocuments = (int) DB::table('legacy_operational_documents')->count();
        $actualLines = (int) DB::table('legacy_operational_document_lines')->count();

        $documentMappings = (int) DB::table('legacy_import_mappings')
            ->where('source_system', 'foodex_sqlserver')
            ->where('source_table', 'wh_TransHeader')
            ->where('target_table', 'legacy_operational_documents')
            ->distinct()
            ->count('target_id');

        $lineMappings = (int) DB::table('legacy_import_mappings')
            ->where('source_system', 'foodex_sqlserver')
            ->where('source_table', 'wh_TransDetails')
            ->where('target_table', 'legacy_operational_document_lines')
            ->distinct()
            ->count('target_id');

        $orphanDocumentMappings = (int) DB::table('legacy_import_mappings as m')
            ->leftJoin('legacy_operational_documents as t', 't.id', '=', 'm.target_id')
            ->where('m.source_system', 'foodex_sqlserver')
            ->where('m.source_table', 'wh_TransHeader')
            ->where('m.target_table', 'legacy_operational_documents')
            ->whereNull('t.id')
            ->count();

        $orphanLineMappings = (int) DB::table('legacy_import_mappings as m')
            ->leftJoin('legacy_operational_document_lines as t', 't.id', '=', 'm.target_id')
            ->where('m.source_system', 'foodex_sqlserver')
            ->where('m.source_table', 'wh_TransDetails')
            ->where('m.target_table', 'legacy_operational_document_lines')
            ->whereNull('t.id')
            ->count();

        $latestStockRun = DB::table('legacy_import_runs')
            ->where('source_system', 'foodex_sqlserver')
            ->where('phase', 'stock')
            ->where('status', 'completed')
            ->orderByDesc('started_at')
            ->first(['summary']);
        $stockSummary = $latestStockRun?->summary === null
            ? []
            : (json_decode((string) $latestStockRun->summary, true) ?: []);

        $expectedInventory = (int) data_get($stockSummary, 'inventories.target_total', 0);
        $currentInventory = (int) DB::table('inventories')->count();
        $negativeInventory = (int) DB::table('inventories')->where('quantity', '<', 0)->count();
        $stockMovementTotal = (int) DB::table('stock_movements')->count();
        $baselineMovements = (int) DB::table('stock_movements')
            ->where('type', 'legacy_opening_balance')
            ->where('reference_type', 'legacy_foodex_stock_baseline')
            ->count();
        $nonBaselineMovements = $stockMovementTotal - $baselineMovements;

        $healthy = (string) $latestRun->status === 'completed'
            && $actualDocuments === $expectedDocuments
            && $actualLines === $expectedLines
            && $documentMappings === $expectedDocuments
            && $lineMappings === $expectedLines
            && $orphanDocumentMappings === 0
            && $orphanLineMappings === 0
            && $currentInventory === $expectedInventory
            && $negativeInventory === 0
            && $nonBaselineMovements === 0;

        $report = [
            'run_id' => (string) $latestRun->id,
            'status' => (string) $latestRun->status,
            'expected_documents' => $expectedDocuments,
            'actual_documents' => $actualDocuments,
            'expected_lines' => $expectedLines,
            'actual_lines' => $actualLines,
            'document_mappings' => $documentMappings,
            'line_mappings' => $lineMappings,
            'orphan_document_mappings' => $orphanDocumentMappings,
            'orphan_line_mappings' => $orphanLineMappings,
            'expected_inventories' => $expectedInventory,
            'current_inventories' => $currentInventory,
            'negative_inventories' => $negativeInventory,
            'stock_movements_total' => $stockMovementTotal,
            'baseline_stock_movements' => $baselineMovements,
            'non_baseline_stock_movements' => $nonBaselineMovements,
            'healthy' => $healthy,
            'error' => $latestRun->error,
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $healthy ? 0 : 1;
        }

        $this->info('Legacy Foodex operational-history verification');
        $this->line('Latest run: '.$report['run_id'].' | status='.$report['status']);
        $this->table(
            ['Metric', 'Expected', 'Actual'],
            [
                ['documents', $expectedDocuments, $actualDocuments],
                ['lines', $expectedLines, $actualLines],
                ['document_mappings', $expectedDocuments, $documentMappings],
                ['line_mappings', $expectedLines, $lineMappings],
                ['inventories_unchanged', $expectedInventory, $currentInventory],
                ['negative_inventories', 0, $negativeInventory],
                ['non_baseline_stock_movements', 0, $nonBaselineMovements],
                ['orphan_document_mappings', 0, $orphanDocumentMappings],
                ['orphan_line_mappings', 0, $orphanLineMappings],
            ],
        );

        if ($healthy) {
            $this->info('Operational-history verification PASSED.');

            return 0;
        }

        $this->error('Operational-history verification FAILED. Do not continue to finance/final reconciliation.');

        return 1;
    },
)->purpose('Verify immutable Foodex operational history and prove no historical stock replay occurred.');


Artisan::command(
    'foodex:legacy-finance-diagnostics {--json : Output machine-readable JSON}',
    function (LegacyFoodexImportService $importer): int {
        $progress = function (string $stage, string $state, ?float $elapsed): void {
            if ((bool) $this->option('json')) {
                return;
            }

            if ($state === 'start') {
                $this->line('>> '.$stage.' ...');

                return;
            }

            $this->line('   '.$stage.' DONE in '.number_format((float) $elapsed, 3).'s');
        };

        try {
            $report = $importer->financeDiagnostics($progress);
        } catch (Throwable $exception) {
            $this->error('Legacy Foodex finance diagnostics failed: '.$exception->getMessage());

            return 1;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $this->info('Legacy Foodex finance diagnostics (READ ONLY)');

        $this->table(
            ['Source table', 'Rows'],
            collect($report['tables'])->map(
                static fn ($count, $table): array => [(string) $table, (int) $count],
            )->values()->all(),
        );

        $this->line(
            'GL headers: rows='.$report['gl_header']['rows'].
            ' | posted='.$report['gl_header']['posted'].
            ' | unposted='.$report['gl_header']['unposted'].
            ' | range='.($report['gl_header']['first_date'] ?? 'n/a').
            ' -> '.($report['gl_header']['last_date'] ?? 'n/a')
        );

        $this->newLine();
        $this->info('GL detail Dr/Cr flag candidates');
        $this->table(
            ['Flag', 'Rows', 'Active rows', 'Active value'],
            collect($report['gl_flags'])->map(static fn (array $row): array => [
                $row['flag'] ?? 'NULL',
                $row['rows'],
                $row['active_rows'],
                $row['active_value'],
            ])->all(),
        );

        $this->newLine();
        $this->info('Posted GL balance test');
        $this->table(
            ['Metric', 'Value'],
            collect([
                'posted_headers_with_active_details' => $report['gl_balance']['posted_headers_with_active_details'],
                'balanced_if_flags_1_2_are_dr_cr' => $report['gl_balance']['balanced_if_flags_1_2_are_dr_cr'],
                'unbalanced_if_flags_1_2_are_dr_cr' => $report['gl_balance']['unbalanced_if_flags_1_2_are_dr_cr'],
                'other_flag_rows' => $report['gl_balance']['other_flag_rows'],
                'absolute_difference_total' => $report['gl_balance']['absolute_difference_total'],
            ])->map(static fn ($value, $metric): array => [(string) $metric, $value])->values()->all(),
        );

        if (($report['gl_balance']['unbalanced_samples'] ?? []) !== []) {
            $this->warn('GL balance anomaly samples');
            $this->table(
                ['Header', 'Flag 1 total', 'Flag 2 total', 'Difference', 'Other flag rows'],
                collect($report['gl_balance']['unbalanced_samples'])->map(static fn (array $row): array => [
                    $row['header_id'],
                    $row['flag_1_total'],
                    $row['flag_2_total'],
                    $row['difference'],
                    $row['other_flag_rows'],
                ])->all(),
            );
        }

        $this->newLine();
        $this->info('Party / GL account links');
        $this->table(
            ['Metric', 'Value'],
            collect($report['party_account_links'])->filter(
                static fn ($value): bool => ! is_array($value),
            )->map(static fn ($value, $metric): array => [(string) $metric, $value])->values()->all(),
        );

        $this->newLine();
        $this->info('Legacy receipts/payments by PrTransType');
        $this->table(
            ['Type', 'Rows', 'Value', 'Customer', 'Supplier', 'Account', 'GL posted', 'Opening'],
            collect($report['pr_trans_types'])->map(static fn (array $row): array => [
                $row['type'],
                $row['rows'],
                $row['trans_value'],
                $row['customer_rows'],
                $row['supplier_rows'],
                $row['account_rows'],
                $row['gl_posted_rows'],
                $row['opening_rows'],
            ])->all(),
        );

        $this->newLine();
        $this->info('PrTrans type lookup (source authority)');
        $this->table(
            ['Type', 'Arabic', 'English', 'Status'],
            collect($report['pr_semantics']['type_lookup'] ?? [])->map(static fn (array $row): array => [
                $row['type'],
                $row['name_ar'],
                $row['name_en'],
                $row['status'],
            ])->all(),
        );

        $this->newLine();
        $this->info('PrTrans payment shape');
        $this->table(
            ['Type', 'PaymentType', 'PayType', 'Rows', 'Value'],
            collect($report['pr_semantics']['payment_shapes'] ?? [])->map(static fn (array $row): array => [
                $row['type'],
                $row['payment_type'] ?? 'NULL',
                $row['pay_type'] ?? 'NULL',
                $row['rows'],
                $row['value'],
            ])->all(),
        );

        $this->newLine();
        $this->info('PrTrans direct invoice links');
        $this->table(
            ['PrType', 'Invoice TransType', 'Rows', 'Value'],
            collect($report['pr_semantics']['direct_invoice_links'] ?? [])->map(static fn (array $row): array => [
                $row['pr_type'],
                $row['invoice_trans_type'] ?? 'NULL',
                $row['rows'],
                $row['value'],
            ])->all(),
        );

        $this->newLine();
        $this->info('PrTrans detail allocation links');
        $this->table(
            ['PrType', 'Linked TransType', 'Rows', 'Value'],
            collect($report['pr_semantics']['detail_links'] ?? [])->map(static fn (array $row): array => [
                $row['pr_type'],
                $row['linked_trans_type'] ?? 'NULL',
                $row['rows'],
                $row['value'],
            ])->all(),
        );

        $detailIntegrity = $report['pr_semantics']['detail_integrity'] ?? [];
        $this->line(
            'PrTransDtl integrity: rows='.($detailIntegrity['rows'] ?? 0).
            ' | orphan_parent_rows='.($detailIntegrity['orphan_parent_rows'] ?? 0).
            ' | linked_transaction_rows='.($detailIntegrity['linked_transaction_rows'] ?? 0).
            ' | value='.($detailIntegrity['value'] ?? 0)
        );

        $this->newLine();
        $this->info('Bank in/out transaction types');
        $this->table(
            ['Type', 'Rows', 'Value', 'Customer', 'Supplier', 'Account', 'GL posted'],
            collect($report['pr_semantics']['bank_types'] ?? [])->map(static fn (array $row): array => [
                $row['type'],
                $row['rows'],
                $row['value'],
                $row['customer_rows'],
                $row['supplier_rows'],
                $row['account_rows'],
                $row['gl_posted_rows'],
            ])->all(),
        );

        foreach (($report['pr_semantics']['samples'] ?? []) as $type => $samples) {
            $this->newLine();
            $this->info('PrTrans sample type '.$type);
            $this->table(
                ['ID', 'Receipt', 'Date', 'Value', 'Customer', 'Supplier', 'Account', 'InvHdr', 'PaymentType', 'PayType', 'GL', 'CashBox', 'Bank', 'Notes'],
                collect($samples)->map(static fn (array $row): array => [
                    $row['id'],
                    $row['receipt'],
                    $row['date'],
                    $row['value'],
                    $row['customer_id'],
                    $row['supplier_id'],
                    $row['account_id'],
                    $row['invoice_header_id'],
                    $row['payment_type'] ?? 'NULL',
                    $row['pay_type'] ?? 'NULL',
                    $row['gl_posted'],
                    $row['cashbox_no'],
                    $row['bank_id'],
                    $row['notes'],
                ])->all(),
            );
        }

        $this->line(
            'Commercial GL links: any='.$report['commercial_gl_links']['posted_commercial_headers_with_any_gl_link'].
            ' | primary='.$report['commercial_gl_links']['with_primary_gl_link'].
            ' | secondary='.$report['commercial_gl_links']['with_secondary_gl_link']
        );

        $this->line(
            'GL account integrity: used='.$report['account_integrity']['used_active_accounts'].
            ' | missing='.$report['account_integrity']['missing_account_ids']
        );

        $this->warn('Diagnostics only. No source or target rows were changed.');

        return 0;
    },
)->purpose('Inspect legacy Foodex GL and receipt/payment authority before finance cutover; read-only only.');
