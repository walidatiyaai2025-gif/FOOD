<?php

use App\Services\LegacyDatabaseOneShotService;
use App\Services\LegacyFinanceAssetMaterializerService;
use App\Services\LegacyFoodexImportService;
use App\Services\LegacyImportRehearsalResetService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\Services\LegacyFullArchiveService;
use App\Services\LegacyCanonicalCoverageService;
use App\Services\LegacyBusinessImpactMaterializerService;
use App\Services\LegacyInvoiceSourceMaterializerService;
use App\Services\LegacyStockReconciliationService;
use App\Services\LegacyVanInventoryHistoryMaterializerService;
use App\Services\LegacySupplierBrandMaterializerService;
use App\Services\LegacyFinanceAccountMaterializerService;
use App\Services\LegacyFinanceCostCenterMaterializerService;
use App\Services\LegacyFinanceAssetProfilerService;

Artisan::command(
    'foodex:legacy-rehearsal-reset {--apply : Delete only the approved catalog/sales/stock scope} {--confirm= : Required exact confirmation when applying} {--json : Output machine-readable JSON}',
    function (LegacyImportRehearsalResetService $reset): int {
        try {
            if (! (bool) $this->option('apply')) {
                $report = $reset->preview();

                if ((bool) $this->option('json')) {
                    $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                    return $report['ready'] ? 0 : 1;
                }

                $this->info('Legacy import rehearsal scoped reset preview');
                $this->table(
                    ['Table', 'Rows'],
                    collect($report['counts'])
                        ->map(fn ($count, $table): array => [(string) $table, (int) $count])
                        ->values()
                        ->all(),
                );

                $this->line('Rows inside approved reset scope: '.$report['total']);
                $this->line('Legacy mapping rows to remove: '.$report['legacy_mapping_rows_to_remove']);
                $this->line('Protected tables are count-guarded and are never reset.');

                if ($report['blocked_references'] !== []) {
                    $this->error(
                        'Blocked by unapproved dependent tables: '.
                        json_encode($report['blocked_references'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    );

                    return 1;
                }

                $this->info('Scoped reset preview PASSED.');
                $this->warn(
                    'Apply only with --apply --confirm="'.LegacyImportRehearsalResetService::CONFIRMATION.'"'
                );

                return 0;
            }

            $result = $reset->execute((string) $this->option('confirm'));

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                return 0;
            }

            $this->info('Legacy import rehearsal scoped reset completed.');
            $this->line('Removed rows: '.$result['removed']);
            $this->line('Legacy mapping rows removed: '.($result['legacy_mapping_rows_removed'] ?? 0));
            $this->line('Mapped customer ledger rows removed: '.($result['mapped_customer_ledger_rows_removed'] ?? 0));
            $this->info('Protected settings/users/roles/customers/stores/warehouses/configuration counts remained unchanged.');

            return 0;
        } catch (Throwable $exception) {
            $this->error('Legacy rehearsal reset failed: '.$exception->getMessage());

            return 1;
        }
    },
)->purpose('Reset only the approved catalog, sales and inventory scope before a legacy Foodex import rehearsal.');

Artisan::command(
    'foodex:legacy-rehearsal-reset-vans {--apply : Delete only vans and van operational history} {--confirm= : Required exact confirmation when applying} {--json : Output machine-readable JSON}',
    function (LegacyImportRehearsalResetService $reset): int {
        try {
            if (! (bool) $this->option('apply')) {
                $report = $reset->previewVanScope();

                if ((bool) $this->option('json')) {
                    $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                    return $report['ready'] ? 0 : 1;
                }

                $this->info('Legacy import van scoped reset preview');
                $this->table(
                    ['Table', 'Rows'],
                    collect($report['counts'])
                        ->map(fn ($count, $table): array => [(string) $table, (int) $count])
                        ->values()
                        ->all(),
                );
                $this->line('Fleet location rows to remove: '.$report['fleet_location_rows']);
                $this->line('Driver current-location assignment links to clear: '.$report['driver_location_assignment_links_to_clear']);

                if (! $report['ready']) {
                    $this->error(
                        'Blocked by unapproved dependent tables: '.
                        json_encode($report['blocked_references'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    );

                    return 1;
                }

                $this->info('Van scoped reset preview PASSED.');

                return 0;
            }

            $result = $reset->executeVanScope((string) $this->option('confirm'));

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                return 0;
            }

            $this->info('Van scoped reset completed safely.');
            $this->info('Users, drivers, warehouses, stores and configuration were preserved.');

            return 0;
        } catch (Throwable $exception) {
            $this->error('Legacy van rehearsal reset failed: '.$exception->getMessage());

            return 1;
        }
    },
)->purpose('Reset vans and their operational history without touching users, drivers, stores or warehouses.');

Artisan::command(
    'foodex:legacy-one-shot {--apply : Snapshot and migrate the full legacy database} {--reset-approved-scope : Reset only the approved legacy-owned target scope before semantic import} {--confirm= : Required exact reset confirmation when --reset-approved-scope is used} {--json : Output machine-readable JSON}',
    function (LegacyDatabaseOneShotService $oneShot): int {
        if ((bool) $this->option('apply')) {
            $migrationExit = Artisan::call('migrate', ['--force' => true]);
            if ($migrationExit !== 0) {
                $this->error('Additive migrations failed before full legacy one-shot.');
                $this->line(Artisan::output());

                return 1;
            }
        }

        $progress = function (string $stage, string $state): void {
            if ((bool) $this->option('json')) {
                return;
            }

            $state === 'start'
                ? $this->line('>> '.$stage.' ...')
                : $this->line('   '.$stage.' DONE');
        };

        try {
            $report = $oneShot->run(
                (bool) $this->option('apply'),
                (bool) $this->option('reset-approved-scope'),
                $this->option('confirm') === null ? null : (string) $this->option('confirm'),
                $progress,
            );
        } catch (Throwable $exception) {
            $this->error('Full legacy database one-shot failed: '.$exception->getMessage());

            return 1;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));

            return 0;
        }

        if (! (bool) $this->option('apply')) {
            $inventory = $report['full_source_inventory'];
            $this->info('Full legacy database one-shot dry-run completed.');
            $this->line(
                'Source tables='.$inventory['table_count'].
                ' | source rows='.$inventory['source_row_count']
            );
            if ((bool) $this->option('reset-approved-scope')) {
                $this->line('Approved target reset was previewed only; no target rows were changed.');
            }
            $this->warn('No source or target business rows were changed.');

            return 0;
        }

        $this->info('Full legacy database one-shot completed.');
        $this->line(
            'Snapshot='.$report['snapshot_id'].
            ' | source tables='.$report['source_table_count'].
            ' | source rows='.$report['source_row_count'].
            ' | archived rows='.$report['archived_row_count']
        );
        $this->line(
            'All source data preserved='.($report['all_source_data_preserved'] ? 'yes' : 'no').
            ' | semantic verification='.($report['semantic_verifications_healthy'] ? 'healthy' : 'failed')
        );
        if ($report['approved_target_reset'] !== null) {
            $this->line('Approved target reset=yes');
        }
        $this->line(
            'Semantic mapped tables='.count($report['coverage']['tables_with_semantic_mappings']).
            ' | raw-only preserved tables='.count($report['coverage']['raw_only_source_tables'])
        );
        $this->line('Status='.$report['status']);

        if ($report['coverage']['raw_only_source_tables'] !== []) {
            $this->warn(
                'Some source tables have no native semantic mapping yet; every row is preserved in the lossless raw archive.'
            );
        }
        if (! $report['native_fixed_asset_ready']) {
            $this->warn(
                'Fixed-asset native accounting remains fail-closed because the legacy source does not contain authoritative account mappings.'
            );
        }

        return 0;
    },
)->purpose('Snapshot every legacy Foodex base table and row, optionally reset the approved target scope, import all supported semantics including verified stock, and verify complete source preservation in one command.');

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
        $this->line(
            'Protected reset ready: '.(($report['target']['legacy_protected_reset_ready'] ?? false) ? 'yes' : 'no')
        );
        $this->line(
            'Protected upgrade ready: '.(($report['target']['legacy_upgrade_ready'] ?? false) ? 'yes' : 'no')
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
        if (! in_array($phase, ['masters', 'extended-masters', 'stock', 'sales-history', 'purchase-history', 'operational-history', 'finance-history'], true)) {
            $this->error('Supported phases: masters, extended-masters, stock, sales-history, purchase-history, operational-history, finance-history.');

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
                'finance-history' => $importer->importFinanceHistory((bool) $this->option('apply'), $progress),
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

            if ($phase === 'finance-history') {
                $this->newLine();
                $this->info('Finance classification');
                $this->table(
                    ['Classification', 'Rows', 'Value'],
                    collect($result['classification_counts'] ?? [])->map(
                        static fn ($count, $classification): array => [
                            (string) $classification,
                            (int) $count,
                            round((float) (($result['classification_values'][$classification] ?? 0)), 3),
                        ],
                    )->values()->all(),
                );

                $samples = $result['samples'] ?? [];
                foreach ([
                    'missing_direct_payments',
                    'direct_payment_amount_mismatches',
                    'missing_return_links',
                    'customer_identity_mismatches',
                ] as $sampleKey) {
                    if (($samples[$sampleKey] ?? []) !== []) {
                        $this->newLine();
                        $label = $sampleKey === 'direct_payment_amount_mismatches'
                            ? 'invoice_receipt_variance_samples_nonblocking'
                            : $sampleKey;
                        $this->warn($label.': '.json_encode(
                            $samples[$sampleKey],
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                        ));
                    }
                }

                if (($samples['zero_amount_rows_nonblocking'] ?? []) !== []) {
                    $this->line(
                        'Zero-amount finance rows preserved without ledger effect: '.
                        json_encode($samples['zero_amount_rows_nonblocking'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    );
                }

                if (($samples['negative_amounts'] ?? []) !== []) {
                    $this->warn(
                        'Negative finance amounts (blocking): '.
                        json_encode($samples['negative_amounts'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    );
                }

                if (($samples['unsupported_types'] ?? []) !== []) {
                    $this->warn(
                        'Unsupported active PrTrans types: '.
                        json_encode($samples['unsupported_types'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
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

        $latestSummary = $latestRun?->summary === null
            ? []
            : (json_decode((string) $latestRun->summary, true) ?: []);
        $planned = (array) ($latestSummary['planned'] ?? []);
        $expectedPositivePositions = array_key_exists('positive_positions', $planned)
            ? (int) $planned['positive_positions']
            : null;

        $report = [
            'latest_run' => $latestRun === null ? null : [
                'id' => (string) $latestRun->id,
                'status' => (string) $latestRun->status,
                'started_at' => $latestRun->started_at,
                'finished_at' => $latestRun->finished_at,
                'summary' => $latestSummary === [] ? null : $latestSummary,
                'error' => $latestRun->error,
            ],
            'expected' => [
                'positive_positions' => $expectedPositivePositions,
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
                && $expectedPositivePositions !== null
                && $inventory['negative'] === 0
                && $inventory['reserved_nonzero'] === 0
                && $inventory['total'] === $expectedPositivePositions
                && $inventory['positive'] === $expectedPositivePositions
                && $baselineMovements === $expectedPositivePositions
                && $mappings === $expectedPositivePositions
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
                ['expected.positive_positions', $expectedPositivePositions ?? 'n/a'],
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
    'foodex:legacy-finance-history-verify {--json : Output machine-readable JSON}',
    function (): int {
        $latestRun = DB::table('legacy_import_runs')
            ->where('source_system', 'foodex_sqlserver')
            ->where('phase', 'finance-history')
            ->orderByDesc('started_at')
            ->first(['id', 'status', 'summary', 'error']);

        if ($latestRun === null) {
            $this->error('No finance-history import run exists.');

            return 1;
        }

        $summary = $latestRun->summary === null
            ? []
            : (json_decode((string) $latestRun->summary, true) ?: []);
        $planned = (array) ($summary['planned'] ?? []);

        $expectedFinance = (int) ($planned['active_pr_transactions'] ?? 0);
        $expectedBank = (int) ($planned['active_bank_transactions'] ?? 0);
        $expectedLedger = (int) ($planned['new_customer_ledger_entries'] ?? 0);
        $expectedLedgerCredits = round((float) ($planned['new_customer_ledger_credit_value'] ?? 0), 3);
        $expectedLedgerDebits = round((float) ($planned['new_customer_ledger_debit_value'] ?? 0), 3);
        $expectedPaymentLinks = (int) ($planned['existing_payment_links'] ?? $planned['invoice_receipt_duplicates'] ?? 0);

        $actualFinance = (int) DB::table('legacy_finance_transactions')->count();
        $actualBank = (int) DB::table('legacy_bank_transactions')->count();
        $actualLedger = (int) DB::table('customer_account_ledger_entries')
            ->where('source', 'legacy_foodex_finance')
            ->count();
        $ledgerTotals = DB::table('customer_account_ledger_entries')
            ->where('source', 'legacy_foodex_finance')
            ->selectRaw('COALESCE(SUM(credit), 0) as credits')
            ->selectRaw('COALESCE(SUM(debit), 0) as debits')
            ->first();
        $actualLedgerCredits = round((float) ($ledgerTotals->credits ?? 0), 3);
        $actualLedgerDebits = round((float) ($ledgerTotals->debits ?? 0), 3);

        $financeMappings = (int) DB::table('legacy_import_mappings')
            ->where('source_system', 'foodex_sqlserver')
            ->where('source_table', 'wh_PrTrans')
            ->where('target_table', 'legacy_finance_transactions')
            ->count();

        $bankMappings = (int) DB::table('legacy_import_mappings')
            ->where('source_system', 'foodex_sqlserver')
            ->where('source_table', 'wh_InOutBank')
            ->where('target_table', 'legacy_bank_transactions')
            ->count();

        $ledgerMappings = (int) DB::table('legacy_import_mappings')
            ->where('source_system', 'foodex_sqlserver')
            ->where('source_table', 'wh_PrTrans')
            ->where('target_table', 'customer_account_ledger_entries')
            ->count();

        $paymentMappings = (int) DB::table('legacy_import_mappings')
            ->where('source_system', 'foodex_sqlserver')
            ->where('source_table', 'wh_PrTrans')
            ->where('target_table', 'payments')
            ->count();

        $orphanFinanceMappings = (int) DB::table('legacy_import_mappings as m')
            ->leftJoin('legacy_finance_transactions as t', 't.id', '=', 'm.target_id')
            ->where('m.source_system', 'foodex_sqlserver')
            ->where('m.source_table', 'wh_PrTrans')
            ->where('m.target_table', 'legacy_finance_transactions')
            ->whereNull('t.id')
            ->count();

        $orphanBankMappings = (int) DB::table('legacy_import_mappings as m')
            ->leftJoin('legacy_bank_transactions as t', 't.id', '=', 'm.target_id')
            ->where('m.source_system', 'foodex_sqlserver')
            ->where('m.source_table', 'wh_InOutBank')
            ->where('m.target_table', 'legacy_bank_transactions')
            ->whereNull('t.id')
            ->count();

        $orphanLedgerMappings = (int) DB::table('legacy_import_mappings as m')
            ->leftJoin('customer_account_ledger_entries as t', 't.id', '=', 'm.target_id')
            ->where('m.source_system', 'foodex_sqlserver')
            ->where('m.source_table', 'wh_PrTrans')
            ->where('m.target_table', 'customer_account_ledger_entries')
            ->whereNull('t.id')
            ->count();

        $orphanPaymentMappings = (int) DB::table('legacy_import_mappings as m')
            ->leftJoin('payments as t', 't.id', '=', 'm.target_id')
            ->where('m.source_system', 'foodex_sqlserver')
            ->where('m.source_table', 'wh_PrTrans')
            ->where('m.target_table', 'payments')
            ->whereNull('t.id')
            ->count();

        $latestSalesRun = DB::table('legacy_import_runs')
            ->where('source_system', 'foodex_sqlserver')
            ->where('phase', 'sales-history')
            ->where('status', 'completed')
            ->orderByDesc('started_at')
            ->first(['summary']);
        $salesSummary = $latestSalesRun?->summary === null
            ? []
            : (json_decode((string) $latestSalesRun->summary, true) ?: []);
        $expectedPayments = (int) data_get($salesSummary, 'payments.created', 0);
        $actualPayments = (int) DB::table('payments')->count();

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
            && $actualFinance === $expectedFinance
            && $actualBank === $expectedBank
            && $actualLedger === $expectedLedger
            && abs($actualLedgerCredits - $expectedLedgerCredits) <= 0.010
            && abs($actualLedgerDebits - $expectedLedgerDebits) <= 0.010
            && $financeMappings === $expectedFinance
            && $bankMappings === $expectedBank
            && $ledgerMappings === $expectedLedger
            && $paymentMappings === $expectedPaymentLinks
            && $orphanFinanceMappings === 0
            && $orphanBankMappings === 0
            && $orphanLedgerMappings === 0
            && $orphanPaymentMappings === 0
            && $actualPayments === $expectedPayments
            && $currentInventory === $expectedInventory
            && $negativeInventory === 0;

        $report = [
            'run_id' => (string) $latestRun->id,
            'status' => (string) $latestRun->status,
            'expected_finance_history' => $expectedFinance,
            'actual_finance_history' => $actualFinance,
            'expected_bank_history' => $expectedBank,
            'actual_bank_history' => $actualBank,
            'expected_customer_ledger_entries' => $expectedLedger,
            'actual_customer_ledger_entries' => $actualLedger,
            'expected_customer_ledger_credits' => $expectedLedgerCredits,
            'actual_customer_ledger_credits' => $actualLedgerCredits,
            'expected_customer_ledger_debits' => $expectedLedgerDebits,
            'actual_customer_ledger_debits' => $actualLedgerDebits,
            'finance_mappings' => $financeMappings,
            'bank_mappings' => $bankMappings,
            'ledger_mappings' => $ledgerMappings,
            'payment_dedup_mappings' => $paymentMappings,
            'expected_payment_dedup_mappings' => $expectedPaymentLinks,
            'orphan_finance_mappings' => $orphanFinanceMappings,
            'orphan_bank_mappings' => $orphanBankMappings,
            'orphan_ledger_mappings' => $orphanLedgerMappings,
            'orphan_payment_mappings' => $orphanPaymentMappings,
            'expected_existing_payments' => $expectedPayments,
            'actual_existing_payments' => $actualPayments,
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

        $this->info('Legacy Foodex finance-history verification');
        $this->line('Latest run: '.$report['run_id'].' | status='.$report['status']);
        $this->table(
            ['Metric', 'Expected', 'Actual'],
            [
                ['finance_history', $expectedFinance, $actualFinance],
                ['bank_history', $expectedBank, $actualBank],
                ['customer_ledger_entries', $expectedLedger, $actualLedger],
                ['customer_ledger_credits', $expectedLedgerCredits, $actualLedgerCredits],
                ['customer_ledger_debits', $expectedLedgerDebits, $actualLedgerDebits],
                ['finance_mappings', $expectedFinance, $financeMappings],
                ['bank_mappings', $expectedBank, $bankMappings],
                ['ledger_mappings', $expectedLedger, $ledgerMappings],
                ['payment_dedup_mappings', $expectedPaymentLinks, $paymentMappings],
                ['existing_payments_unchanged', $expectedPayments, $actualPayments],
                ['inventories_unchanged', $expectedInventory, $currentInventory],
                ['negative_inventories', 0, $negativeInventory],
                ['orphan_finance_mappings', 0, $orphanFinanceMappings],
                ['orphan_bank_mappings', 0, $orphanBankMappings],
                ['orphan_ledger_mappings', 0, $orphanLedgerMappings],
                ['orphan_payment_mappings', 0, $orphanPaymentMappings],
            ],
        );

        if ($healthy) {
            $this->info('Finance-history verification PASSED.');

            return 0;
        }

        $this->error('Finance-history verification FAILED. Do not declare final cutover complete.');

        return 1;
    },
)->purpose('Verify Foodex finance history, customer balances, payment deduplication, and stock preservation.');

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

Artisan::command(
    'foodex:legacy-finance-assets {--apply : Materialize legacy gl_Assets into the protected asset register} {--json : Output machine-readable JSON}',
    function (LegacyFinanceAssetMaterializerService $assets): int {
        try {
            $result = $assets->materialize((bool) $this->option('apply'));
        } catch (Throwable $exception) {
            $this->error('Legacy fixed-asset materialization failed: '.$exception->getMessage());

            return 1;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        if (! (bool) $this->option('apply')) {
            $this->info('Legacy fixed-asset materialization dry-run');
            $this->line('Source rows: '.$result['source_rows'].' | Existing materialized rows: '.$result['materialized_rows']);
            $this->line(
                'Insert='.$result['planned']['insert'].
                ' | Update='.$result['planned']['update'].
                ' | Unchanged='.$result['planned']['unchanged']
            );
            $preflight = $result['native_preflight'];
            $this->line('Native ready='.$preflight['ready_rows'].' | blocked='.$preflight['blocked_rows']);
            if (! $preflight['ready_for_native_promotion']) {
                $this->warn('Native promotion remains fail-closed. Materialization is safe; native finance posting is not authorized yet.');
                $this->line('Blockers: '.json_encode($preflight['blocker_counts'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            return 0;
        }

        $this->info('Legacy fixed-asset materialization completed.');
        $this->line('Run ID: '.$result['run_id']);
        $this->line(
            'Source='.$result['verification']['source_rows'].
            ' | Materialized='.$result['verification']['materialized_rows'].
            ' | Mappings='.$result['verification']['mapping_rows']
        );
        if (! $result['native_preflight']['ready_for_native_promotion']) {
            $this->warn('Assets are preserved, but native enterprise fixed-asset promotion is intentionally blocked until authoritative finance mappings exist.');
        }

        return 0;
    },
)->purpose('Materialize every legacy Foodex fixed asset idempotently without fabricating finance accounts or journals.');

Artisan::command(
    'foodex:legacy-finance-assets-verify {--json : Output machine-readable JSON}',
    function (LegacyFinanceAssetMaterializerService $assets): int {
        try {
            $report = $assets->verify();
        } catch (Throwable $exception) {
            $this->error('Legacy fixed-asset verification failed to run: '.$exception->getMessage());

            return 1;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line(
                'Source='.$report['source_rows'].
                ' | Materialized='.$report['materialized_rows'].
                ' | Mappings='.$report['mapping_rows'].
                ' | Mapping mismatches='.$report['mapping_mismatches']
            );
            $report['healthy']
                ? $this->info('Legacy fixed-asset verification PASSED.')
                : $this->error('Legacy fixed-asset verification FAILED.');
        }

        return $report['healthy'] ? 0 : 1;
    },
)->purpose('Prove row/hash/provenance parity between dbo.gl_Assets and the FOODEX legacy fixed-asset register.');

Artisan::command(
    'foodex:legacy-finance-assets-native-preflight {--json : Output machine-readable JSON}',
    function (LegacyFinanceAssetMaterializerService $assets): int {
        try {
            $report = $assets->nativePromotionPreflight();
        } catch (Throwable $exception) {
            $this->error('Legacy fixed-asset native preflight failed to run: '.$exception->getMessage());

            return 1;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $this->line('Rows='.$report['rows'].' | Ready='.$report['ready_rows'].' | Blocked='.$report['blocked_rows']);
        if ($report['ready_for_native_promotion']) {
            $this->info('Native fixed-asset promotion prerequisites are satisfied.');
        } else {
            $this->warn('Native promotion is BLOCKED safely. No enterprise asset journal will be fabricated.');
            $this->line('Blockers: '.json_encode($report['blocker_counts'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return 0;
    },
)->purpose('Fail-closed preflight for promoting legacy assets into native Enterprise Finance fixed assets.');

Artisan::command(
    'foodex:legacy-archive-all {--apply : Archive every SQL Server user table and row} {--json : Output machine-readable JSON}',
    function (LegacyFullArchiveService $archive): int {
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
            $result = $archive->archiveAll((bool) $this->option('apply'), $progress);
        } catch (Throwable $exception) {
            $this->error('Legacy full-source archive failed: '.$exception->getMessage());

            return 1;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        if (! (bool) $this->option('apply')) {
            $this->info('Legacy full-source archive dry-run');
            $this->table(
                ['Metric', 'Count'],
                [
                    ['SQL Server user tables', $result['planned']['source_tables']],
                    ['Estimated source rows', $result['planned']['estimated_source_rows']],
                ],
            );
            $this->warn('Dry-run only. Apply archives every source row, including tables without a canonical FOODEX target.');

            return 0;
        }

        $this->info('Legacy full-source archive completed.');
        $this->table(
            ['Metric', 'Count'],
            [
                ['SQL Server user tables', $result['source_tables']],
                ['Completed tables', $result['completed_tables']],
                ['Archived rows', $result['archived_rows']],
            ],
        );

        return 0;
    },
)->purpose('Losslessly archive every SQL Server user table and row into MySQL for 100% legacy data coverage.');

Artisan::command(
    'foodex:legacy-archive-verify {--json : Output machine-readable JSON}',
    function (LegacyFullArchiveService $archive): int {
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
            $report = $archive->verify($progress);
        } catch (Throwable $exception) {
            $this->error('Legacy full-source archive verification failed: '.$exception->getMessage());

            return 1;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $report['healthy'] ? 0 : 1;
        }

        $this->info('Legacy full-source coverage verification');
        $this->table(
            ['Metric', 'Count'],
            [
                ['SQL Server user tables', $report['source_tables']],
                ['SQL Server rows', $report['source_rows']],
                ['Archived rows', $report['archived_rows']],
            ],
        );

        $failed = collect($report['tables'])->where('healthy', false)->values()->all();
        if ($failed !== []) {
            $this->error('Coverage mismatches detected.');
            $this->table(
                ['Schema', 'Table', 'Source', 'Archived', 'Status'],
                collect($failed)->map(static fn (array $row): array => [
                    $row['schema'],
                    $row['table'],
                    $row['source_rows'],
                    $row['archived_rows'],
                    $row['manifest_status'] ?? '',
                ])->all(),
            );
        }

        if ($report['healthy']) {
            $this->info('FULL SQL SERVER COVERAGE PASSED: every current source table and row is archived.');

            return 0;
        }

        $this->error('FULL SQL SERVER COVERAGE FAILED.');

        return 1;
    },
)->purpose('Fail unless every SQL Server user table and row is present in the MySQL legacy archive.');

Artisan::command(
    'foodex:legacy-finance-accounts {--apply : Materialize the legacy GL chart of accounts into native enterprise finance accounts} {--json : Output JSON}',
    function (LegacyFinanceAccountMaterializerService $materializer): int {
        try {
            $result = $materializer->materialize((bool) $this->option('apply'));
        } catch (Throwable $exception) {
            $this->error('Legacy finance account materialization failed: '.$exception->getMessage());
            return 1;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return ($result['healthy'] ?? ($result['profile']['ready'] ?? false)) ? 0 : 1;
        }

        $profile = $result['profile'];
        $this->info((bool) $this->option('apply')
            ? 'Legacy native chart-of-accounts materialization completed.'
            : 'Legacy finance chart-of-accounts dry-run');

        $this->table(
            ['Metric', 'Value'],
            [
                ['Source table', $profile['source_table']],
                ['Source rows', $profile['source_rows']],
                ['ID column', $profile['resolved_columns']['id'] ?? '—'],
                ['Code column', $profile['resolved_columns']['code'] ?? '—'],
                ['Name column', $profile['resolved_columns']['name'] ?? '—'],
                ['Parent column', $profile['resolved_columns']['parent'] ?? '—'],
                ['Type column', $profile['resolved_columns']['type'] ?? '—'],
                ['Normal-balance column', $profile['resolved_columns']['normal_balance'] ?? '—'],
                ['Status column', $profile['resolved_columns']['status'] ?? '—'],
                ['Type lookup table', $profile['type_lookup']['table'] ?? '—'],
                ['Unclassified type rows', $profile['classification']['unclassified_type_rows']],
                ['Missing code rows', $profile['classification']['missing_code_rows']],
                ['Missing name rows', $profile['classification']['missing_name_rows']],
                ['Duplicate source IDs', $profile['classification']['duplicate_source_ids']],
                ['Profile ready', $profile['ready'] ? 'YES' : 'NO'],
            ],
        );

        if (! (bool) $this->option('apply')) {
            $this->line('');
            $this->info('Source gl_Accounts columns');
            foreach (array_chunk($profile['columns'], 6) as $chunk) {
                $this->line('  '.implode(' | ', $chunk));
            }

            if ($profile['type_lookup_values'] !== []) {
                $this->line('');
                $this->info('gl_AccountTypes lookup values');
                $this->table(
                    ['ID', 'Label'],
                    collect($profile['type_lookup_values'])->map(static fn (array $row): array => [
                        $row['id'],
                        $row['label'],
                    ])->all(),
                );
            }

            if ($profile['final_list_values'] !== []) {
                $this->line('');
                $this->info('AccountFinalListType values');
                $this->table(
                    ['Value', 'Rows'],
                    collect($profile['final_list_values'])->map(static fn (array $row): array => [
                        is_scalar($row['value']) ? (string) $row['value'] : json_encode($row['value']),
                        $row['count'],
                    ])->all(),
                );
            }

            if ($profile['functional_type_values'] !== []) {
                $this->line('');
                $this->info('Functional AccountType values');
                $this->table(
                    ['Value', 'Rows'],
                    collect($profile['functional_type_values'])->map(static fn (array $row): array => [
                        is_scalar($row['value']) ? (string) $row['value'] : json_encode($row['value']),
                        $row['count'],
                    ])->all(),
                );
            }

            if ($profile['has_accounts_values'] !== []) {
                $this->line('');
                $this->info('HasAccounts values');
                $this->table(
                    ['Value', 'Rows'],
                    collect($profile['has_accounts_values'])->map(static fn (array $row): array => [
                        is_scalar($row['value']) ? (string) $row['value'] : json_encode($row['value']),
                        $row['count'],
                    ])->all(),
                );
            }

            if ($profile['has_trans_values'] !== []) {
                $this->line('');
                $this->info('HasTrans values');
                $this->table(
                    ['Value', 'Rows'],
                    collect($profile['has_trans_values'])->map(static fn (array $row): array => [
                        is_scalar($row['value']) ? (string) $row['value'] : json_encode($row['value']),
                        $row['count'],
                    ])->all(),
                );
            }

            if ($profile['root_accounts'] !== []) {
                $this->line('');
                $this->info('Root chart-of-accounts nodes');
                $this->table(
                    ['ID', 'Code', 'Name AR', 'Name EN', 'AccountType', 'FinalListType', 'HasAccounts', 'HasTrans', 'Status'],
                    collect($profile['root_accounts'])->map(static fn (array $row): array => [
                        $row['id'],
                        $row['code'],
                        $row['name_ar'] ?? '—',
                        $row['name'] ?? '—',
                        $row['account_type'] ?? '—',
                        $row['final_list_type'] ?? '—',
                        $row['has_accounts'] ?? '—',
                        $row['has_trans'] ?? '—',
                        $row['status'] ?? '—',
                    ])->all(),
                );
            }

            if ($profile['type_values'] !== []) {
                $this->line('');
                $this->info('Resolved classification candidate values');
                $this->table(
                    ['Value', 'Rows'],
                    collect($profile['type_values'])->map(static fn (array $row): array => [
                        is_scalar($row['value']) ? (string) $row['value'] : json_encode($row['value']),
                        $row['count'],
                    ])->all(),
                );
            }

            return $profile['ready'] ? 0 : 1;
        }

        $this->table(
            ['Verification', 'Count'],
            [
                ['Account mappings', $result['target']['account_mappings']],
                ['Orphan mappings', $result['target']['orphan_mappings']],
                ['Duplicate target IDs', $result['target']['duplicate_target_ids']],
                ['Semantic mismatches', $result['target']['semantic_mismatches']],
                ['Parent mismatches', $result['target']['parent_mismatches']],
                ['Control-flag mismatches', $result['target']['control_flag_mismatches']],
                ['Posting-flag mismatches', $result['target']['posting_flag_mismatches']],
            ],
        );

        $this->line('');
        $this->info('Native account type distribution');
        $types = collect(array_unique(array_merge(
            array_keys($result['target']['expected_type_counts']),
            array_keys($result['target']['actual_type_counts']),
        )))->sort()->values();
        $this->table(
            ['Type', 'Expected', 'Actual'],
            $types->map(static fn (string $type): array => [
                $type,
                $result['target']['expected_type_counts'][$type] ?? 0,
                $result['target']['actual_type_counts'][$type] ?? 0,
            ])->all(),
        );

        if (! $result['healthy']) {
            $this->error('LEGACY NATIVE FINANCE ACCOUNT COVERAGE FAILED.');
            return 1;
        }

        $this->info('LEGACY NATIVE FINANCE ACCOUNT COVERAGE PASSED.');
        return 0;
    },
)->purpose('Promote the legacy GL account master into FOODEX native enterprise finance accounts using fail-closed accounting semantics.');

Artisan::command(
    'foodex:legacy-finance-accounts-verify {--json : Output JSON}',
    function (LegacyFinanceAccountMaterializerService $materializer): int {
        try {
            $result = $materializer->verify();
        } catch (Throwable $exception) {
            $this->error('Legacy finance account verification failed: '.$exception->getMessage());
            return 1;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return $result['healthy'] ? 0 : 1;
        }

        $this->table(
            ['Metric', 'Count'],
            [
                ['Source accounts', $result['profile']['source_rows']],
                ['Unclassified type rows', $result['profile']['classification']['unclassified_type_rows']],
                ['Account mappings', $result['target']['account_mappings']],
                ['Orphan mappings', $result['target']['orphan_mappings']],
                ['Duplicate target IDs', $result['target']['duplicate_target_ids']],
                ['Semantic mismatches', $result['target']['semantic_mismatches']],
                ['Parent mismatches', $result['target']['parent_mismatches']],
                ['Control-flag mismatches', $result['target']['control_flag_mismatches']],
                ['Posting-flag mismatches', $result['target']['posting_flag_mismatches']],
            ],
        );

        $this->line('');
        $this->info('Native account type distribution');
        $types = collect(array_unique(array_merge(
            array_keys($result['target']['expected_type_counts']),
            array_keys($result['target']['actual_type_counts']),
        )))->sort()->values();
        $this->table(
            ['Type', 'Expected', 'Actual'],
            $types->map(static fn (string $type): array => [
                $type,
                $result['target']['expected_type_counts'][$type] ?? 0,
                $result['target']['actual_type_counts'][$type] ?? 0,
            ])->all(),
        );

        if (! $result['healthy']) {
            $this->error('LEGACY NATIVE FINANCE ACCOUNT COVERAGE FAILED.');
            return 1;
        }

        $this->info('LEGACY NATIVE FINANCE ACCOUNT COVERAGE PASSED.');
        return 0;
    },
)->purpose('Verify one-to-one native chart-of-accounts coverage and parent integrity for the legacy GL master.');

Artisan::command(
    'foodex:legacy-finance-assets-semantics {--json : Output JSON}',
    function (LegacyFinanceAssetProfilerService $profiler): int {
        try {
            $profile = $profiler->profile();
        } catch (Throwable $exception) {
            $this->error('Legacy fixed-asset semantic profiling failed: '.$exception->getMessage());
            return 1;
        }

        $result = [
            'source_table' => $profile['source_table'],
            'source_rows' => $profile['source_rows'],
            'numeric_stats' => $profile['numeric_stats'],
            'date_stats' => $profile['date_stats'],
            'asset_semantic_masters' => $profile['asset_semantic_masters'],
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));
            return 0;
        }

        $this->info('Legacy fixed-asset semantic master profile');
        $this->line((string) json_encode(
            $result,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));

        return 0;
    },
)->purpose('Output only the source-backed fixed-asset semantic master rows and referenced GL account mappings.');

Artisan::command(
    'foodex:legacy-finance-assets-profile {--json : Output JSON}',
    function (LegacyFinanceAssetProfilerService $profiler): int {
        try {
            $profile = $profiler->profile();
        } catch (Throwable $exception) {
            $this->error('Legacy fixed-asset profiling failed: '.$exception->getMessage());
            return 1;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return 0;
        }

        $this->info('Legacy fixed-asset source profile');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Source table', $profile['source_table']],
                ['Source rows', $profile['source_rows']],
                ['Resolved ID', $profile['resolved_fields']['id'] ?? '—'],
                ['Resolved code', $profile['resolved_fields']['code'] ?? '—'],
                ['Resolved name', $profile['resolved_fields']['name'] ?? '—'],
                ['Resolved category', $profile['resolved_fields']['category_id'] ?? '—'],
                ['Resolved depreciation ID', $profile['resolved_fields']['depreciation_id'] ?? '—'],
                ['Resolved acquisition date', $profile['resolved_fields']['acquisition_date'] ?? '—'],
                ['Resolved capitalization date', $profile['resolved_fields']['capitalization_date'] ?? '—'],
                ['Resolved acquisition amount', $profile['resolved_fields']['acquisition_amount'] ?? '—'],
                ['Resolved salvage value', $profile['resolved_fields']['salvage_value'] ?? '—'],
                ['Resolved useful-life months', $profile['resolved_fields']['useful_life_months'] ?? '—'],
                ['Resolved useful-life years', $profile['resolved_fields']['useful_life_years'] ?? '—'],
                ['Resolved depreciation rate', $profile['resolved_fields']['depreciation_rate'] ?? '—'],
                ['Resolved asset account', $profile['resolved_fields']['asset_account_id'] ?? '—'],
                ['Resolved accumulated dep. account', $profile['resolved_fields']['accumulated_depreciation_account_id'] ?? '—'],
                ['Resolved dep. expense account', $profile['resolved_fields']['depreciation_expense_account_id'] ?? '—'],
                ['Resolved offset account', $profile['resolved_fields']['offset_account_id'] ?? '—'],
                ['Resolved cost center', $profile['resolved_fields']['cost_center_id'] ?? '—'],
                ['Resolved status', $profile['resolved_fields']['status'] ?? '—'],
            ],
        );

        $this->line('');
        $this->info('Source gl_Assets columns');
        foreach (array_chunk($profile['columns'], 6) as $chunk) {
            $this->line('  '.implode(' | ', $chunk));
        }

        $this->line('');
        $this->info('Resolved-field completeness');
        $this->table(
            ['Field', 'Column', 'Non-null rows'],
            collect($profile['completeness'])->map(static fn (array $row, string $field): array => [
                $field,
                $row['column'] ?? '—',
                $row['non_null_rows'],
            ])->values()->all(),
        );

        foreach ($profile['distributions'] as $field => $rows) {
            if ($rows === []) {
                continue;
            }
            $this->line('');
            $this->info($field.' distribution');
            $this->table(
                ['Value', 'Rows'],
                collect($rows)->map(static fn (array $row): array => [
                    is_scalar($row['value']) ? (string) $row['value'] : json_encode($row['value']),
                    $row['count'],
                ])->all(),
            );
        }

        if ($profile['samples'] !== []) {
            $this->line('');
            $this->info('First fixed-asset source samples');
            foreach ($profile['samples'] as $index => $row) {
                $this->line(sprintf(
                    '#%d %s',
                    $index + 1,
                    json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ));
            }
        }

        $this->warn('PROFILE ONLY: no fixed-asset target rows were written.');
        return 0;
    },
)->purpose('Profile legacy fixed-asset source semantics before enabling native asset materialization.');

Artisan::command(
    'foodex:legacy-finance-cost-centers {--apply : Materialize legacy cost centers into native enterprise cost centers} {--json : Output JSON}',
    function (LegacyFinanceCostCenterMaterializerService $materializer): int {
        try {
            $result = $materializer->materialize((bool) $this->option('apply'));
        } catch (Throwable $exception) {
            $this->error('Legacy finance cost-center materialization failed: '.$exception->getMessage());
            return 1;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return ($result['healthy'] ?? ($result['profile']['ready'] ?? false)) ? 0 : 1;
        }

        $profile = $result['profile'];
        $this->info((bool) $this->option('apply')
            ? 'Legacy native cost-center materialization completed.'
            : 'Legacy finance cost-center dry-run');

        $this->table(
            ['Metric', 'Value'],
            [
                ['Source table', $profile['source_table']],
                ['Source rows', $profile['source_rows']],
                ['ID column', $profile['resolved_columns']['id'] ?? '—'],
                ['Code column', $profile['resolved_columns']['code'] ?? '—'],
                ['Name column', $profile['resolved_columns']['name'] ?? '—'],
                ['Arabic-name column', $profile['resolved_columns']['name_ar'] ?? '—'],
                ['Parent column', $profile['resolved_columns']['parent'] ?? '—'],
                ['Status column', $profile['resolved_columns']['status'] ?? '—'],
                ['Missing ID rows', $profile['classification']['missing_id_rows']],
                ['Duplicate IDs', $profile['classification']['duplicate_ids']],
                ['Missing code rows', $profile['classification']['missing_code_rows']],
                ['Missing name rows', $profile['classification']['missing_name_rows']],
                ['Missing parent rows', $profile['classification']['missing_parent_rows']],
                ['Parent-cycle rows', $profile['classification']['parent_cycle_rows']],
                ['Profile ready', $profile['ready'] ? 'YES' : 'NO'],
            ],
        );

        if (! (bool) $this->option('apply')) {
            $this->line('');
            $this->info('Source cost-center columns');
            foreach (array_chunk($profile['columns'], 6) as $chunk) {
                $this->line('  '.implode(' | ', $chunk));
            }

            if ($profile['status_values'] !== []) {
                $this->line('');
                $this->info('Source cost-center status values');
                $this->table(
                    ['Value', 'Rows'],
                    collect($profile['status_values'])->map(static fn (array $row): array => [
                        is_scalar($row['value']) ? (string) $row['value'] : json_encode($row['value']),
                        $row['count'],
                    ])->all(),
                );
            }

            return $profile['ready'] ? 0 : 1;
        }

        $this->table(
            ['Verification', 'Count'],
            [
                ['Cost-center mappings', $result['target']['cost_center_mappings']],
                ['Orphan mappings', $result['target']['orphan_mappings']],
                ['Duplicate target IDs', $result['target']['duplicate_target_ids']],
                ['Semantic mismatches', $result['target']['semantic_mismatches']],
                ['Parent mismatches', $result['target']['parent_mismatches']],
            ],
        );

        if (! $result['healthy']) {
            $this->error('LEGACY NATIVE COST-CENTER COVERAGE FAILED.');
            return 1;
        }

        $this->info('LEGACY NATIVE COST-CENTER COVERAGE PASSED.');
        return 0;
    },
)->purpose('Promote the legacy GL cost-center master into FOODEX native enterprise cost centers.');

Artisan::command(
    'foodex:legacy-finance-cost-centers-verify {--json : Output JSON}',
    function (LegacyFinanceCostCenterMaterializerService $materializer): int {
        try {
            $result = $materializer->verify();
        } catch (Throwable $exception) {
            $this->error('Legacy finance cost-center verification failed: '.$exception->getMessage());
            return 1;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return $result['healthy'] ? 0 : 1;
        }

        $this->table(
            ['Metric', 'Count'],
            [
                ['Source cost centers', $result['profile']['source_rows']],
                ['Cost-center mappings', $result['target']['cost_center_mappings']],
                ['Orphan mappings', $result['target']['orphan_mappings']],
                ['Duplicate target IDs', $result['target']['duplicate_target_ids']],
                ['Semantic mismatches', $result['target']['semantic_mismatches']],
                ['Parent mismatches', $result['target']['parent_mismatches']],
            ],
        );

        if (! $result['healthy']) {
            $this->error('LEGACY NATIVE COST-CENTER COVERAGE FAILED.');
            return 1;
        }

        $this->info('LEGACY NATIVE COST-CENTER COVERAGE PASSED.');
        return 0;
    },
)->purpose('Verify one-to-one native cost-center coverage and parent integrity.');

Artisan::command(
    'foodex:legacy-supplier-brands {--apply : Derive brands from suppliers using all explicit supplier-product source relations} {--json : Output JSON}',
    function (LegacySupplierBrandMaterializerService $materializer): int {
        try {
            $result = $materializer->materialize((bool) $this->option('apply'));
        } catch (Throwable $exception) {
            $this->error('Supplier-derived brand materialization failed: '.$exception->getMessage());
            return 1;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return ($result['healthy'] ?? true) ? 0 : 1;
        }

        $this->info((bool) $this->option('apply') ? 'Supplier-derived brand materialization completed.' : 'Supplier-derived brand dry-run');
        $source = $result['source'];
        $target = $result['target'];

        $this->table(
            ['Metric', 'Count'],
            [
                ['Legacy supplier rows', $source['legacy_supplier_rows'] ?? $source['supplier_mappings'] ?? 0],
                ['Legacy supplier mappings', $source['legacy_supplier_mappings'] ?? $source['supplier_mappings'] ?? 0],
                ['Legacy products', $source['legacy_products'] ?? $source['product_mappings'] ?? 0],
                ['Supplier-product relations', $source['supplier_product_relations'] ?? 0],
                ['Products with supplier relation', $source['products_with_supplier_relation'] ?? 0],
                ['Products without supplier relation', $source['products_without_supplier_relation'] ?? ($target['products_without_supplier_relation'] ?? 0)],
                ['Direct wh_Items supplier column', $source['direct_item_supplier_column'] ?? '—'],
                ['Direct wh_Items relations', $source['direct_item_relations'] ?? 0],
                ['Supplier-item bridge tables', count($source['bridge_tables'] ?? [])],
                ['Supplier-item bridge relations', $source['bridge_relations'] ?? 0],
                ['Procurement-history relations', $source['procurement_relations'] ?? 0],
                ['Derived brands', $target['derived_brands'] ?? 0],
                ['Products with primary brand', $target['products_with_primary_brand'] ?? 0],
                ['Accounted products', $target['accounted_products'] ?? (($source['products_with_supplier_relation'] ?? 0) + ($source['products_without_supplier_relation'] ?? 0))],
                ['Duplicate primary products', $target['duplicate_primary_products'] ?? 0],
                ['Primary brand mismatches', $target['primary_brand_mismatches'] ?? 0],
            ],
        );

        if ((bool) $this->option('apply')) {
            if (! $result['healthy']) {
                $this->error('SUPPLIER-DERIVED BRAND COVERAGE FAILED.');
                return 1;
            }
            $this->info('SUPPLIER-DERIVED BRAND COVERAGE PASSED.');
        }

        return 0;
    },
)->purpose('Build B2B brands from legacy suppliers and assign products using source-authoritative supplier relations.');

Artisan::command(
    'foodex:legacy-supplier-brands-verify {--json : Output JSON}',
    function (LegacySupplierBrandMaterializerService $materializer): int {
        try {
            $result = $materializer->verify();
        } catch (Throwable $exception) {
            $this->error('Supplier-derived brand verification failed: '.$exception->getMessage());
            return 1;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return $result['healthy'] ? 0 : 1;
        }

        $this->table(
            ['Metric', 'Count'],
            [
                ['Legacy supplier rows', $result['source']['legacy_supplier_rows']],
                ['Legacy supplier mappings', $result['source']['legacy_supplier_mappings']],
                ['Legacy products', $result['source']['legacy_products']],
                ['Supplier-product relations', $result['source']['supplier_product_relations']],
                ['Products with supplier relation', $result['source']['products_with_supplier_relation']],
                ['Direct wh_Items supplier column', $result['source']['direct_item_supplier_column'] ?? '—'],
                ['Direct wh_Items relations', $result['source']['direct_item_relations']],
                ['Supplier-item bridge tables', count($result['source']['bridge_tables'])],
                ['Supplier-item bridge relations', $result['source']['bridge_relations']],
                ['Procurement-history relations', $result['source']['procurement_relations']],
                ['Supplier brand mappings', $result['target']['supplier_brand_mappings']],
                ['Derived brands', $result['target']['derived_brands']],
                ['Products with primary brand', $result['target']['products_with_primary_brand']],
                ['Products without supplier relation', $result['target']['products_without_supplier_relation']],
                ['Accounted products', $result['target']['accounted_products']],
                ['Duplicate primary products', $result['target']['duplicate_primary_products']],
                ['Primary brand mismatches', $result['target']['primary_brand_mismatches']],
                ['Relations missing brand', $result['target']['relations_missing_brand']],
                ['Relations missing supplier', $result['target']['relations_missing_supplier']],
            ],
        );

        if (! $result['healthy']) {
            $this->error('SUPPLIER-DERIVED BRAND COVERAGE FAILED.');
            return 1;
        }

        $this->info('SUPPLIER-DERIVED BRAND COVERAGE PASSED: every imported product is either supplier-branded or explicitly classified as having no supplier relation in the source.');
        return 0;
    },
)->purpose('Verify complete supplier-derived brand coverage and deterministic primary supplier assignment.');

Artisan::command(
    'foodex:legacy-van-history {--apply : Materialize legacy Van operational history into native FOODEX tables} {--json : Output JSON}',
    function (LegacyVanInventoryHistoryMaterializerService $materializer): int {
        try {
            $result = $materializer->materialize((bool) $this->option('apply'));
        } catch (Throwable $exception) {
            $this->error('Legacy Van history materialization failed: '.$exception->getMessage());

            return 1;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));

            return ($result['healthy'] ?? true) ? 0 : 1;
        }

        $this->info((bool) $this->option('apply')
            ? 'Legacy Van operational history materialization completed.'
            : 'Legacy Van operational history dry-run');

        $this->table(
            ['Metric', 'Count'],
            [
                ['Source headers', $result['source']['headers']],
                ['Source details', $result['source']['details']],
                ['Target headers', $result['target']['headers']],
                ['Target details', $result['target']['details']],
                ...((bool) $this->option('apply') ? [
                    ['Header mappings', $result['target']['header_mappings']],
                    ['Detail mappings', $result['target']['detail_mappings']],
                    ['Headers without legacy ID', $result['target']['headers_without_legacy_id']],
                    ['Duplicate legacy header IDs', $result['target']['duplicate_legacy_header_ids']],
                    ['Documents with car identifier', $result['target']['documents_with_car_identifier']],
                    ['Documents linked to vans', $result['target']['documents_linked_to_van']],
                    ['Source-car unmapped documents', $result['target']['source_car_unmapped_documents']],
                    ['Documents without car identifier', $result['target']['documents_without_car_identifier']],
                    ['Details with resolvable header', $result['target']['details_with_resolvable_header']],
                    ['Linked detail rows', $result['target']['linked_detail_rows']],
                    ['Source-orphan detail rows', $result['target']['source_orphan_details']],
                    ['Details without header identifier', $result['target']['details_without_header_identifier']],
                    ['Unexpected unlinked details', $result['target']['unexpected_unlinked_details']],
                    ['Unmapped product lines', $result['target']['unmapped_product_lines']],
                ] : []),
            ],
        );

        if ((bool) $this->option('apply')) {
            if (! $result['healthy']) {
                $this->error('LEGACY VAN HISTORY COVERAGE FAILED.');

                return 1;
            }

            $this->info('LEGACY VAN HISTORY COVERAGE PASSED.');
        }

        return 0;
    },
)->purpose('Materialize wh_CarTransHeader/wh_CarTransDetails as native Van operational history without mutating stock balances.');

Artisan::command(
    'foodex:legacy-van-history-verify {--json : Output JSON}',
    function (LegacyVanInventoryHistoryMaterializerService $materializer): int {
        try {
            $result = $materializer->verify();
        } catch (Throwable $exception) {
            $this->error('Legacy Van history verification failed: '.$exception->getMessage());

            return 1;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));

            return $result['healthy'] ? 0 : 1;
        }

        $this->table(
            ['Metric', 'Count'],
            [
                ['Source headers', $result['source']['headers']],
                ['Native headers', $result['target']['headers']],
                ['Header mappings', $result['target']['header_mappings']],
                ['Headers without legacy ID', $result['target']['headers_without_legacy_id']],
                ['Duplicate legacy header IDs', $result['target']['duplicate_legacy_header_ids']],
                ['Source details', $result['source']['details']],
                ['Native details', $result['target']['details']],
                ['Detail mappings', $result['target']['detail_mappings']],
                ['Documents with car identifier', $result['target']['documents_with_car_identifier']],
                ['Documents linked to vans', $result['target']['documents_linked_to_van']],
                ['Source-car unmapped documents', $result['target']['source_car_unmapped_documents']],
                ['Documents without car identifier', $result['target']['documents_without_car_identifier']],
                ['Details with resolvable header', $result['target']['details_with_resolvable_header']],
                ['Linked detail rows', $result['target']['linked_detail_rows']],
                ['Source-orphan detail rows', $result['target']['source_orphan_details']],
                ['Details without header identifier', $result['target']['details_without_header_identifier']],
                ['Unexpected unlinked details', $result['target']['unexpected_unlinked_details']],
                ['Unmapped product lines', $result['target']['unmapped_product_lines']],
            ],
        );

        if (! $result['healthy']) {
            $this->error('LEGACY VAN HISTORY COVERAGE FAILED.');

            return 1;
        }

        $this->info('LEGACY VAN HISTORY COVERAGE PASSED: historical Van transactions are native and stock authority remains unchanged.');

        return 0;
    },
)->purpose('Verify full source-to-native coverage for legacy Van transaction history.');

Artisan::command(
    'foodex:legacy-business-materialize {--apply : Materialize every uncovered business-impact source table} {--json : Output JSON}',
    function (LegacyBusinessImpactMaterializerService $materializer): int {
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
            $result = $materializer->materialize((bool) $this->option('apply'), $progress);
        } catch (Throwable $exception) {
            $this->error('Legacy business-impact materialization failed: '.$exception->getMessage());

            return 1;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));

            return ($result['healthy'] ?? true) ? 0 : 1;
        }

        $summary = $result['summary'];
        $this->info((bool) $this->option('apply')
            ? 'Legacy business-impact materialization completed.'
            : 'Legacy business-impact materialization dry-run');

        $this->table(
            ['Metric', 'Count'],
            [
                ['SQL Server source tables', $summary['source_tables']],
                ['SQL Server source rows', $summary['source_rows']],
                ['Already represented tables', $summary['already_represented_tables']],
                ['Already represented rows', $summary['already_represented_rows']],
                ['Business extension tables', $summary['business_extension_tables']],
                ['Business extension rows', $summary['business_extension_rows']],
                ['Technical staging tables', $summary['technical_staging_tables']],
                ['Technical staging rows', $summary['technical_staging_rows']],
                ['Business-impact tables', $summary['business_impact_tables']],
                ['Business-impact rows', $summary['business_impact_rows']],
            ],
        );

        if ((bool) $this->option('apply')) {
            if (! $result['healthy']) {
                $this->error('BUSINESS-IMPACT MATERIALIZATION FAILED.');

                return 1;
            }

            $this->info('BUSINESS-IMPACT MATERIALIZATION PASSED.');
        }

        return 0;
    },
)->purpose('Materialize every uncovered non-empty business-impact SQL Server table into a queryable FOODEX business extension layer.');

Artisan::command(
    'foodex:legacy-business-verify {--json : Output JSON}',
    function (LegacyBusinessImpactMaterializerService $materializer): int {
        try {
            $report = $materializer->verify();
        } catch (Throwable $exception) {
            $this->error('Legacy business-impact verification failed: '.$exception->getMessage());

            return 1;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));

            return $report['healthy'] ? 0 : 1;
        }

        $this->info('Legacy SQL business-impact coverage verification');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Business-impact tables', $report['summary']['business_impact_tables']],
                ['Business-impact rows', $report['summary']['business_impact_rows']],
                ['Business extension tables', $report['summary']['business_extension_tables']],
                ['Business extension rows', $report['summary']['business_extension_rows']],
                ['Technical staging tables excluded', $report['summary']['technical_staging_tables']],
                ['Technical staging rows archived', $report['summary']['technical_staging_rows']],
                ['Business coverage failures', count($report['business_failures'])],
                ['Materialization failures', count($report['materialization_failures'])],
            ],
        );

        if ($report['domain_rows'] !== []) {
            $this->line('');
            $this->info('Materialized business-extension rows by domain');
            $this->table(
                ['Domain', 'Rows'],
                collect($report['domain_rows'])
                    ->map(static fn (int $count, string $domain): array => [$domain, $count])
                    ->values()
                    ->all(),
            );
        }

        if ($report['business_failures'] !== []) {
            $this->error('Business-impact SQL tables remain uncovered:');
            $this->table(
                ['Schema', 'Table', 'Rows', 'Materialized', 'Domain'],
                collect($report['business_failures'])
                    ->take(100)
                    ->map(static fn (array $row): array => [
                        $row['schema'],
                        $row['table'],
                        $row['source_rows'],
                        $row['materialized_rows'],
                        $row['domain'],
                    ])
                    ->all(),
            );
        }

        if (! $report['healthy']) {
            $this->error('BUSINESS-IMPACT COVERAGE FAILED.');

            return 1;
        }

        $this->info('BUSINESS-IMPACT COVERAGE PASSED: no business-impact SQL table or row is left archive-only.');

        return 0;
    },
)->purpose('Fail unless every business-impact SQL Server table is either canonicalized or fully materialized into the FOODEX business extension layer.');

Artisan::command(
    'foodex:legacy-canonical-coverage {--json : Output complete JSON coverage report}',
    function (LegacyCanonicalCoverageService $coverage): int {
        try {
            $report = $coverage->report();
        } catch (Throwable $exception) {
            $this->error('Legacy canonical coverage report failed: '.$exception->getMessage());

            return 1;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));

            return $report['healthy_archive'] ? 0 : 1;
        }

        $summary = $report['summary'];
        $this->info('Legacy SQL -> FOODEX canonical coverage');
        $this->table(
            ['Classification', 'Tables / Rows'],
            [
                ['Canonical mapped tables', $summary['canonical_mapped_tables']],
                ['Canonical input tables', $summary['canonical_input_tables']],
                ['Reference-preserved tables', $summary['reference_preserved_tables']],
                ['Invoice-extension tables', $summary['invoice_extension_tables']],
                ['Business-extension tables', $summary['business_extension_tables']],
                ['Technical staging tables', $summary['technical_staging_tables']],
                ['Empty source tables', $summary['empty_source_tables']],
                ['Archive-only nonempty tables', $summary['archive_only_tables']],
                ['Archive-only rows', $summary['archive_only_rows']],
            ],
        );

        $archiveOnly = array_slice($report['archive_only_nonempty'], 0, 100);
        if ($archiveOnly !== []) {
            $this->warn('Non-empty SQL Server tables still preserved only in the full archive:');
            $this->table(
                ['Schema', 'Table', 'Rows'],
                collect($archiveOnly)->map(static fn (array $row): array => [
                    $row['schema'],
                    $row['table'],
                    $row['source_rows'],
                ])->all(),
            );
        } else {
            $this->info('No non-empty source table remains archive-only.');
        }

        return $report['healthy_archive'] ? 0 : 1;
    },
)->purpose('Show which SQL Server tables are canonicalized, referenced, extended, empty, or still archive-only.');

Artisan::command(
    'foodex:legacy-invoice-extras {--apply : Materialize invoice satellite records from the verified full archive} {--json : Output JSON}',
    function (LegacyInvoiceSourceMaterializerService $materializer): int {
        try {
            $result = $materializer->materialize((bool) $this->option('apply'));
        } catch (Throwable $exception) {
            $this->error('Legacy invoice extra materialization failed: '.$exception->getMessage());

            return 1;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return ($result['healthy'] ?? true) ? 0 : 1;
        }

        if (! (bool) $this->option('apply')) {
            $this->info('Legacy invoice extras dry-run');
            $this->table(
                ['Metric', 'Count'],
                [
                    ['Source rows', $result['source_rows']],
                    ['Existing materialized rows', $result['existing_materialized_rows']],
                ],
            );
            foreach ($result['source_counts'] as $table => $count) {
                $this->line($table.': '.$count);
            }

            return 0;
        }

        $this->info('Legacy invoice extra materialization completed.');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Source rows', $result['source_rows']],
                ['Materialized rows', $result['materialized_rows']],
                ['Linked to canonical invoices', $result['linked_to_invoice_rows']],
            ],
        );

        if (! $result['healthy']) {
            $this->error('LEGACY INVOICE EXTRA COVERAGE FAILED.');

            return 1;
        }

        $this->info('LEGACY INVOICE EXTRA COVERAGE PASSED.');

        return 0;
    },
)->purpose('Materialize archived Foodex invoice satellite records and link them to canonical invoices.');

Artisan::command(
    'foodex:legacy-invoice-extras-verify {--json : Output JSON}',
    function (LegacyInvoiceSourceMaterializerService $materializer): int {
        try {
            $result = $materializer->verify();
        } catch (Throwable $exception) {
            $this->error('Legacy invoice extra verification failed: '.$exception->getMessage());

            return 1;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $result['healthy'] ? 0 : 1;
        }

        $rows = [];
        foreach ($result['source_counts'] as $table => $sourceCount) {
            $rows[] = [
                $table,
                $sourceCount,
                $result['materialized_counts'][$table] ?? 0,
                $result['linked_to_invoice_counts'][$table] ?? 0,
                $result['rows_without_header_identifier'][$table] ?? 0,
            ];
        }

        $this->table(
            ['Source table', 'Archived', 'Materialized', 'Invoice-linked', 'No header key'],
            $rows,
        );

        if (! $result['healthy']) {
            $this->error('LEGACY INVOICE EXTRA COVERAGE FAILED.');

            return 1;
        }

        $this->info('LEGACY INVOICE EXTRA COVERAGE PASSED.');

        return 0;
    },
)->purpose('Verify archived invoice satellite rows are fully materialized.');

Artisan::command(
    'foodex:legacy-stock-reconcile {--json : Output machine-readable JSON}',
    function (LegacyStockReconciliationService $reconciliation): int {
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
            $report = $reconciliation->verify($progress);
        } catch (Throwable $exception) {
            $this->error('Legacy Foodex stock reconciliation failed: '.$exception->getMessage());

            return 1;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));

            return $report['healthy'] ? 0 : 1;
        }

        $this->info('Legacy Foodex source-to-target stock reconciliation');

        $this->table(
            ['Metric', 'Value'],
            [
                ['source detail rows scanned', $report['source']['detail_rows_scanned']],
                ['source accepted stock rows', $report['source']['accepted_stock_rows']],
                ['source positive positions', $report['source']['positive_positions']],
                ['source zero positions', $report['source']['zero_positions']],
                ['source negative positions', $report['source']['negative_positions']],
                ['Store/Car numeric-ID collisions safely namespaced', $report['source']['numeric_store_car_id_collisions']],
                ['target inventories', $report['target']['inventories']],
                ['target baseline movements', $report['target']['baseline_movements']],
                ['target inventory mappings', $report['target']['inventory_mappings']],
                ['store positive positions', $report['breakdown']['stores']['positive_positions']],
                ['store total quantity', $report['breakdown']['stores']['quantity']],
                ['van positive positions', $report['breakdown']['vans']['positive_positions']],
                ['van total quantity', $report['breakdown']['vans']['quantity']],
            ],
        );

        foreach ($report['mismatches'] as $name => $rows) {
            if ($rows !== []) {
                $this->error($name.': '.count($rows).' sample(s) detected.');
            }
        }

        if ($report['healthy']) {
            $this->info('STOCK SOURCE -> TARGET RECONCILIATION PASSED.');

            return 0;
        }

        $this->error('STOCK SOURCE -> TARGET RECONCILIATION FAILED.');

        return 1;
    },
)->purpose('Recalculate Foodex stock from SQL Server and prove every Store/Van item balance matches MySQL inventories.');
