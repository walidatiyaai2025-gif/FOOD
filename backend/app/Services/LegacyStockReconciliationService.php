<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class LegacyStockReconciliationService
{
    private const SOURCE_SYSTEM = 'foodex_sqlserver';

    private const MOVEMENT_SIGNS = [
        1 => 1,
        2 => -1,
        3 => -1,
        4 => 1,
        5 => -1,
        7 => 1,
        8 => -1,
        11 => 1,
        12 => -1,
        13 => 1,
        14 => -1,
        15 => -1,
        16 => 1,
        19 => -1,
        20 => 1,
        22 => 1,
        23 => -1,
    ];

    public function verify(?callable $progress = null): array
    {
        $stage = static function (string $label, callable $callback) use ($progress): mixed {
            if ($progress !== null) {
                $progress($label, 'start', null);
            }

            $startedAt = microtime(true);
            $result = $callback();
            $elapsed = round(microtime(true) - $startedAt, 3);

            if ($progress !== null) {
                $progress($label, 'done', $elapsed);
            }

            return $result;
        };

        $source = $this->legacySource();

        $eligibleItems = $stage('reconcile_load_eligible_items', fn () => $source
            ->table('wh_Items')
            ->where('Status', 1)
            ->where('ItemType', '<>', 4)
            ->pluck('ID')
            ->map(static fn ($id): int => (int) $id)
            ->flip());

        $sourceLocations = $stage('reconcile_load_locations', function () use ($source): array {
            $locations = [];
            $storeIds = [];
            $carIds = [];

            foreach ($source->table('wh_Stores')->get(['ID', 'Code']) as $row) {
                $id = (int) $row->ID;
                $key = LegacyStockLocationResolver::key('wh_Stores', $id);
                $storeIds[$id] = true;
                $locations[$key] = [
                    'source_table' => 'wh_Stores',
                    'source_id' => $id,
                    'code' => $row->Code,
                ];
            }

            foreach ($source->table('wh_Cars')->get(['ID', 'Code']) as $row) {
                $id = (int) $row->ID;
                $key = LegacyStockLocationResolver::key('wh_Cars', $id);
                $carIds[$id] = true;
                $locations[$key] = [
                    'source_table' => 'wh_Cars',
                    'source_id' => $id,
                    'code' => $row->Code,
                ];
            }

            return [
                'locations' => $locations,
                'numeric_id_collisions' => count(array_intersect_key($storeIds, $carIds)),
            ];
        });

        $headers = $stage('reconcile_load_headers', function () use ($source, $sourceLocations): array {
            $map = [];

            foreach ($source->table('wh_TransHeader')
                ->where('Status', 1)
                ->whereIn('TransType', array_keys(self::MOVEMENT_SIGNS))
                ->get(['HeaderId', 'TransType', 'StoreId']) as $row) {
                $type = (int) $row->TransType;
                $sourceId = (int) ($row->StoreId ?? 0);
                $resolved = LegacyStockLocationResolver::resolve(
                    $type,
                    $sourceId,
                    $sourceLocations['locations'],
                );

                $map[(int) $row->HeaderId] = [
                    'type' => $type,
                    'source_table' => $resolved['source_table'],
                    'source_id' => $sourceId,
                    'location_key' => $resolved['location_key'],
                    'location_resolution' => $resolved['resolution'],
                ];
            }

            return $map;
        });

        $positions = [];
        $invalidRows = [];
        $scannedRows = 0;
        $acceptedRows = 0;

        $stage('reconcile_stream_details', function () use (
            $eligibleItems,
            $headers,
            $sourceLocations,
            &$positions,
            &$invalidRows,
            &$scannedRows,
            &$acceptedRows,
        ): void {
            $lastDetailId = 0;
            $chunkSize = max(250, (int) config('database.connections.foodex_legacy.detail_chunk', 2000));

            while (true) {
                $rows = $this->detailChunk($lastDetailId, $chunkSize);

                if ($rows->isEmpty()) {
                    break;
                }

                foreach ($rows as $row) {
                    $detailId = (int) ($row->DetailId ?? 0);
                    $lastDetailId = max($lastDetailId, $detailId);
                    $scannedRows++;

                    if ((int) ($row->Status ?? 0) !== 1) {
                        continue;
                    }

                    $header = $headers[(int) ($row->HeaderId ?? 0)] ?? null;
                    if ($header === null) {
                        continue;
                    }

                    $itemId = (int) ($row->ItemID ?? 0);
                    if (! $eligibleItems->has($itemId)) {
                        continue;
                    }

                    $locationKey = (string) $header['location_key'];

                    if (
                        $itemId <= 0
                        || (int) $header['source_id'] <= 0
                        || ! isset($sourceLocations['locations'][$locationKey])
                    ) {
                        if (count($invalidRows) < 50) {
                            $invalidRows[] = [
                                'detail_id' => $detailId,
                                'header_id' => (int) ($row->HeaderId ?? 0),
                                'item_id' => $itemId,
                                'location_key' => $locationKey,
                                'trans_type' => (int) $header['type'],
                                'location_resolution' => $header['location_resolution'] ?? null,
                            ];
                        }

                        continue;
                    }

                    $acceptedRows++;
                    $type = (int) $header['type'];
                    $quantity = self::MOVEMENT_SIGNS[$type] * (float) ($row->TransPkgQty1 ?? 0);
                    $positionKey = $locationKey.':'.$itemId;

                    if (! isset($positions[$positionKey])) {
                        $positions[$positionKey] = [
                            'source_table' => (string) $header['source_table'],
                            'source_id' => (int) $header['source_id'],
                            'location_key' => $locationKey,
                            'item_id' => $itemId,
                            'quantity' => 0.0,
                        ];
                    }

                    $positions[$positionKey]['quantity'] += $quantity;
                }
            }
        });

        foreach ($positions as &$position) {
            $position['quantity'] = round((float) $position['quantity'], 3);
        }
        unset($position);

        $warehouseMap = $stage('reconcile_target_warehouse_mappings', function (): array {
            $map = [];

            foreach (DB::table('legacy_import_mappings')
                ->where('source_system', self::SOURCE_SYSTEM)
                ->where('target_table', 'warehouses')
                ->whereIn('source_table', ['wh_Stores', 'wh_Cars'])
                ->get(['source_table', 'source_key', 'target_id']) as $row) {
                $map[LegacyStockLocationResolver::key(
                    (string) $row->source_table,
                    (int) $row->source_key,
                )] = (int) $row->target_id;
            }

            return $map;
        });

        $productMap = $stage('reconcile_target_product_mappings', fn () => DB::table('legacy_import_mappings')
            ->where('source_system', self::SOURCE_SYSTEM)
            ->where('source_table', 'wh_Items')
            ->where('target_table', 'products')
            ->get(['source_key', 'target_id'])
            ->mapWithKeys(static fn ($row): array => [(int) $row->source_key => (int) $row->target_id])
            ->all());

        $positive = [];
        $zeroCount = 0;
        $negative = [];
        $unmappedLocations = [];
        $unmappedItems = [];

        foreach ($positions as $positionKey => $position) {
            $quantity = (float) $position['quantity'];

            if (! isset($warehouseMap[$position['location_key']])) {
                $unmappedLocations[$position['location_key']] = true;
            }

            if (! isset($productMap[$position['item_id']])) {
                $unmappedItems[$position['item_id']] = true;
            }

            if ($quantity > 0.0005) {
                $positive[$positionKey] = $position;
            } elseif ($quantity < -0.0005) {
                $negative[$positionKey] = $position;
            } else {
                $zeroCount++;
            }
        }

        $targetRows = $stage('reconcile_load_target_inventory', fn () => DB::table('inventories')
            ->get(['id', 'warehouse_id', 'product_id', 'quantity', 'reserved_quantity']));

        $targetByPair = [];
        foreach ($targetRows as $row) {
            $targetByPair[((int) $row->warehouse_id).':'.((int) $row->product_id)] = $row;
        }

        $expectedTargetPairs = [];
        $missingTarget = [];
        $quantityMismatches = [];
        $storeExpectedRows = 0;
        $vanExpectedRows = 0;
        $storeExpectedQuantity = 0.0;
        $vanExpectedQuantity = 0.0;

        foreach ($positive as $positionKey => $position) {
            $locationKey = (string) $position['location_key'];
            $itemId = (int) $position['item_id'];

            if (! isset($warehouseMap[$locationKey]) || ! isset($productMap[$itemId])) {
                continue;
            }

            $warehouseId = (int) $warehouseMap[$locationKey];
            $productId = (int) $productMap[$itemId];
            $pair = $warehouseId.':'.$productId;
            $expectedTargetPairs[$pair] = true;

            $isVan = (string) $position['source_table'] === 'wh_Cars';
            if ($isVan) {
                $vanExpectedRows++;
                $vanExpectedQuantity += (float) $position['quantity'];
            } else {
                $storeExpectedRows++;
                $storeExpectedQuantity += (float) $position['quantity'];
            }

            $target = $targetByPair[$pair] ?? null;
            if ($target === null) {
                if (count($missingTarget) < 100) {
                    $missingTarget[] = [
                        'position_key' => $positionKey,
                        'location_key' => $locationKey,
                        'item_id' => $itemId,
                        'expected_quantity' => (float) $position['quantity'],
                        'warehouse_id' => $warehouseId,
                        'product_id' => $productId,
                    ];
                }

                continue;
            }

            $actual = round((float) $target->quantity, 3);
            $expected = round((float) $position['quantity'], 3);

            if (abs($actual - $expected) > 0.0005 || abs((float) $target->reserved_quantity) > 0.0005) {
                if (count($quantityMismatches) < 100) {
                    $quantityMismatches[] = [
                        'position_key' => $positionKey,
                        'location_key' => $locationKey,
                        'item_id' => $itemId,
                        'expected_quantity' => $expected,
                        'actual_quantity' => $actual,
                        'reserved_quantity' => round((float) $target->reserved_quantity, 3),
                        'warehouse_id' => $warehouseId,
                        'product_id' => $productId,
                    ];
                }
            }
        }

        $extraTarget = [];
        foreach ($targetByPair as $pair => $row) {
            if (! isset($expectedTargetPairs[$pair])) {
                if (count($extraTarget) < 100) {
                    $extraTarget[] = [
                        'inventory_id' => (int) $row->id,
                        'warehouse_id' => (int) $row->warehouse_id,
                        'product_id' => (int) $row->product_id,
                        'quantity' => round((float) $row->quantity, 3),
                        'reserved_quantity' => round((float) $row->reserved_quantity, 3),
                    ];
                }
            }
        }

        $baselineMovements = (int) DB::table('stock_movements')
            ->where('type', 'legacy_opening_balance')
            ->where('reference_type', 'legacy_foodex_stock_baseline')
            ->count();

        $inventoryMappings = (int) DB::table('legacy_import_mappings')
            ->where('source_system', self::SOURCE_SYSTEM)
            ->where('source_table', 'wh_vw_ItemStock2Now')
            ->where('target_table', 'inventories')
            ->distinct()
            ->count('target_id');

        $healthy = $invalidRows === []
            && $negative === []
            && $unmappedLocations === []
            && $unmappedItems === []
            && $missingTarget === []
            && $quantityMismatches === []
            && $extraTarget === []
            && $targetRows->count() === count($positive)
            && $baselineMovements === count($positive)
            && $inventoryMappings === count($positive);

        return [
            'healthy' => $healthy,
            'source' => [
                'detail_rows_scanned' => $scannedRows,
                'accepted_stock_rows' => $acceptedRows,
                'aggregated_positions' => count($positions),
                'positive_positions' => count($positive),
                'zero_positions' => $zeroCount,
                'negative_positions' => count($negative),
                'numeric_store_car_id_collisions' => (int) $sourceLocations['numeric_id_collisions'],
            ],
            'target' => [
                'inventories' => $targetRows->count(),
                'baseline_movements' => $baselineMovements,
                'inventory_mappings' => $inventoryMappings,
            ],
            'breakdown' => [
                'stores' => [
                    'positive_positions' => $storeExpectedRows,
                    'quantity' => round($storeExpectedQuantity, 3),
                ],
                'vans' => [
                    'positive_positions' => $vanExpectedRows,
                    'quantity' => round($vanExpectedQuantity, 3),
                ],
            ],
            'mismatches' => [
                'invalid_source_rows' => $invalidRows,
                'negative_positions' => array_slice(array_values($negative), 0, 100),
                'unmapped_locations' => array_slice(array_keys($unmappedLocations), 0, 100),
                'unmapped_items' => array_slice(array_keys($unmappedItems), 0, 100),
                'missing_target_positions' => $missingTarget,
                'quantity_or_reserved_mismatches' => $quantityMismatches,
                'extra_target_positions' => $extraTarget,
            ],
            'rules' => [
                'source_tables_for_stock' => ['wh_TransHeader', 'wh_TransDetails'],
                'car_tables_are_audit_only' => true,
                'store_and_van_ids_are_namespaced' => true,
                'location_resolution_uses_actual_source_membership_with_trans_type_collision_breaker' => true,
                'car_preferred_location_types' => LegacyStockLocationResolver::CAR_PREFERRED_TYPES,
                'only_positive_positions_are_materialized' => true,
                'quantity_tolerance' => 0.0005,
            ],
        ];
    }

    private function detailChunk(int $lastDetailId, int $chunkSize): \Illuminate\Support\Collection
    {
        $maxAttempts = max(1, (int) config('database.connections.foodex_legacy.retry_attempts', 5));

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return $this->legacySource()
                    ->table('wh_TransDetails')
                    ->select(['DetailId', 'HeaderId', 'ItemID', 'TransPkgQty1', 'Status'])
                    ->where('DetailId', '>', $lastDetailId)
                    ->orderBy('DetailId')
                    ->limit($chunkSize)
                    ->get();
            } catch (Throwable $exception) {
                if (! $this->isTransientSqlServerError($exception) || $attempt >= $maxAttempts) {
                    throw $exception;
                }

                DB::purge('foodex_legacy');
                usleep(min(5 * $attempt, 20) * 1000000);
            }
        }

        throw new RuntimeException('Unexpected SQL Server detail paging failure.');
    }

    private function legacySource(): Connection
    {
        $connection = DB::connection('foodex_legacy');
        $pdo = $connection->getPdo();

        if (defined('PDO::SQLSRV_ATTR_QUERY_TIMEOUT')) {
            $pdo->setAttribute(
                \PDO::SQLSRV_ATTR_QUERY_TIMEOUT,
                max(0, (int) config('database.connections.foodex_legacy.query_timeout', 300)),
            );
        }

        return $connection;
    }

    private function isTransientSqlServerError(Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'sqlstate[08001]')
            || str_contains($message, 'sqlstate[08s01]')
            || str_contains($message, 'sqlstate[hyt00]')
            || str_contains($message, 'wait operation timed out')
            || str_contains($message, 'communication link failure')
            || str_contains($message, 'transport-level error');
    }
}
