<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class LegacyDatabaseOneShotService
{
    private const SOURCE_SYSTEM = 'foodex_sqlserver';

    public function __construct(
        private readonly LegacyDatabaseSnapshotService $snapshots,
        private readonly LegacyFoodexImportService $importer,
        private readonly LegacyFinanceAssetMaterializerService $assets,
        private readonly LegacyImportRehearsalResetService $reset,
        private readonly WholesalePrincipal $principal,
    ) {}

    public function run(
        bool $apply,
        bool $resetApprovedScope = false,
        ?string $resetConfirmation = null,
        ?callable $progress = null,
    ): array {
        $this->progress($progress, 'preflight', 'start');
        $preflight = $this->importer->preflight();
        $this->progress($progress, 'preflight', 'done');

        if (! $apply) {
            return $this->dryRun($preflight, $resetApprovedScope, $progress);
        }

        $runId = (string) Str::uuid();
        DB::table('legacy_import_runs')->insert([
            'id' => $runId,
            'source_system' => self::SOURCE_SYSTEM,
            'phase' => 'database-one-shot',
            'mode' => 'apply',
            'status' => 'running',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $this->progress($progress, 'full-source-snapshot', 'start');
            $snapshot = $this->snapshots->snapshot();
            $this->progress($progress, 'full-source-snapshot', 'done');

            $resetReport = null;
            if ($resetApprovedScope) {
                $this->progress($progress, 'approved-target-reset', 'start');
                $resetReport = $this->reset->execute($resetConfirmation ?? '');
                $this->progress($progress, 'approved-target-reset', 'done');

                // The reset deliberately preserves business identities and their provenance.
                // Recompute readiness so masters can use the protected post-reset baseline.
                $preflight = $this->importer->preflight();
            }

            $this->assertSemanticTargetReady($preflight);

            $phases = [];
            $phases['masters'] = $this->runUnverifiedPhase('masters', $progress);
            $phases['extended-masters'] = $this->runUnverifiedPhase('extended-masters', $progress);
            $phases['stock'] = $this->runVerifiedPhase('stock', 'foodex:legacy-stock-verify', $progress);
            $phases['sales-history'] = $this->runVerifiedPhase('sales-history', 'foodex:legacy-sales-history-verify', $progress);
            $phases['purchase-history'] = $this->runVerifiedPhase('purchase-history', 'foodex:legacy-purchase-history-verify', $progress);
            $phases['operational-history'] = $this->runVerifiedPhase('operational-history', 'foodex:legacy-operational-history-verify', $progress);
            $phases['finance-history'] = $this->runVerifiedPhase('finance-history', 'foodex:legacy-finance-history-verify', $progress);
            $phases['finance-assets'] = $this->runAssets($progress);
            $phases['customer360'] = $this->runCustomer360($progress);

            $this->progress($progress, 'final-verification', 'start');
            $finalVerification = $this->finalVerification();
            $this->progress($progress, 'final-verification', 'done');

            $coverage = $this->coverage((string) $snapshot['snapshot_id']);
            $assetPreflight = $phases['finance-assets']['native_preflight'];
            $semanticHealthy = collect($finalVerification)->every(
                static fn (array $row): bool => (bool) ($row['healthy'] ?? false),
            );

            $summary = [
                'run_id' => $runId,
                'snapshot_id' => $snapshot['snapshot_id'],
                'source_database' => $snapshot['source_database'],
                'source_table_count' => $snapshot['table_count'],
                'source_row_count' => $snapshot['source_row_count'],
                'archived_row_count' => $snapshot['archived_row_count'],
                'all_source_data_preserved' => (bool) $snapshot['verification']['healthy'],
                'approved_target_reset' => $resetReport,
                'semantic_verifications_healthy' => $semanticHealthy,
                'native_fixed_asset_ready' => (bool) $assetPreflight['ready_for_native_promotion'],
                'coverage' => $coverage,
                'phases' => $phases,
                'final_verification' => $finalVerification,
            ];
            $summary['status'] = $summary['all_source_data_preserved'] && $semanticHealthy
                ? ($coverage['raw_only_source_tables'] === [] && $summary['native_fixed_asset_ready']
                    ? 'complete_native'
                    : 'complete_preserved_with_native_gaps')
                : 'failed';

            DB::table('legacy_import_runs')->where('id', $runId)->update([
                'status' => $summary['status'] === 'failed' ? 'failed' : 'completed',
                'summary' => $this->json($summary),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

            if ($summary['status'] === 'failed') {
                throw new RuntimeException('Full legacy database one-shot verification failed.');
            }

            return $summary;
        } catch (Throwable $exception) {
            DB::table('legacy_import_runs')->where('id', $runId)->update([
                'status' => 'failed',
                'error' => mb_substr($exception->getMessage(), 0, 60000),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

            throw $exception;
        }
    }

    private function dryRun(array $preflight, bool $resetApprovedScope, ?callable $progress): array
    {
        $this->progress($progress, 'full-source-inventory', 'start');
        $snapshotPreview = $this->snapshots->preview();
        $this->progress($progress, 'full-source-inventory', 'done');

        $phasePreviews = [];
        foreach ([
            'masters',
            'extended-masters',
            'stock',
            'sales-history',
            'purchase-history',
            'operational-history',
            'finance-history',
        ] as $phase) {
            $this->progress($progress, $phase.'-dry-run', 'start');
            $phasePreviews[$phase] = $this->importPhase($phase, false);
            $this->progress($progress, $phase.'-dry-run', 'done');
        }

        $phasePreviews['finance-assets'] = $this->assets->preview();
        $phasePreviews['customer360'] = $this->importer->backfillCustomer360(false);

        return [
            'mode' => 'dry-run',
            'preflight' => $preflight,
            'approved_target_reset_preview' => $resetApprovedScope ? $this->reset->preview() : null,
            'full_source_inventory' => $snapshotPreview,
            'phase_previews' => $phasePreviews,
            'rules' => [
                'every_source_base_table_is_inventoried' => true,
                'apply_snapshots_every_source_row_before_semantic_writes' => true,
                'verified_completed_phases_are_resumed_not_reapplied' => true,
                'unsupported_source_data_is_preserved_raw' => true,
                'unsafe_semantic_writes_fail_fast' => true,
                'accounting_data_is_never_fabricated' => true,
            ],
        ];
    }

    private function runUnverifiedPhase(string $phase, ?callable $progress): array
    {
        $this->progress($progress, $phase, 'start');
        $dryRun = $this->importPhase($phase, false);
        $apply = $this->importPhase($phase, true);
        $this->progress($progress, $phase, 'done');

        return [
            'status' => 'applied',
            'dry_run' => $dryRun,
            'apply' => $apply,
        ];
    }

    private function runVerifiedPhase(string $phase, string $verifyCommand, ?callable $progress): array
    {
        $this->progress($progress, $phase, 'start');

        $normalization = null;
        if ($phase === 'stock') {
            $normalization = $this->normalizeLegacyStockToPrincipalWarehouse();
        }

        $before = $this->artisanJson($verifyCommand);
        if ($before['exit_code'] === 0 && (bool) ($before['report']['healthy'] ?? false)) {
            if ($phase === 'stock') {
                $this->assertStockVerification($before['report']);
            }

            $this->progress($progress, $phase, 'done');

            return [
                'status' => $phase === 'stock' && ($normalization['normalized'] ?? false)
                    ? 'already_verified_and_principal_normalized'
                    : 'already_verified',
                'normalization' => $normalization,
                'verification' => $before['report'],
            ];
        }

        $dryRun = $this->importPhase($phase, false);
        $expectedStockProducts = null;
        if ($phase === 'stock') {
            $expectedStockProducts = $this->assertStockPlan($dryRun);
        }

        $apply = $this->importPhase($phase, true);

        if ($phase === 'stock') {
            $normalization = $this->normalizeLegacyStockToPrincipalWarehouse();
        }

        $after = $this->artisanJson($verifyCommand);

        if ($after['exit_code'] !== 0 || ! (bool) ($after['report']['healthy'] ?? false)) {
            throw new RuntimeException("{$phase} verification failed after apply.");
        }

        if ($phase === 'stock') {
            $this->assertStockVerification($after['report'], $expectedStockProducts);
        }

        $this->progress($progress, $phase, 'done');

        return [
            'status' => 'applied_and_verified',
            'dry_run' => $dryRun,
            'apply' => $apply,
            'normalization' => $normalization,
            'verification' => $after['report'],
        ];
    }

    private function assertStockPlan(array $dryRun): int
    {
        $planned = (array) ($dryRun['planned'] ?? []);
        $accepted = (int) ($planned['accepted_stock_detail_rows'] ?? 0);
        $positivePositions = (int) ($planned['positive_positions'] ?? 0);
        $positiveProducts = (int) ($planned['positive_products'] ?? 0);

        if ($accepted <= 0 || $positivePositions <= 0 || $positiveProducts <= 0) {
            throw new RuntimeException(
                'Stock one-shot refused: the source scan did not produce any positive cutover product inventory.'
            );
        }

        foreach (['invalid_source_rows', 'negative_positions', 'unmapped_locations', 'unmapped_items'] as $metric) {
            if ((int) ($planned[$metric] ?? 0) !== 0) {
                throw new RuntimeException(
                    "Stock one-shot refused: {$metric}=".((int) ($planned[$metric] ?? 0)).'.'
                );
            }
        }

        return $positiveProducts;
    }

    private function assertStockVerification(array $report, ?int $expectedPositiveProducts = null): void
    {
        $expected = $expectedPositiveProducts
            ?? (int) (($report['expected']['positive_products'] ?? null)
                ?? ($report['latest_run']['summary']['planned']['positive_products'] ?? 0)
                ?? ($report['expected']['positive_positions'] ?? 0));

        $actual = (int) ($report['inventories']['total'] ?? 0);
        $positive = (int) ($report['inventories']['positive'] ?? 0);
        $movements = (int) ($report['stock_movements']['legacy_baseline'] ?? 0);
        $mappings = (int) ($report['legacy_inventory_mappings'] ?? 0);

        if ($expected <= 0 || $actual !== $expected || $positive !== $expected
            || $movements !== $expected || $mappings !== $expected) {
            throw new RuntimeException(
                'Stock one-shot verification refused: expected positive products do not match '.
                "inventories/movements/mapped-targets ({$expected}/{$actual}/{$movements}/{$mappings})."
            );
        }

        $principalWarehouseId = $this->principal->warehouseId();
        $principalStoreId = $this->principal->storeId();

        $principalInventoryRows = (int) DB::table('inventories')
            ->where('warehouse_id', $principalWarehouseId)
            ->where('quantity', '>', 0)
            ->count();

        $principalQuantity = (float) DB::table('inventories')
            ->where('warehouse_id', $principalWarehouseId)
            ->where('quantity', '>', 0)
            ->sum('quantity');

        $nonPrincipalLegacyTargets = (int) DB::table('legacy_import_mappings as m')
            ->join('inventories as i', 'i.id', '=', 'm.target_id')
            ->where('m.source_system', self::SOURCE_SYSTEM)
            ->where('m.source_table', 'wh_vw_ItemStock2Now')
            ->where('m.target_table', 'inventories')
            ->where('i.warehouse_id', '<>', $principalWarehouseId)
            ->count();

        $principalBelongsToStore = DB::table('warehouses')
            ->where('id', $principalWarehouseId)
            ->where('store_id', $principalStoreId)
            ->where('is_active', true)
            ->exists();

        if (! $principalBelongsToStore || $principalInventoryRows !== $expected
            || $principalQuantity <= 0 || $nonPrincipalLegacyTargets !== 0) {
            throw new RuntimeException(
                'Stock one-shot verification refused: imported wholesale stock is not fully normalized '.
                "to the principal warehouse ({$principalInventoryRows}/{$expected}, qty={$principalQuantity}, ".
                "non_principal_mappings={$nonPrincipalLegacyTargets})."
            );
        }
    }

    /** @return array<string,mixed> */
    private function normalizeLegacyStockToPrincipalWarehouse(): array
    {
        $mappings = DB::table('legacy_import_mappings')
            ->where('source_system', self::SOURCE_SYSTEM)
            ->where('source_table', 'wh_vw_ItemStock2Now')
            ->where('target_table', 'inventories')
            ->get(['id', 'source_key', 'target_id']);

        if ($mappings->isEmpty()) {
            return [
                'normalized' => false,
                'reason' => 'no_legacy_stock_mappings',
                'principal_inventory_rows' => 0,
                'principal_quantity' => 0.0,
            ];
        }

        $mappedInventoryIds = $mappings
            ->pluck('target_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $inventoryRows = DB::table('inventories')
            ->whereIn('id', $mappedInventoryIds)
            ->get(['id', 'warehouse_id', 'product_id', 'quantity', 'reserved_quantity']);

        if ($inventoryRows->count() !== count($mappedInventoryIds)) {
            throw new RuntimeException(
                'Stock one-shot normalization refused: one or more mapped legacy inventory rows are missing.'
            );
        }

        $aggregates = [];
        $productByInventoryId = [];

        foreach ($inventoryRows as $row) {
            $inventoryId = (int) $row->id;
            $productId = (int) $row->product_id;
            $reserved = (float) $row->reserved_quantity;

            if ($productId <= 0 || abs($reserved) > 0.0001) {
                throw new RuntimeException(
                    'Stock one-shot normalization refused: legacy baseline inventory contains invalid product or reservation state.'
                );
            }

            $productByInventoryId[$inventoryId] = $productId;
            $quantity = max(0.0, (float) $row->quantity);

            if (! isset($aggregates[$productId])) {
                $aggregates[$productId] = [
                    'quantity' => 0.0,
                    'source_inventory_ids' => [],
                ];
            }

            $aggregates[$productId]['quantity'] += $quantity;
            $aggregates[$productId]['source_inventory_ids'][] = $inventoryId;
        }

        $aggregates = array_filter(
            $aggregates,
            static fn (array $row): bool => (float) $row['quantity'] > 0.0005,
        );

        if ($aggregates === []) {
            throw new RuntimeException(
                'Stock one-shot normalization refused: mapped legacy stock contains no positive product quantities.'
            );
        }

        $principalStoreId = $this->principal->storeId();
        $principalWarehouseId = $this->principal->warehouseId();

        return DB::transaction(function () use (
            $mappings,
            $mappedInventoryIds,
            $productByInventoryId,
            $aggregates,
            $principalStoreId,
            $principalWarehouseId,
        ): array {
            $targetByProduct = [];
            $created = 0;
            $updated = 0;

            foreach ($aggregates as $productId => $aggregate) {
                $quantity = round((float) $aggregate['quantity'], 3);

                $inventory = DB::table('inventories')
                    ->where('warehouse_id', $principalWarehouseId)
                    ->where('product_id', (int) $productId)
                    ->lockForUpdate()
                    ->first(['id', 'quantity', 'reserved_quantity']);

                if ($inventory !== null
                    && ! in_array((int) $inventory->id, $mappedInventoryIds, true)) {
                    throw new RuntimeException(
                        'Stock one-shot normalization refused: principal warehouse already contains non-legacy product stock.'
                    );
                }

                if ($inventory === null) {
                    $inventoryId = (int) DB::table('inventories')->insertGetId([
                        'warehouse_id' => $principalWarehouseId,
                        'product_id' => (int) $productId,
                        'quantity' => $quantity,
                        'reserved_quantity' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $created++;
                } else {
                    $inventoryId = (int) $inventory->id;
                    DB::table('inventories')->where('id', $inventoryId)->update([
                        'quantity' => $quantity,
                        'reserved_quantity' => 0,
                        'updated_at' => now(),
                    ]);
                    $updated++;
                }

                $targetByProduct[(int) $productId] = $inventoryId;

                DB::table('stock_movements')->updateOrInsert(
                    [
                        'inventory_id' => $inventoryId,
                        'type' => 'legacy_opening_balance',
                        'reference_type' => 'legacy_foodex_stock_baseline',
                        'reference_id' => $inventoryId,
                    ],
                    [
                        'store_id' => $principalStoreId,
                        'user_id' => null,
                        'quantity' => $quantity,
                        'reason' => 'Legacy Foodex cutover opening balance consolidated into FOODEX principal warehouse',
                        'notes' => $this->json([
                            'source_system' => self::SOURCE_SYSTEM,
                            'source_view' => 'dbo.wh_vw_ItemStock2Now',
                            'normalization' => 'principal_warehouse_product_aggregate',
                            'source_inventory_ids' => array_values($aggregate['source_inventory_ids']),
                        ]),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            }

            foreach ($mappings as $mapping) {
                $sourceInventoryId = (int) $mapping->target_id;
                $productId = $productByInventoryId[$sourceInventoryId] ?? null;
                $targetInventoryId = $productId === null ? null : ($targetByProduct[$productId] ?? null);

                if ($targetInventoryId === null) {
                    throw new RuntimeException(
                        'Stock one-shot normalization refused: legacy stock mapping cannot be resolved to a principal product inventory.'
                    );
                }

                DB::table('legacy_import_mappings')
                    ->where('id', $mapping->id)
                    ->update([
                        'target_id' => $targetInventoryId,
                        'updated_at' => now(),
                    ]);
            }

            $keepInventoryIds = array_values(array_unique(array_values($targetByProduct)));

            $ownedInventoryIds = DB::table('stock_movements')
                ->where('type', 'legacy_opening_balance')
                ->where('reference_type', 'legacy_foodex_stock_baseline')
                ->pluck('inventory_id')
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();

            $obsoleteInventoryIds = array_values(array_diff($ownedInventoryIds, $keepInventoryIds));

            if ($obsoleteInventoryIds !== []) {
                $foreignMovements = (int) DB::table('stock_movements')
                    ->whereIn('inventory_id', $obsoleteInventoryIds)
                    ->where(static function ($query): void {
                        $query->where('type', '<>', 'legacy_opening_balance')
                            ->orWhere('reference_type', '<>', 'legacy_foodex_stock_baseline');
                    })
                    ->count();

                $reservedRows = (int) DB::table('inventories')
                    ->whereIn('id', $obsoleteInventoryIds)
                    ->where('reserved_quantity', '<>', 0)
                    ->count();

                if ($foreignMovements !== 0 || $reservedRows !== 0) {
                    throw new RuntimeException(
                        'Stock one-shot normalization refused: obsolete legacy inventory has non-baseline activity or reservations.'
                    );
                }

                DB::table('stock_movements')
                    ->whereIn('inventory_id', $obsoleteInventoryIds)
                    ->where('type', 'legacy_opening_balance')
                    ->where('reference_type', 'legacy_foodex_stock_baseline')
                    ->delete();

                DB::table('inventories')
                    ->whereIn('id', $obsoleteInventoryIds)
                    ->delete();
            }

            $principalRows = (int) DB::table('inventories')
                ->where('warehouse_id', $principalWarehouseId)
                ->where('quantity', '>', 0)
                ->count();

            $principalQuantity = (float) DB::table('inventories')
                ->where('warehouse_id', $principalWarehouseId)
                ->where('quantity', '>', 0)
                ->sum('quantity');

            return [
                'normalized' => true,
                'principal_store_id' => $principalStoreId,
                'principal_warehouse_id' => $principalWarehouseId,
                'principal_inventory_rows' => $principalRows,
                'principal_quantity' => round($principalQuantity, 3),
                'created' => $created,
                'updated' => $updated,
                'removed_obsolete_inventory_rows' => count($obsoleteInventoryIds),
                'source_position_mappings' => $mappings->count(),
            ];
        }, 3);
    }

    private function runAssets(?callable $progress): array
    {
        $this->progress($progress, 'finance-assets', 'start');
        $before = $this->assets->verify();

        if (! $before['healthy']) {
            $dryRun = $this->assets->preview();
            $apply = $this->assets->materialize(true);
        } else {
            $dryRun = null;
            $apply = null;
        }

        $verification = $this->assets->verify();
        if (! $verification['healthy']) {
            throw new RuntimeException('Fixed-asset preservation verification failed.');
        }

        $nativePreflight = $this->assets->nativePromotionPreflight();
        $this->progress($progress, 'finance-assets', 'done');

        return [
            'status' => $apply === null ? 'already_verified' : 'materialized_and_verified',
            'dry_run' => $dryRun,
            'apply' => $apply,
            'verification' => $verification,
            'native_preflight' => $nativePreflight,
        ];
    }

    private function runCustomer360(?callable $progress): array
    {
        $this->progress($progress, 'customer360', 'start');
        $dryRun = $this->importer->backfillCustomer360(false);
        $apply = $this->importer->backfillCustomer360(true);
        $idempotency = $this->importer->backfillCustomer360(false);
        $this->progress($progress, 'customer360', 'done');

        return [
            'status' => 'applied',
            'dry_run' => $dryRun,
            'apply' => $apply,
            'idempotency_check' => $idempotency,
        ];
    }

    private function finalVerification(): array
    {
        $commands = [
            'stock' => 'foodex:legacy-stock-verify',
            'sales-history' => 'foodex:legacy-sales-history-verify',
            'purchase-history' => 'foodex:legacy-purchase-history-verify',
            'operational-history' => 'foodex:legacy-operational-history-verify',
            'finance-history' => 'foodex:legacy-finance-history-verify',
            'finance-assets' => 'foodex:legacy-finance-assets-verify',
        ];
        $reports = [];

        foreach ($commands as $name => $command) {
            $result = $this->artisanJson($command);
            $reports[$name] = [
                'healthy' => $result['exit_code'] === 0 && (bool) ($result['report']['healthy'] ?? false),
                'report' => $result['report'],
            ];
        }

        return $reports;
    }

    private function coverage(string $snapshotId): array
    {
        $archived = $this->snapshots->archivedTables($snapshotId);
        $mapped = DB::table('legacy_import_mappings')
            ->where('source_system', self::SOURCE_SYSTEM)
            ->distinct()
            ->pluck('source_table')
            ->map(static fn ($name): string => (string) $name)
            ->all();
        $mappedSet = array_fill_keys($mapped, true);
        $semantic = [];
        $rawOnly = [];

        foreach ($archived as $table) {
            $qualified = $table['schema'].'.'.$table['table'];
            if (isset($mappedSet[$table['table']]) || isset($mappedSet[$qualified])) {
                $semantic[] = $qualified;
            } else {
                $rawOnly[] = $qualified;
            }
        }

        return [
            'archived_source_tables' => array_values(array_map(
                static fn (array $table): string => $table['schema'].'.'.$table['table'],
                $archived,
            )),
            'tables_with_semantic_mappings' => $semantic,
            'raw_only_source_tables' => $rawOnly,
            'semantic_mapping_rows' => (int) DB::table('legacy_import_mappings')
                ->where('source_system', self::SOURCE_SYSTEM)
                ->count(),
            'raw_archive_is_lossless_fallback' => true,
        ];
    }

    private function importPhase(string $phase, bool $apply): array
    {
        return match ($phase) {
            'masters' => $this->importer->importMasters($apply),
            'extended-masters' => $this->importer->importExtendedMasters($apply),
            'stock' => $this->importer->importStock($apply),
            'sales-history' => $this->importer->importSalesHistory($apply),
            'purchase-history' => $this->importer->importPurchaseHistory($apply),
            'operational-history' => $this->importer->importOperationalHistory($apply),
            'finance-history' => $this->importer->importFinanceHistory($apply),
            default => throw new RuntimeException("Unsupported one-shot phase: {$phase}"),
        };
    }

    private function artisanJson(string $command): array
    {
        $exitCode = Artisan::call($command, ['--json' => true]);
        $output = trim(Artisan::output());
        $decoded = json_decode($output, true);

        return [
            'exit_code' => $exitCode,
            'report' => is_array($decoded) ? $decoded : ['raw_output' => $output],
        ];
    }

    private function assertSemanticTargetReady(array $preflight): void
    {
        $target = (array) ($preflight['target'] ?? []);
        $ready = (bool) ($target['clean_baseline_ready'] ?? false)
            || (bool) ($target['legacy_upgrade_ready'] ?? false)
            || (bool) ($target['legacy_masters_rerun_ready'] ?? false)
            || (bool) ($target['legacy_protected_reset_ready'] ?? false);

        if (! $ready) {
            throw new RuntimeException(
                'Target is not safe for semantic legacy import. Full raw source snapshot was preserved, but native writes were refused.'
            );
        }
    }

    private function progress(?callable $progress, string $stage, string $state): void
    {
        if ($progress !== null) {
            $progress($stage, $state);
        }
    }

    private function json(mixed $value): string
    {
        $json = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        if (! is_string($json)) {
            throw new RuntimeException('Unable to encode full legacy one-shot summary.');
        }

        return $json;
    }
}
