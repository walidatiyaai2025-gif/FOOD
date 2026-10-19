<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class LegacyFoodexImportService
{
    private const SOURCE_SYSTEM = 'foodex_sqlserver';

    public function preflight(): array
    {
        $source = DB::connection('foodex_legacy');

        $sourceCounts = [];
        foreach ([
            'wh_Customers',
            'wh_Items',
            'wh_ItemGroups',
            'wh_ItemSubGroups',
            'wh_ItemBarCodes',
            'wh_Stores',
            'wh_Cars',
            'wh_PriceTypes',
            'wh_Suppliers',
            'wh_SalesReps',
            'wh_ItemStockOnHand',
            'wh_TransHeader',
            'wh_TransDetails',
            'wh_CarTransHeader',
            'wh_CarTransDetails',
        ] as $table) {
            $sourceCounts[$table] = (int) $source->table($table)->count();
        }

        $dateRange = $source->table('wh_TransHeader')
            ->selectRaw('MIN(TransDate) AS first_date, MAX(TransDate) AS last_date')
            ->first();

        $stockSnapshot = $source->table('wh_ItemStockOnHand')
            ->selectRaw('MIN(StockDate) AS first_date, MAX(StockDate) AS last_date, COUNT(DISTINCT StoreID) AS stores, COUNT(DISTINCT ItemID) AS items')
            ->first();

        $priceMatrixConsistency = [];
        foreach ([2, 3] as $package) {
            $qtyField = 'Pkg'.$package.'Qty';
            foreach ([2, 3, 4, 5] as $tier) {
                $baseField = 'Pkg1Price'.$tier;
                $packageField = 'Pkg'.$package.'Price'.$tier;
                $result = $source->table('wh_Items')
                    ->selectRaw(
                        "SUM(CASE WHEN COALESCE({$qtyField}, 0) > 0 AND COALESCE({$packageField}, 0) > 0 AND COALESCE({$baseField}, 0) > 0 THEN 1 ELSE 0 END) AS candidate_rows, ".
                        "SUM(CASE WHEN COALESCE({$qtyField}, 0) > 0 AND COALESCE({$packageField}, 0) > 0 AND COALESCE({$baseField}, 0) > 0 ".
                        "AND ABS({$packageField} - ({$baseField} * {$qtyField})) > 0.050 THEN 1 ELSE 0 END) AS mismatch_rows"
                    )
                    ->first();

                $priceMatrixConsistency['pkg'.$package.'_tier'.$tier] = [
                    'candidate_rows' => (int) ($result->candidate_rows ?? 0),
                    'mismatch_rows' => (int) ($result->mismatch_rows ?? 0),
                ];
            }
        }

        $b2bStores = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('store_types.code', 'B2B')
            ->where('stores.is_active', true)
            ->orderBy('stores.id')
            ->get(['stores.id', 'stores.code', 'stores.name']);

        $targetBusinessCounts = $this->targetBusinessCounts();
        $targetBusinessTotal = array_sum($targetBusinessCounts);
        $legacyMastersRerunReady = $this->legacyMastersRerunReady($sourceCounts);

        return [
            'source_connection' => 'foodex_legacy',
            'source_database' => (string) config('database.connections.foodex_legacy.database'),
            'source_counts' => $sourceCounts,
            'transaction_date_range' => [
                'first' => $dateRange?->first_date === null ? null : (int) $dateRange->first_date,
                'last' => $dateRange?->last_date === null ? null : (int) $dateRange->last_date,
            ],
            'stock_snapshot' => [
                'first_date' => $stockSnapshot?->first_date === null ? null : (int) $stockSnapshot->first_date,
                'last_date' => $stockSnapshot?->last_date === null ? null : (int) $stockSnapshot->last_date,
                'stores' => (int) ($stockSnapshot?->stores ?? 0),
                'items' => (int) ($stockSnapshot?->items ?? 0),
                'rows' => $sourceCounts['wh_ItemStockOnHand'],
                'usable_as_cutover_stock' => $stockSnapshot?->last_date !== null
                    && $dateRange?->first_date !== null
                    && (int) $stockSnapshot->last_date >= (int) $dateRange->first_date,
            ],
            'price_matrix_consistency' => $priceMatrixConsistency,
            'target' => [
                'database_driver' => DB::connection()->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME),
                'business_counts' => $targetBusinessCounts,
                'business_total' => $targetBusinessTotal,
                'clean_baseline_ready' => $targetBusinessTotal === 0,
                'legacy_masters_rerun_ready' => $legacyMastersRerunReady,
                'b2b_stores' => $b2bStores->map(static fn ($row): array => [
                    'id' => (int) $row->id,
                    'code' => (string) $row->code,
                    'name' => (string) $row->name,
                ])->all(),
                'tracking_ready' => Schema::hasTable('legacy_import_runs')
                    && Schema::hasTable('legacy_import_mappings')
                    && Schema::hasTable('legacy_import_quarantine'),
            ],
            'rules' => [
                'source_is_read_only' => true,
                'legacy_ids_are_not_target_primary_keys' => true,
                'status_zero_is_not_imported_as_posted_history' => true,
                'van_ids_are_inventory_locations' => true,
                'paired_inventory_documents_are_not_double_counted' => true,
            ],
        ];
    }

    public function importMasters(bool $apply): array
    {
        $preflight = $this->preflight();

        if (! $apply) {
            return [
                'mode' => 'dry-run',
                'phase' => 'masters',
                'planned' => [
                    'warehouses' => $preflight['source_counts']['wh_Stores'],
                    'vans_as_warehouses' => $preflight['source_counts']['wh_Cars'],
                    'root_categories' => $preflight['source_counts']['wh_ItemGroups'],
                    'child_categories' => $preflight['source_counts']['wh_ItemSubGroups'],
                    'products' => $preflight['source_counts']['wh_Items'],
                    'barcodes' => $preflight['source_counts']['wh_ItemBarCodes'],
                    'customers' => $preflight['source_counts']['wh_Customers'],
                    'price_tiers' => $preflight['source_counts']['wh_PriceTypes'],
                    'deferred_stock_snapshots' => $preflight['source_counts']['wh_ItemStockOnHand'],
                ],
                'preflight' => $preflight,
            ];
        }

        $this->assertTrackingTables();

        if (
            ! ($preflight['target']['clean_baseline_ready'] ?? false)
            && ! ($preflight['target']['legacy_masters_rerun_ready'] ?? false)
        ) {
            throw new RuntimeException(
                'Refusing to merge legacy Foodex data into an unowned FOODEX business dataset. '.
                'Use a fresh database baseline, or rerun only against a fully provenance-owned legacy masters dataset.'
            );
        }

        $runId = (string) Str::uuid();
        DB::table('legacy_import_runs')->insert([
            'id' => $runId,
            'source_system' => self::SOURCE_SYSTEM,
            'phase' => 'masters',
            'mode' => 'apply',
            'status' => 'running',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $summary = DB::transaction(function () use ($runId): array {
                $storeId = app(WholesalePrincipal::class)->storeId();
                $catalogId = $this->ensureWholesaleCatalog($storeId);

                $summary = [
                    'run_id' => $runId,
                    'store_id' => $storeId,
                    'catalog_id' => $catalogId,
                    'price_tiers' => $this->importPriceTiers($runId),
                    'warehouses' => $this->importWarehousesAndVans($storeId, $runId),
                    'categories' => $this->importCategories($catalogId, $runId),
                ];

                $unitId = $this->ensureLegacyBaseUnit();
                $summary['products'] = $this->importProducts($storeId, $catalogId, $unitId, $runId);
                $summary['pricing'] = $this->importB2bPrices($storeId, $runId);
                $summary['customers'] = $this->importCustomers($runId, $storeId);

                return $summary;
            }, 3);

            DB::table('legacy_import_runs')->where('id', $runId)->update([
                'status' => 'completed',
                'summary' => $this->json($summary),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'mode' => 'apply',
                'phase' => 'masters',
                'status' => 'completed',
                'summary' => $summary,
            ];
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

    public function backfillCustomer360(bool $apply): array
    {
        $this->assertTrackingTables();

        if (! Schema::hasTable('platform_customers')) {
            throw new RuntimeException('Missing platform_customers. Run php artisan migrate first.');
        }

        $source = DB::connection('foodex_legacy');
        $sourceCount = (int) $source->table('wh_Customers')->count();
        $customerMappings = DB::table('legacy_import_mappings')
            ->where('source_system', self::SOURCE_SYSTEM)
            ->where('source_table', 'wh_Customers')
            ->where('target_table', 'customers')
            ->orderBy('source_key')
            ->get(['source_key', 'target_id']);

        if ($customerMappings->count() !== $sourceCount) {
            throw new RuntimeException(
                'Customer 360 backfill requires a complete provenance-owned legacy customer masters import first.'
            );
        }

        $existingMapped = (int) DB::table('legacy_import_mappings')
            ->where('source_system', self::SOURCE_SYSTEM)
            ->where('source_table', 'wh_Customers')
            ->where('target_table', 'platform_customers')
            ->distinct()
            ->count('target_id');

        if (! $apply) {
            return [
                'mode' => 'dry-run',
                'phase' => 'customer360-backfill',
                'planned' => [
                    'source_customers' => $sourceCount,
                    'customer_mappings' => $customerMappings->count(),
                    'existing_platform_customer_mappings' => $existingMapped,
                    'missing_platform_customer_mappings' => max(0, $sourceCount - $existingMapped),
                ],
            ];
        }

        $sourceRows = $source->table('wh_Customers')
            ->orderBy('ID')
            ->get()
            ->keyBy(static fn (object $row): string => (string) $row->ID);
        $storeId = app(WholesalePrincipal::class)->storeId();
        $runId = (string) Str::uuid();

        DB::table('legacy_import_runs')->insert([
            'id' => $runId,
            'source_system' => self::SOURCE_SYSTEM,
            'phase' => 'customer360-backfill',
            'mode' => 'apply',
            'status' => 'running',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $summary = DB::transaction(function () use (
                $customerMappings,
                $sourceRows,
                $storeId,
                $runId,
            ): array {
                $created = 0;
                $updated = 0;

                foreach ($customerMappings as $mapping) {
                    $sourceKey = (string) $mapping->source_key;
                    $sourceRow = $sourceRows->get($sourceKey);
                    if (! is_object($sourceRow)) {
                        throw new RuntimeException("Legacy customer {$sourceKey} disappeared during Customer 360 backfill.");
                    }

                    $customerId = (int) $mapping->target_id;
                    $customer = DB::table('customers')->where('id', $customerId)->first([
                        'id',
                        'name',
                        'phone',
                        'email',
                    ]);
                    if ($customer === null) {
                        throw new RuntimeException(
                            "Mapped canonical customer {$customerId} is missing for legacy customer {$sourceKey}."
                        );
                    }

                    $accountStatus = DB::table('b2b_accounts')
                        ->where('customer_id', $customerId)
                        ->value('status');
                    $active = strtolower((string) ($accountStatus ?? 'active')) === 'active';

                    $platform = DB::table('platform_customers')
                        ->where('legacy_customer_id', $customerId)
                        ->first(['id']);

                    $payload = [
                        'name' => (string) $customer->name,
                        'phone' => $customer->phone,
                        'email' => $customer->email,
                        'is_active' => $active,
                        'updated_at' => now(),
                    ];

                    if ($platform === null) {
                        $platformId = (int) DB::table('platform_customers')->insertGetId([
                            'user_id' => null,
                            'legacy_customer_id' => $customerId,
                            ...$payload,
                            'origin_channel' => 'b2b',
                            'origin_store_id' => $storeId,
                            'registration_source' => 'import',
                            'registered_at' => null,
                            'created_at' => now(),
                        ]);
                        $created++;
                    } else {
                        $platformId = (int) $platform->id;
                        DB::table('platform_customers')
                            ->where('id', $platformId)
                            ->update($payload);
                        $updated++;
                    }

                    $this->syncMapping(
                        'wh_Customers',
                        $sourceKey,
                        'platform_customers',
                        $platformId,
                        $sourceRow,
                        $runId,
                        [
                            'registration_source' => 'import',
                            'origin_channel' => 'b2b',
                            'origin_store_id' => $storeId,
                            'login_identity' => 'not_materialized',
                            'backfill' => true,
                        ],
                    );
                }

                return [
                    'run_id' => $runId,
                    'store_id' => $storeId,
                    'created' => $created,
                    'updated' => $updated,
                    'mapped' => $customerMappings->count(),
                ];
            }, 3);

            DB::table('legacy_import_runs')->where('id', $runId)->update([
                'status' => 'completed',
                'summary' => $this->json($summary),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'mode' => 'apply',
                'phase' => 'customer360-backfill',
                'status' => 'completed',
                'summary' => $summary,
            ];
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

    public function stockDiagnostics(bool $deep = false, ?callable $progress = null): array
    {
        $source = DB::connection('foodex_legacy');
        $timings = [];

        $stage = static function (string $label, callable $callback) use (&$timings, $progress): mixed {
            if ($progress !== null) {
                $progress($label, 'start', null);
            }

            $startedAt = microtime(true);
            $result = $callback();
            $elapsed = round(microtime(true) - $startedAt, 3);
            $timings[$label] = $elapsed;

            if ($progress !== null) {
                $progress($label, 'done', $elapsed);
            }

            return $result;
        };

        $movementSigns = [
            1 => 1,   // Purchase
            2 => -1,  // Return
            3 => -1,  // Sales
            4 => 1,   // Restore
            5 => -1,  // Damaged
            7 => 1,   // Legacy Foodex: transfer-to / incoming at this StoreId
            8 => -1,  // Legacy Foodex: transfer-from / outgoing from this StoreId
            11 => 1,  // Inventory addition
            12 => -1, // Inventory deficit
            13 => 1,  // To car
            14 => -1, // From car
            15 => -1, // From store
            16 => 1,  // To store
            19 => -1, // Car sale
            20 => 1,  // Car restore
            22 => 1,  // Production output
            23 => -1, // Production materials issue
        ];
        $recognizedTypes = array_keys($movementSigns);

        $typeNames = $stage('transaction_type_names', fn () => $source->table('wh_TransTypes')
            ->get(['ID', 'Name', 'Caption_En', 'Caption_Ar'])
            ->keyBy('ID'));

        $headers = $stage('load_headers', fn () => $source->table('wh_TransHeader')
            ->get([
                'HeaderId',
                'TransType',
                'TransDate',
                'StoreId',
                'CarHeaderId',
                'TransferHeaderID',
                'b_OpeningBal',
                'Status',
            ]));

        $headerAnalysis = $stage('analyze_headers', function () use ($headers, $recognizedTypes): array {
            $typeStats = [];
            $postedHeaderMap = [];
            $usedLocations = [];
            $transferRows = [];
            $linkedCarHeaders = [];
            $postedHeaders = 0;
            $recognizedHeaders = 0;
            $openingBalanceHeaders = 0;
            $carWrapperHeaders = 0;

            foreach ($headers as $row) {
                $type = (int) ($row->TransType ?? 0);
                $date = $row->TransDate === null ? null : (int) $row->TransDate;
                $posted = $row->Status === null || (int) $row->Status !== 0;

                if (! isset($typeStats[$type])) {
                    $typeStats[$type] = [
                        'headers' => 0,
                        'posted_headers' => 0,
                        'first_date' => null,
                        'last_date' => null,
                    ];
                }

                $typeStats[$type]['headers']++;
                if ($date !== null) {
                    $typeStats[$type]['first_date'] = $typeStats[$type]['first_date'] === null
                        ? $date
                        : min($typeStats[$type]['first_date'], $date);
                    $typeStats[$type]['last_date'] = $typeStats[$type]['last_date'] === null
                        ? $date
                        : max($typeStats[$type]['last_date'], $date);
                }

                if (! $posted) {
                    continue;
                }

                $typeStats[$type]['posted_headers']++;
                $postedHeaders++;

                $headerId = (int) $row->HeaderId;
                $storeId = (int) ($row->StoreId ?? 0);
                $postedHeaderMap[$headerId] = [
                    'type' => $type,
                    'store_id' => $storeId,
                    'trans_date' => $date,
                ];

                if (in_array($type, $recognizedTypes, true)) {
                    $recognizedHeaders++;
                    if ($storeId > 0) {
                        $usedLocations[$storeId] = true;
                    }
                }

                if ((int) ($row->b_OpeningBal ?? 0) === 1) {
                    $openingBalanceHeaders++;
                }

                if ($type === 17 || $type === 18) {
                    $carWrapperHeaders++;
                }

                $carHeaderId = (int) ($row->CarHeaderId ?? 0);
                if ($carHeaderId > 0) {
                    $linkedCarHeaders[$carHeaderId] = true;
                }

                if (in_array($type, [7, 8, 13, 14, 15, 16], true)) {
                    $transferRows[] = $row;
                }
            }

            ksort($typeStats);
            ksort($usedLocations);

            return [
                'type_stats' => $typeStats,
                'posted_header_map' => $postedHeaderMap,
                'used_locations' => array_keys($usedLocations),
                'transfer_rows' => collect($transferRows),
                'posted_headers' => $postedHeaders,
                'recognized_headers' => $recognizedHeaders,
                'opening_balance_headers' => $openingBalanceHeaders,
                'car_wrapper_headers_17_18' => $carWrapperHeaders,
                'linked_car_headers' => count($linkedCarHeaders),
            ];
        });

        $locationNames = $stage('source_location_names', function () use ($source): array {
            $names = [];

            foreach ($source->table('wh_Stores')->get(['ID', 'Code', 'Name_Ar', 'Name_En']) as $row) {
                $id = (int) $row->ID;
                $names[$id] = [
                    'source_table' => 'wh_Stores',
                    'code' => $this->legacyText($row->Code ?? null),
                    'name' => $this->name($row->Name_Ar ?? null, $row->Name_En ?? null, 'Store '.$id),
                ];
            }

            foreach ($source->table('wh_Cars')->get(['ID', 'Code', 'Name_Ar', 'Name_En']) as $row) {
                $id = (int) $row->ID;
                $names[$id] = [
                    'source_table' => 'wh_Cars',
                    'code' => $this->legacyText($row->Code ?? null),
                    'name' => $this->name($row->Name_Ar ?? null, $row->Name_En ?? null, 'Car '.$id),
                ];
            }

            return $names;
        });

        $warehouseMappings = $stage('target_mapping_lookup', fn () => [
            'warehouses' => DB::table('legacy_import_mappings')
                ->where('source_system', self::SOURCE_SYSTEM)
                ->where('target_table', 'warehouses')
                ->whereIn('source_table', ['wh_Stores', 'wh_Cars'])
                ->pluck('target_id', 'source_key'),
            'products' => DB::table('legacy_import_mappings')
                ->where('source_system', self::SOURCE_SYSTEM)
                ->where('source_table', 'wh_Items')
                ->where('target_table', 'products')
                ->pluck('source_key')
                ->map(static fn ($id): int => (int) $id)
                ->flip(),
        ]);

        $unmappedLocations = collect($headerAnalysis['used_locations'])
            ->reject(static fn (int $id): bool => $warehouseMappings['warehouses']->has((string) $id)
                || $warehouseMappings['warehouses']->has($id))
            ->values()
            ->all();

        $pairing = $stage('transfer_pairing_local', fn () => [
            $this->legacyPairDiagnosticsFromRows(
                $headerAnalysis['transfer_rows'],
                'CarHeaderId',
                13,
                15,
                'store_to_van',
            ),
            $this->legacyPairDiagnosticsFromRows(
                $headerAnalysis['transfer_rows'],
                'CarHeaderId',
                14,
                16,
                'van_to_store',
            ),
            $this->legacyPairDiagnosticsFromRows(
                $headerAnalysis['transfer_rows'],
                'TransferHeaderID',
                7,
                8,
                'warehouse_transfer',
            ),
        ]);

        $carSourceHeaders = $stage(
            'car_source_count',
            fn () => (int) $source->table('wh_CarTransHeader')->count(),
        );

        $detailStats = [];
        $usedItems = [];
        $postedDetails = null;
        $recognizedDetails = null;
        $quantity = null;
        $rebuildPreview = null;

        if ($deep) {
            $itemConversions = $stage('deep_load_items', fn () => $source->table('wh_Items')
                ->get(['ID', 'Pkg2Qty', 'Pkg3Qty'])
                ->keyBy('ID'));

            $postedDetails = 0;
            $recognizedDetails = 0;
            $usingTransPkgQty1 = 0;
            $usingFallback = 0;
            $negativeRawQty = 0;
            $conversionMismatch = 0;
            $unresolvedPackageConversions = 0;
            $unresolvedPackageSamples = [];
            $positions = [];
            $processedRows = 0;
            $chunkNumber = 0;

            $stage('deep_stream_details', function () use (
                $source,
                $progress,
                $movementSigns,
                $headerAnalysis,
                $itemConversions,
                &$detailStats,
                &$usedItems,
                &$postedDetails,
                &$recognizedDetails,
                &$usingTransPkgQty1,
                &$usingFallback,
                &$negativeRawQty,
                &$conversionMismatch,
                &$unresolvedPackageConversions,
                &$unresolvedPackageSamples,
                &$positions,
                &$processedRows,
                &$chunkNumber,
            ): void {
                $source->table('wh_TransDetails')
                    ->select([
                        'DetailId',
                        'HeaderId',
                        'ItemID',
                        'Package',
                        'Qty',
                        'TransPkgQty1',
                        'Pkg2Qty',
                        'Pkg3Qty',
                        'Status',
                    ])
                    ->orderBy('DetailId')
                    ->chunkById(
                        10000,
                        function ($rows) use (
                            $progress,
                            $movementSigns,
                            $headerAnalysis,
                            $itemConversions,
                            &$detailStats,
                            &$usedItems,
                            &$postedDetails,
                            &$recognizedDetails,
                            &$usingTransPkgQty1,
                            &$usingFallback,
                            &$negativeRawQty,
                            &$conversionMismatch,
                            &$unresolvedPackageConversions,
                            &$unresolvedPackageSamples,
                            &$positions,
                            &$processedRows,
                            &$chunkNumber,
                        ): void {
                            $chunkStartedAt = microtime(true);
                            $chunkNumber++;

                            foreach ($rows as $row) {
                                $processedRows++;

                                if ($row->Status !== null && (int) $row->Status === 0) {
                                    continue;
                                }

                                $headerId = (int) $row->HeaderId;
                                $header = $headerAnalysis['posted_header_map'][$headerId] ?? null;
                                if ($header === null) {
                                    continue;
                                }

                                $postedDetails++;
                                $type = (int) $header['type'];
                                if (! isset($movementSigns[$type])) {
                                    continue;
                                }

                                $recognizedDetails++;
                                $itemId = (int) $row->ItemID;
                                $usedItems[$itemId] = true;

                                if (! isset($detailStats[$type])) {
                                    $detailStats[$type] = [
                                        'details' => 0,
                                        'negative_qty_lines' => 0,
                                        'trans_pkg_qty1_lines' => 0,
                                    ];
                                }

                                $detailStats[$type]['details']++;

                                $rawQty = (float) ($row->Qty ?? 0);
                                if ($rawQty < 0) {
                                    $negativeRawQty++;
                                    $detailStats[$type]['negative_qty_lines']++;
                                }

                                $transPkgQty1 = (float) ($row->TransPkgQty1 ?? 0);
                                if (abs($transPkgQty1) > 0.0000001) {
                                    $usingTransPkgQty1++;
                                    $detailStats[$type]['trans_pkg_qty1_lines']++;
                                } elseif (abs($rawQty) > 0.0000001) {
                                    $usingFallback++;
                                }

                                $item = $itemConversions->get($itemId);
                                $package = (int) ($row->Package ?? 1);
                                $absQty = abs($rawQty);

                                $pkg2 = (float) ($row->Pkg2Qty ?? 0);
                                if (abs($pkg2) <= 0.0000001) {
                                    $pkg2 = (float) ($item->Pkg2Qty ?? 0);
                                }
                                if (abs($pkg2) <= 0.0000001) {
                                    $pkg2 = 1.0;
                                }

                                $pkg3 = (float) ($row->Pkg3Qty ?? 0);
                                if (abs($pkg3) <= 0.0000001) {
                                    $pkg3 = (float) ($item->Pkg3Qty ?? 0);
                                }
                                if (abs($pkg3) <= 0.0000001) {
                                    $pkg3 = 1.0;
                                }

                                $fallbackQuantity = match ($package) {
                                    1 => $absQty,
                                    2 => $absQty * $pkg2,
                                    3 => $absQty * $pkg3,
                                    default => null,
                                };

                                if (abs($transPkgQty1) > 0.0000001) {
                                    $baseQuantity = abs($transPkgQty1);

                                    if (
                                        $fallbackQuantity !== null
                                        && abs($baseQuantity - $fallbackQuantity) > 0.001
                                    ) {
                                        $conversionMismatch++;
                                    }
                                } elseif ($fallbackQuantity !== null) {
                                    $baseQuantity = $fallbackQuantity;
                                } else {
                                    $unresolvedPackageConversions++;

                                    if (count($unresolvedPackageSamples) < 20) {
                                        $unresolvedPackageSamples[] = [
                                            'detail_id' => (int) $row->DetailId,
                                            'header_id' => $headerId,
                                            'item_id' => $itemId,
                                            'package' => $package,
                                            'qty' => $rawQty,
                                        ];
                                    }

                                    continue;
                                }

                                $storeId = (int) $header['store_id'];
                                if ($storeId <= 0) {
                                    continue;
                                }

                                $positionKey = $storeId.':'.$itemId;
                                if (! isset($positions[$positionKey])) {
                                    $positions[$positionKey] = [
                                        'store_id' => $storeId,
                                        'item_id' => $itemId,
                                        'quantity' => 0.0,
                                        'movement_lines' => 0,
                                        'first_date' => null,
                                        'last_date' => null,
                                        'by_type' => [],
                                    ];
                                }

                                $signedQuantity = $movementSigns[$type] * $baseQuantity;
                                $positions[$positionKey]['quantity'] += $signedQuantity;
                                $positions[$positionKey]['movement_lines']++;
                                $positions[$positionKey]['by_type'][$type] = (
                                    $positions[$positionKey]['by_type'][$type] ?? 0.0
                                ) + $signedQuantity;

                                $transDate = $header['trans_date'] ?? null;
                                if ($transDate !== null) {
                                    $positions[$positionKey]['first_date'] = $positions[$positionKey]['first_date'] === null
                                        ? $transDate
                                        : min($positions[$positionKey]['first_date'], $transDate);
                                    $positions[$positionKey]['last_date'] = $positions[$positionKey]['last_date'] === null
                                        ? $transDate
                                        : max($positions[$positionKey]['last_date'], $transDate);
                                }
                            }

                            if ($progress !== null) {
                                $progress(
                                    'deep_details_chunk_'.$chunkNumber.'_rows_'.$processedRows,
                                    'done',
                                    round(microtime(true) - $chunkStartedAt, 3),
                                );
                            }
                        },
                        'DetailId',
                        'DetailId',
                    );
            });

            ksort($usedItems);

            $unmappedItems = collect(array_keys($usedItems))
                ->reject(static fn (int $id): bool => $warehouseMappings['products']->has($id))
                ->values()
                ->all();

            $positivePositions = 0;
            $zeroPositions = 0;
            $negativePositions = 0;
            $negativeSamples = [];
            $negativeStores = [];

            foreach ($positions as $position) {
                $qty = round((float) $position['quantity'], 3);
                if ($qty > 0.0005) {
                    $positivePositions++;
                } elseif ($qty < -0.0005) {
                    $negativePositions++;
                    $storeId = (int) $position['store_id'];

                    if (! isset($negativeStores[$storeId])) {
                        $negativeStores[$storeId] = [
                            'store_id' => $storeId,
                            'name' => $locationNames[$storeId]['name'] ?? 'Unknown location '.$storeId,
                            'source_table' => $locationNames[$storeId]['source_table'] ?? null,
                            'negative_positions' => 0,
                            'total_negative_quantity' => 0.0,
                            'by_type' => [],
                        ];
                    }

                    $negativeStores[$storeId]['negative_positions']++;
                    $negativeStores[$storeId]['total_negative_quantity'] += $qty;

                    foreach ($position['by_type'] as $movementType => $contribution) {
                        $negativeStores[$storeId]['by_type'][$movementType] = (
                            $negativeStores[$storeId]['by_type'][$movementType] ?? 0.0
                        ) + (float) $contribution;
                    }

                    if (count($negativeSamples) < 20) {
                        $negativeSamples[] = [
                            'store_id' => $storeId,
                            'store_name' => $locationNames[$storeId]['name'] ?? 'Unknown location '.$storeId,
                            'item_id' => (int) $position['item_id'],
                            'quantity' => $qty,
                            'movement_lines' => (int) $position['movement_lines'],
                            'first_date' => $position['first_date'],
                            'last_date' => $position['last_date'],
                            'by_type' => collect($position['by_type'])
                                ->map(static fn ($value): float => round((float) $value, 3))
                                ->all(),
                        ];
                    }
                } else {
                    $zeroPositions++;
                }
            }

            $quantity = [
                'lines' => $recognizedDetails,
                'using_trans_pkg_qty1' => $usingTransPkgQty1,
                'using_fallback' => $usingFallback,
                'negative_raw_qty' => $negativeRawQty,
                'transpkg_vs_conversion_mismatch' => $conversionMismatch,
                'unresolved_package_conversions' => $unresolvedPackageConversions,
                'unresolved_package_samples' => $unresolvedPackageSamples,
                'source_detail_rows_scanned' => $processedRows,
            ];

            foreach ($negativeStores as &$negativeStore) {
                $negativeStore['total_negative_quantity'] = round(
                    (float) $negativeStore['total_negative_quantity'],
                    3,
                );
                ksort($negativeStore['by_type']);
                $negativeStore['by_type'] = collect($negativeStore['by_type'])
                    ->map(static fn ($value): float => round((float) $value, 3))
                    ->all();
            }
            unset($negativeStore);

            usort(
                $negativeStores,
                static fn (array $a, array $b): int =>
                    $b['negative_positions'] <=> $a['negative_positions'],
            );

            $rebuildPreview = [
                'positions' => count($positions),
                'positive_positions' => $positivePositions,
                'zero_positions' => $zeroPositions,
                'negative_positions' => $negativePositions,
                'negative_store_summary' => array_values($negativeStores),
                'negative_samples' => $negativeSamples,
            ];
        } else {
            $unmappedItems = [];
        }

        $types = [];
        foreach ($headerAnalysis['type_stats'] as $type => $stats) {
            $typeRow = $typeNames->get($type);
            $details = $detailStats[$type] ?? null;

            $types[] = [
                'type' => (int) $type,
                'name' => $this->name(
                    $typeRow->Caption_Ar ?? null,
                    $typeRow->Caption_En ?? ($typeRow->Name ?? null),
                    'Type '.$type,
                ),
                'headers' => (int) $stats['headers'],
                'posted_headers' => (int) $stats['posted_headers'],
                'details' => $deep ? (int) ($details['details'] ?? 0) : null,
                'negative_qty_lines' => $deep ? (int) ($details['negative_qty_lines'] ?? 0) : null,
                'trans_pkg_qty1_lines' => $deep ? (int) ($details['trans_pkg_qty1_lines'] ?? 0) : null,
                'first_date' => $stats['first_date'],
                'last_date' => $stats['last_date'],
                'recognized_stock_type' => in_array((int) $type, $recognizedTypes, true),
                'stock_sign' => $movementSigns[$type] ?? 0,
            ];
        }

        $unknownPostedTypes = collect($types)
            ->filter(static fn (array $row): bool => $row['posted_headers'] > 0 && ! $row['recognized_stock_type'])
            ->map(static fn (array $row): array => [
                'type' => $row['type'],
                'name' => $row['name'],
                'posted_headers' => $row['posted_headers'],
                'details' => $row['details'] ?? null,
            ])
            ->values()
            ->all();

        return [
            'mode' => $deep ? 'deep' : 'quick',
            'source' => [
                'posted_headers' => (int) $headerAnalysis['posted_headers'],
                'posted_details' => $postedDetails,
                'recognized_stock_headers' => (int) $headerAnalysis['recognized_headers'],
                'recognized_stock_details' => $recognizedDetails,
                'opening_balance_headers' => (int) $headerAnalysis['opening_balance_headers'],
                'car_wrapper_headers_17_18' => (int) $headerAnalysis['car_wrapper_headers_17_18'],
                'linked_car_headers' => (int) $headerAnalysis['linked_car_headers'],
                'car_source_headers' => $carSourceHeaders,
            ],
            'types' => $types,
            'unknown_posted_types' => $unknownPostedTypes,
            'mapping_coverage' => [
                'used_locations' => count($headerAnalysis['used_locations']),
                'unmapped_locations' => $unmappedLocations,
                'used_items' => $deep ? count($usedItems) : null,
                'unmapped_items' => $unmappedItems,
                'mapped_products' => $warehouseMappings['products']->count(),
            ],
            'quantity' => $quantity,
            'pairing' => $pairing,
            'rebuild_preview' => $rebuildPreview,
            'timings_seconds' => $timings,
            'rules' => [
                'source_tables_for_stock' => ['wh_TransHeader', 'wh_TransDetails'],
                'header_diagnostics_are_single_scan' => true,
                'deep_details_are_chunked_without_joins' => true,
                'car_tables_are_audit_only' => true,
                'types_17_18_are_wrappers_not_stock' => true,
                'quantity_prefers_trans_pkg_qty1' => true,
                'fallback_uses_package_conversion' => true,
                'transfer_signs_match_foodex_wh_vw_itemstock' => true,
                'apply_enabled' => false,
                'deep_reconstruction_enabled' => $deep,
            ],
        ];
    }

    private function legacyPairDiagnosticsFromRows(
        \Illuminate\Support\Collection $rows,
        string $linkField,
        int $typeA,
        int $typeB,
        string $label,
    ): array {
        $groups = [];

        foreach ($rows as $row) {
            $type = (int) $row->TransType;
            if ($type !== $typeA && $type !== $typeB) {
                continue;
            }

            $linkId = (int) ($row->{$linkField} ?? 0);
            if ($linkId <= 0) {
                continue;
            }

            if (! isset($groups[$linkId])) {
                $groups[$linkId] = ['a' => 0, 'b' => 0];
            }

            if ($type === $typeA) {
                $groups[$linkId]['a']++;
            } else {
                $groups[$linkId]['b']++;
            }
        }

        $complete = 0;
        $missingA = 0;
        $missingB = 0;
        $duplicates = 0;

        foreach ($groups as $group) {
            $a = (int) $group['a'];
            $b = (int) $group['b'];

            if ($a > 0 && $b > 0) {
                $complete++;
            }
            if ($a === 0) {
                $missingA++;
            }
            if ($b === 0) {
                $missingB++;
            }
            if ($a > 1 || $b > 1) {
                $duplicates++;
            }
        }

        return [
            'label' => $label,
            'link_field' => $linkField,
            'type_a' => $typeA,
            'type_b' => $typeB,
            'groups' => count($groups),
            'complete_groups' => $complete,
            'missing_type_a' => $missingA,
            'missing_type_b' => $missingB,
            'duplicate_side_groups' => $duplicates,
        ];
    }

    public function stockSourceDiagnostics(?callable $progress = null): array
    {
        $source = DB::connection('foodex_legacy');
        $timings = [];

        $stage = static function (string $label, callable $callback) use (&$timings, $progress): mixed {
            if ($progress !== null) {
                $progress($label, 'start', null);
            }

            $startedAt = microtime(true);
            $result = $callback();
            $elapsed = round(microtime(true) - $startedAt, 3);
            $timings[$label] = $elapsed;

            if ($progress !== null) {
                $progress($label, 'done', $elapsed);
            }

            return $result;
        };

        $tableStats = $stage('table_counts', function () use ($source): array {
            $stats = [];

            foreach ([
                'wh_ItemStockOnHand',
                'wh_TransHeaderInventory',
                'wh_TransDetailsInventory',
                'wh_invTransHeader',
                'wh_invTransDetails',
                'wh_OnlineStock',
                'wh_VisitItemsStock',
            ] as $table) {
                try {
                    $stats[$table] = (int) $source->table($table)->count();
                } catch (Throwable) {
                    $stats[$table] = null;
                }
            }

            return $stats;
        });

        $snapshot = $stage('stock_snapshot_summary', function () use ($source, $tableStats): ?object {
            if (($tableStats['wh_ItemStockOnHand'] ?? 0) <= 0) {
                return null;
            }

            return $source->table('wh_ItemStockOnHand')
                ->selectRaw(
                    'COUNT(*) AS rows_count, '.
                    'COUNT(DISTINCT StoreID) AS stores, '.
                    'COUNT(DISTINCT ItemID) AS items, '.
                    'MIN(StockDate) AS first_date, '.
                    'MAX(StockDate) AS last_date, '.
                    'SUM(CASE WHEN CurrentStock < 0 THEN 1 ELSE 0 END) AS negative_rows, '.
                    'SUM(CASE WHEN CurrentStock = 0 THEN 1 ELSE 0 END) AS zero_rows, '.
                    'SUM(CASE WHEN CurrentStock > 0 THEN 1 ELSE 0 END) AS positive_rows'
                )
                ->first();
        });

        $prevYear = $stage('previous_year_flags', fn () => $source->table('wh_TransHeader')
            ->whereRaw('COALESCE(Status, 1) <> 0')
            ->selectRaw(
                'SUM(CASE WHEN COALESCE(PrevYear, 0) = 1 THEN 1 ELSE 0 END) AS prev_year_headers, '.
                'SUM(CASE WHEN COALESCE(PrevYear, 0) = 0 THEN 1 ELSE 0 END) AS current_year_headers, '.
                'SUM(CASE WHEN TransDate = 20251231 THEN 1 ELSE 0 END) AS headers_20251231, '.
                'SUM(CASE WHEN TransDate = 20251231 AND TransType = 11 THEN 1 ELSE 0 END) AS type11_20251231, '.
                'SUM(CASE WHEN TransDate = 20251231 AND COALESCE(PrevYear, 0) = 1 THEN 1 ELSE 0 END) AS prev_year_20251231'
            )
            ->first());

        $namedObjects = $stage('named_stock_objects', fn () => $source->select(<<<'SQL'
SELECT TOP 200
    s.name AS schema_name,
    o.name AS object_name,
    o.type_desc
FROM sys.objects AS o
INNER JOIN sys.schemas AS s ON s.schema_id = o.schema_id
WHERE o.type IN ('P', 'V', 'FN', 'IF', 'TF')
  AND (
      LOWER(o.name) LIKE '%stock%'
      OR LOWER(o.name) LIKE '%invent%'
      OR LOWER(o.name) LIKE '%onhand%'
  )
ORDER BY s.name, o.name
SQL));

        $dependencyRows = $stage('stock_object_dependencies', fn () => $source->select(<<<'SQL'
SELECT DISTINCT TOP 200
    OBJECT_SCHEMA_NAME(d.referencing_id) AS schema_name,
    OBJECT_NAME(d.referencing_id) AS object_name,
    o.type_desc,
    d.referenced_schema_name,
    d.referenced_entity_name
FROM sys.sql_expression_dependencies AS d
INNER JOIN sys.objects AS o ON o.object_id = d.referencing_id
WHERE o.type IN ('P', 'V', 'FN', 'IF', 'TF')
  AND LOWER(COALESCE(d.referenced_entity_name, '')) IN (
      'wh_itemstockonhand',
      'wh_transheader',
      'wh_transdetails',
      'wh_cartransheader',
      'wh_cartransdetails'
  )
ORDER BY
    OBJECT_SCHEMA_NAME(d.referencing_id),
    OBJECT_NAME(d.referencing_id),
    d.referenced_entity_name
SQL));

        $objects = collect($namedObjects)
            ->map(static fn ($row): array => [
                'schema' => (string) $row->schema_name,
                'name' => (string) $row->object_name,
                'type' => (string) $row->type_desc,
                'reason' => 'name_match',
                'references' => [],
            ])
            ->keyBy(static fn (array $row): string => strtolower($row['schema'].'.'.$row['name']));

        foreach ($dependencyRows as $row) {
            $schema = (string) ($row->schema_name ?? 'dbo');
            $name = (string) $row->object_name;
            $key = strtolower($schema.'.'.$name);

            if (! $objects->has($key)) {
                $objects->put($key, [
                    'schema' => $schema,
                    'name' => $name,
                    'type' => (string) $row->type_desc,
                    'reason' => 'dependency',
                    'references' => [],
                ]);
            }

            $item = $objects->get($key);
            $reference = trim(
                ((string) ($row->referenced_schema_name ?? '')).'.'.
                ((string) ($row->referenced_entity_name ?? '')),
                '.',
            );

            if ($reference !== '' && ! in_array($reference, $item['references'], true)) {
                $item['references'][] = $reference;
            }

            if ($item['reason'] === 'name_match') {
                $item['reason'] = 'name_match+dependency';
            }

            $objects->put($key, $item);
        }

        return [
            'table_counts' => $tableStats,
            'item_stock_snapshot' => $snapshot === null ? null : [
                'rows' => (int) $snapshot->rows_count,
                'stores' => (int) $snapshot->stores,
                'items' => (int) $snapshot->items,
                'first_date' => $snapshot->first_date === null ? null : (int) $snapshot->first_date,
                'last_date' => $snapshot->last_date === null ? null : (int) $snapshot->last_date,
                'negative_rows' => (int) $snapshot->negative_rows,
                'zero_rows' => (int) $snapshot->zero_rows,
                'positive_rows' => (int) $snapshot->positive_rows,
            ],
            'previous_year_flags' => [
                'prev_year_headers' => (int) ($prevYear->prev_year_headers ?? 0),
                'current_year_headers' => (int) ($prevYear->current_year_headers ?? 0),
                'headers_20251231' => (int) ($prevYear->headers_20251231 ?? 0),
                'type11_20251231' => (int) ($prevYear->type11_20251231 ?? 0),
                'prev_year_20251231' => (int) ($prevYear->prev_year_20251231 ?? 0),
            ],
            'stock_related_modules' => $objects->values()->all(),
            'timings_seconds' => $timings,
            'rules' => [
                'module_discovery_uses_dependency_metadata' => true,
                'module_definition_full_text_scan' => false,
                'read_only' => true,
            ],
        ];
    }

    public function stockRoutineDiagnostics(?callable $progress = null): array
    {
        $source = DB::connection('foodex_legacy');
        $timings = [];

        $stage = static function (string $label, callable $callback) use (&$timings, $progress): mixed {
            if ($progress !== null) {
                $progress($label, 'start', null);
            }

            $startedAt = microtime(true);
            $result = $callback();
            $elapsed = round(microtime(true) - $startedAt, 3);
            $timings[$label] = $elapsed;

            if ($progress !== null) {
                $progress($label, 'done', $elapsed);
            }

            return $result;
        };

        $candidateNames = [
            'Fn_Wh_GetItemStock',
            'wh_GetItemStock',
            'sp_GetItemStock',
            'GetItemStockAPI',
            'fn_wh_getstock',
            'fn_wh_getstockinbranch',
            'fn_wh_getStockstore',
            'wh_getStock',
            'Vw_wh_ItemCurrentStock',
            'wh_vw_ItemStock2Now',
            'sp_vw_ItemStock2Now',
            'wh_vw_itemstock',
            'ProductStock',
            'Vw_ItemsServerStock',
            'MobileItemStockOnCar',
            'wh_vw_ItemStock2NowCAR',
            'wh_getStockcAR',
            'wh_getStockCAR2',
        ];

        $objects = $stage('resolve_stock_objects', function () use ($source, $candidateNames) {
            return $source->table('sys.objects as o')
                ->join('sys.schemas as s', 's.schema_id', '=', 'o.schema_id')
                ->whereIn('o.name', $candidateNames)
                ->whereIn('o.type', ['P', 'V', 'FN', 'IF', 'TF'])
                ->orderBy('o.name')
                ->get([
                    'o.object_id',
                    'o.name',
                    'o.type',
                    'o.type_desc',
                    's.name as schema_name',
                ]);
        });

        $results = [];

        foreach ($objects as $object) {
            $schema = (string) $object->schema_name;
            $name = (string) $object->name;
            $objectId = (int) $object->object_id;

            $parameters = $stage(
                'params_'.$name,
                fn () => $source->table('sys.parameters as p')
                    ->leftJoin('sys.types as t', 't.user_type_id', '=', 'p.user_type_id')
                    ->where('p.object_id', $objectId)
                    ->orderBy('p.parameter_id')
                    ->get([
                        'p.parameter_id',
                        'p.name',
                        't.name as type_name',
                        'p.max_length',
                        'p.precision',
                        'p.scale',
                        'p.is_output',
                    ])
                    ->map(static fn ($row): array => [
                        'id' => (int) $row->parameter_id,
                        'name' => (string) ($row->name ?? ''),
                        'type' => (string) ($row->type_name ?? ''),
                        'max_length' => (int) ($row->max_length ?? 0),
                        'precision' => (int) ($row->precision ?? 0),
                        'scale' => (int) ($row->scale ?? 0),
                        'is_output' => (bool) $row->is_output,
                    ])
                    ->values()
                    ->all(),
            );

            $columns = [];
            if ((string) $object->type === 'V') {
                $columns = $stage(
                    'columns_'.$name,
                    fn () => $source->table('sys.columns as c')
                        ->leftJoin('sys.types as t', 't.user_type_id', '=', 'c.user_type_id')
                        ->where('c.object_id', $objectId)
                        ->orderBy('c.column_id')
                        ->get([
                            'c.column_id',
                            'c.name',
                            't.name as type_name',
                        ])
                        ->map(static fn ($row): array => [
                            'id' => (int) $row->column_id,
                            'name' => (string) $row->name,
                            'type' => (string) ($row->type_name ?? ''),
                        ])
                        ->values()
                        ->all(),
                );
            }

            $definitionRow = $stage(
                'definition_'.$name,
                fn () => $source->table('sys.sql_modules')
                    ->where('object_id', $objectId)
                    ->first(['definition']),
            );

            $definition = trim((string) ($definitionRow->definition ?? ''));

            $signals = [
                'wh_TransHeader' => stripos($definition, 'wh_TransHeader') !== false,
                'wh_TransDetails' => stripos($definition, 'wh_TransDetails') !== false,
                'wh_CarTransHeader' => stripos($definition, 'wh_CarTransHeader') !== false,
                'wh_CarTransDetails' => stripos($definition, 'wh_CarTransDetails') !== false,
                'wh_ItemStockOnHand' => stripos($definition, 'wh_ItemStockOnHand') !== false,
                'TransPkgQty1' => stripos($definition, 'TransPkgQty1') !== false,
                'CurrentStock' => stripos($definition, 'CurrentStock') !== false,
                'PrevYear' => stripos($definition, 'PrevYear') !== false,
                'b_OpeningBal' => stripos($definition, 'b_OpeningBal') !== false,
                'TransType' => stripos($definition, 'TransType') !== false,
                'StoreId' => stripos($definition, 'StoreId') !== false
                    || stripos($definition, 'StoreID') !== false,
                'CarId' => stripos($definition, 'CarId') !== false
                    || stripos($definition, 'CarID') !== false,
            ];

            preg_match_all('/\\b(?:TransType\\s*(?:=|IN\\s*\\()\\s*)?([0-9]{1,2})\\b/i', $definition, $typeMatches);
            $transactionTypes = collect($typeMatches[1] ?? [])
                ->map(static fn ($value): int => (int) $value)
                ->unique()
                ->sort()
                ->values()
                ->all();

            $results[] = [
                'schema' => $schema,
                'name' => $name,
                'type' => (string) $object->type_desc,
                'parameters' => $parameters,
                'columns' => $columns,
                'definition_length' => strlen($definition),
                'signals' => $signals,
                'transaction_types_detected' => $transactionTypes,
                'definition' => $definition,
            ];
        }

        return [
            'objects' => $results,
            'timings_seconds' => $timings,
            'read_only' => true,
        ];
    }

    public function importStock(bool $apply, ?callable $progress = null): array
    {
        $this->assertTrackingTables();

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

        $source = DB::connection('foodex_legacy');

        $movementSigns = [
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

        $posCounts = $stage('validate_pos_stock_source', fn () => [
            'headers' => (int) $source->table('wh_PosHeader')->count(),
            'details' => (int) $source->table('wh_PosDetails')->count(),
        ]);

        if ($posCounts['headers'] !== 0 || $posCounts['details'] !== 0) {
            throw new RuntimeException(
                'Stock import refused: legacy POS stock rows exist and must be included in the canonical stock reconstruction.'
            );
        }

        $eligibleItems = $stage('load_stock_eligible_items', fn () => $source->table('wh_Items')
            ->where('Status', 1)
            ->where('ItemType', '<>', 4)
            ->pluck('ID')
            ->map(static fn ($id): int => (int) $id)
            ->flip());

        $locationMeta = $stage('load_stock_location_metadata', function () use ($source): array {
            $meta = [];

            foreach ($source->table('wh_Stores')->get(['ID', 'Code']) as $row) {
                $meta[(int) $row->ID] = [
                    'store_code' => $row->Code ?? null,
                    'is_car_trans' => 0,
                ];
            }

            foreach ($source->table('wh_Cars')->get(['ID', 'Code']) as $row) {
                $meta[(int) $row->ID] = [
                    'store_code' => $row->Code ?? null,
                    'is_car_trans' => 1,
                ];
            }

            return $meta;
        });

        $headerMap = $stage('load_stock_headers', function () use ($source, $movementSigns): array {
            $map = [];

            foreach ($source->table('wh_TransHeader')
                ->where('Status', 1)
                ->whereIn('TransType', array_keys($movementSigns))
                ->get(['HeaderId', 'TransType', 'StoreId']) as $row) {
                $map[(int) $row->HeaderId] = [
                    'type' => (int) $row->TransType,
                    'store_id' => (int) ($row->StoreId ?? 0),
                ];
            }

            return $map;
        });

        $positions = [];
        $invalidRows = [];
        $sourceDetailRows = 0;
        $acceptedDetailRows = 0;
        $chunkNumber = 0;

        $stage('stream_canonical_stock_details', function () use (
            $source,
            $progress,
            $movementSigns,
            $eligibleItems,
            $locationMeta,
            $headerMap,
            &$positions,
            &$invalidRows,
            &$sourceDetailRows,
            &$acceptedDetailRows,
            &$chunkNumber,
        ): void {
            $source->table('wh_TransDetails')
                ->select(['DetailId', 'HeaderId', 'ItemID', 'TransPkgQty1', 'Status'])
                ->orderBy('DetailId')
                ->chunkById(
                    10000,
                    function ($rows) use (
                        $progress,
                        $movementSigns,
                        $eligibleItems,
                        $locationMeta,
                        $headerMap,
                        &$positions,
                        &$invalidRows,
                        &$sourceDetailRows,
                        &$acceptedDetailRows,
                        &$chunkNumber,
                    ): void {
                        $chunkStartedAt = microtime(true);
                        $chunkNumber++;

                        foreach ($rows as $row) {
                            $sourceDetailRows++;

                            if ((int) ($row->Status ?? 0) !== 1) {
                                continue;
                            }

                            $header = $headerMap[(int) $row->HeaderId] ?? null;
                            if ($header === null) {
                                continue;
                            }

                            $itemId = (int) ($row->ItemID ?? 0);
                            $storeId = (int) ($header['store_id'] ?? 0);

                            if ($itemId <= 0 || $storeId <= 0) {
                                if (count($invalidRows) < 20) {
                                    $invalidRows[] = [
                                        'detail_id' => (int) ($row->DetailId ?? 0),
                                        'item_id' => $itemId,
                                        'store_id' => $storeId,
                                    ];
                                }

                                continue;
                            }

                            if (! $eligibleItems->has($itemId)) {
                                continue;
                            }

                            $acceptedDetailRows++;
                            $type = (int) $header['type'];
                            $signedQuantity = $movementSigns[$type] * (float) ($row->TransPkgQty1 ?? 0);
                            $key = $storeId.':'.$itemId;

                            if (! isset($positions[$key])) {
                                $positions[$key] = [
                                    'store_id' => $storeId,
                                    'item_id' => $itemId,
                                    'quantity' => 0.0,
                                    'store_code' => $locationMeta[$storeId]['store_code'] ?? null,
                                    'is_car_trans' => $locationMeta[$storeId]['is_car_trans'] ?? null,
                                ];
                            }

                            $positions[$key]['quantity'] += $signedQuantity;
                        }

                        if ($progress !== null) {
                            $progress(
                                'stock_details_chunk_'.$chunkNumber.'_rows_'.$sourceDetailRows,
                                'done',
                                round(microtime(true) - $chunkStartedAt, 3),
                            );
                        }
                    },
                    'DetailId',
                    'DetailId',
                );
        });

        foreach ($positions as &$position) {
            $position['quantity'] = round((float) $position['quantity'], 3);
        }
        unset($position);

        $warehouseMappings = DB::table('legacy_import_mappings')
            ->where('source_system', self::SOURCE_SYSTEM)
            ->where('target_table', 'warehouses')
            ->whereIn('source_table', ['wh_Stores', 'wh_Cars'])
            ->get(['source_table', 'source_key', 'target_id']);

        $warehouseMap = [];
        foreach ($warehouseMappings as $mapping) {
            $warehouseMap[(int) $mapping->source_key] = (int) $mapping->target_id;
        }

        $productMap = DB::table('legacy_import_mappings')
            ->where('source_system', self::SOURCE_SYSTEM)
            ->where('source_table', 'wh_Items')
            ->where('target_table', 'products')
            ->get(['source_key', 'target_id'])
            ->mapWithKeys(static fn ($row): array => [(int) $row->source_key => (int) $row->target_id])
            ->all();

        $positive = [];
        $zero = 0;
        $negative = [];
        $unmappedLocations = [];
        $unmappedItems = [];

        foreach ($positions as $key => $position) {
            $quantity = (float) $position['quantity'];

            if (! isset($warehouseMap[$position['store_id']])) {
                $unmappedLocations[$position['store_id']] = true;
            }
            if (! isset($productMap[$position['item_id']])) {
                $unmappedItems[$position['item_id']] = true;
            }

            if ($quantity > 0.0005) {
                $positive[$key] = $position;
            } elseif ($quantity < -0.0005) {
                $negative[$key] = $position;
            } else {
                $zero++;
            }
        }

        $existingInventoryCount = (int) DB::table('inventories')->count();
        $mappedLegacyInventoryCount = (int) DB::table('legacy_import_mappings')
            ->where('source_system', self::SOURCE_SYSTEM)
            ->where('source_table', 'wh_vw_ItemStock2Now')
            ->where('target_table', 'inventories')
            ->distinct()
            ->count('target_id');

        $existingMovementCount = (int) DB::table('stock_movements')->count();
        $legacyMovementCount = (int) DB::table('stock_movements')
            ->where('type', 'legacy_opening_balance')
            ->where('reference_type', 'legacy_foodex_stock_baseline')
            ->count();

        $planned = [
            'source_detail_rows_scanned' => $sourceDetailRows,
            'accepted_stock_detail_rows' => $acceptedDetailRows,
            'pos_headers' => $posCounts['headers'],
            'pos_details' => $posCounts['details'],
            'aggregated_positions' => count($positions),
            'positive_positions' => count($positive),
            'zero_positions' => $zero,
            'negative_positions' => count($negative),
            'invalid_source_rows' => count($invalidRows),
            'unmapped_locations' => count($unmappedLocations),
            'unmapped_items' => count($unmappedItems),
            'existing_inventories' => $existingInventoryCount,
            'existing_stock_movements' => $existingMovementCount,
        ];

        if (! $apply) {
            return [
                'mode' => 'dry-run',
                'phase' => 'stock',
                'planned' => $planned,
                'samples' => [
                    'negative' => array_slice(array_values($negative), 0, 20),
                    'invalid' => $invalidRows,
                    'unmapped_locations' => array_slice(array_keys($unmappedLocations), 0, 30),
                    'unmapped_items' => array_slice(array_keys($unmappedItems), 0, 30),
                ],
                'rules' => [
                    'authority' => 'dbo.wh_vw_ItemStock2Now canonical semantics streamed from wh_TransHeader/wh_TransDetails',
                    'only_positive_positions_are_materialized' => true,
                    'zero_positions_are_not_materialized' => true,
                    'negative_positions_block_apply' => true,
                    'foreign_target_stock_blocks_apply' => true,
                ],
            ];
        }

        if ($invalidRows !== []) {
            throw new RuntimeException(
                'Stock apply refused: canonical Foodex stock reconstruction returned invalid item/store identifiers.'
            );
        }

        if ($negative !== []) {
            throw new RuntimeException(
                'Stock apply refused: canonical Foodex stock reconstruction contains negative current-stock positions.'
            );
        }

        if ($unmappedLocations !== [] || $unmappedItems !== []) {
            throw new RuntimeException(
                'Stock apply refused: canonical Foodex stock contains unmapped locations or products.'
            );
        }

        $safeEmptyTarget = $existingInventoryCount === 0 && $existingMovementCount === 0;
        $safeLegacyRerun = $existingInventoryCount === $mappedLegacyInventoryCount
            && $existingMovementCount === $legacyMovementCount
            && $existingInventoryCount === $existingMovementCount;

        if (! $safeEmptyTarget && ! $safeLegacyRerun) {
            throw new RuntimeException(
                'Stock apply refused: target inventory contains stock not wholly owned by the legacy Foodex baseline.'
            );
        }

        $runId = (string) Str::uuid();
        DB::table('legacy_import_runs')->insert([
            'id' => $runId,
            'source_system' => self::SOURCE_SYSTEM,
            'phase' => 'stock',
            'mode' => 'apply',
            'status' => 'running',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $summary = DB::transaction(function () use (
                $positive,
                $warehouseMap,
                $productMap,
                $runId,
                $planned,
            ): array {
                $created = 0;
                $updated = 0;
                $movementCreated = 0;
                $movementUpdated = 0;
                $sourceKeys = [];

                foreach ($positive as $sourceKey => $position) {
                    $warehouseId = $warehouseMap[$position['store_id']];
                    $productId = $productMap[$position['item_id']];
                    $quantity = round((float) $position['quantity'], 3);
                    $sourceKeys[] = $sourceKey;

                    $inventory = DB::table('inventories')
                        ->where('warehouse_id', $warehouseId)
                        ->where('product_id', $productId)
                        ->first(['id']);

                    if ($inventory === null) {
                        $inventoryId = (int) DB::table('inventories')->insertGetId([
                            'warehouse_id' => $warehouseId,
                            'product_id' => $productId,
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

                    $storeId = (int) DB::table('warehouses')
                        ->where('id', $warehouseId)
                        ->value('store_id');

                    $existingMovement = DB::table('stock_movements')
                        ->where('inventory_id', $inventoryId)
                        ->where('type', 'legacy_opening_balance')
                        ->where('reference_type', 'legacy_foodex_stock_baseline')
                        ->where('reference_id', $inventoryId)
                        ->first(['id']);

                    $movementPayload = [
                        'store_id' => $storeId,
                        'user_id' => null,
                        'quantity' => $quantity,
                        'reason' => 'Legacy Foodex cutover opening balance using dbo.wh_vw_ItemStock2Now canonical semantics',
                        'notes' => $this->json([
                            'source_system' => self::SOURCE_SYSTEM,
                            'source_view' => 'dbo.wh_vw_ItemStock2Now',
                            'source_read_strategy' => 'streamed_wh_TransHeader_wh_TransDetails',
                            'legacy_store_id' => $position['store_id'],
                            'legacy_item_id' => $position['item_id'],
                            'store_code' => $position['store_code'],
                            'is_car_trans' => $position['is_car_trans'],
                            'run_id' => $runId,
                        ]),
                        'updated_at' => now(),
                    ];

                    if ($existingMovement === null) {
                        DB::table('stock_movements')->insert([
                            'inventory_id' => $inventoryId,
                            'type' => 'legacy_opening_balance',
                            'reference_type' => 'legacy_foodex_stock_baseline',
                            'reference_id' => $inventoryId,
                            ...$movementPayload,
                            'created_at' => now(),
                        ]);
                        $movementCreated++;
                    } else {
                        DB::table('stock_movements')
                            ->where('id', $existingMovement->id)
                            ->update($movementPayload);
                        $movementUpdated++;
                    }

                    $sourceRow = (object) [
                        'StoreId' => $position['store_id'],
                        'ItemID' => $position['item_id'],
                        'CurrentStock' => $quantity,
                        'StoreCode' => $position['store_code'],
                        'IsCarTrans' => $position['is_car_trans'],
                    ];

                    $this->syncMapping(
                        'wh_vw_ItemStock2Now',
                        $sourceKey,
                        'inventories',
                        $inventoryId,
                        $sourceRow,
                        $runId,
                        [
                            'authority' => 'dbo.wh_vw_ItemStock2Now canonical semantics',
                            'source_read_strategy' => 'streamed_wh_TransHeader_wh_TransDetails',
                            'quantity' => $quantity,
                        ],
                    );
                }

                $staleMappings = DB::table('legacy_import_mappings')
                    ->where('source_system', self::SOURCE_SYSTEM)
                    ->where('source_table', 'wh_vw_ItemStock2Now')
                    ->where('target_table', 'inventories')
                    ->when(
                        $sourceKeys !== [],
                        fn ($query) => $query->whereNotIn('source_key', $sourceKeys),
                    )
                    ->get(['id', 'target_id']);

                $zeroed = 0;
                foreach ($staleMappings as $mapping) {
                    $inventoryId = (int) $mapping->target_id;
                    DB::table('inventories')->where('id', $inventoryId)->update([
                        'quantity' => 0,
                        'reserved_quantity' => 0,
                        'updated_at' => now(),
                    ]);
                    DB::table('stock_movements')
                        ->where('inventory_id', $inventoryId)
                        ->where('type', 'legacy_opening_balance')
                        ->where('reference_type', 'legacy_foodex_stock_baseline')
                        ->where('reference_id', $inventoryId)
                        ->update([
                            'quantity' => 0,
                            'reason' => 'Legacy Foodex cutover opening balance reset to zero on baseline rerun',
                            'updated_at' => now(),
                        ]);
                    $zeroed++;
                }

                $targetInventoryCount = (int) DB::table('inventories')->count();
                $targetPositiveCount = (int) DB::table('inventories')
                    ->where('quantity', '>', 0)
                    ->count();
                $targetNegativeCount = (int) DB::table('inventories')
                    ->where('quantity', '<', 0)
                    ->count();

                return [
                    'run_id' => $runId,
                    'authority' => 'dbo.wh_vw_ItemStock2Now canonical semantics streamed from wh_TransHeader/wh_TransDetails',
                    'planned' => $planned,
                    'inventories' => [
                        'created' => $created,
                        'updated' => $updated,
                        'zeroed_on_rerun' => $zeroed,
                        'target_total' => $targetInventoryCount,
                        'target_positive' => $targetPositiveCount,
                        'target_negative' => $targetNegativeCount,
                    ],
                    'stock_movements' => [
                        'created' => $movementCreated,
                        'updated' => $movementUpdated,
                    ],
                ];
            }, 3);

            if (($summary['inventories']['target_negative'] ?? 0) !== 0) {
                throw new RuntimeException('Stock apply validation failed: target contains negative inventory.');
            }

            DB::table('legacy_import_runs')->where('id', $runId)->update([
                'status' => 'completed',
                'summary' => $this->json($summary),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'mode' => 'apply',
                'phase' => 'stock',
                'status' => 'completed',
                'summary' => $summary,
            ];
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

    public function importSalesHistory(bool $apply, ?callable $progress = null): array
    {
        $this->assertTrackingTables();

        foreach ([
            'orders',
            'order_items',
            'order_status_history',
            'invoices',
            'invoice_items',
            'payments',
            'customer_account_ledger_entries',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Missing {$table}. Run php artisan migrate first.");
            }
        }

        if ($apply) {
            $earlyTargetCounts = [];
            foreach ([
                'orders',
                'order_items',
                'order_status_history',
                'invoices',
                'invoice_items',
                'payments',
                'customer_account_ledger_entries',
            ] as $table) {
                $earlyTargetCounts[$table] = (int) DB::table($table)->count();
            }

            if (array_sum($earlyTargetCounts) !== 0) {
                throw new RuntimeException(
                    'Sales history apply refused before source scan: target commerce/ledger tables are not empty. '.
                    json_encode($earlyTargetCounts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                );
            }

            DB::table('legacy_import_runs')
                ->where('source_system', self::SOURCE_SYSTEM)
                ->where('phase', 'sales-history')
                ->where('status', 'running')
                ->update([
                    'status' => 'aborted',
                    'error' => 'Previous sales-history process terminated before completion; target commerce was clean on retry.',
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);
        }

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

        $source = DB::connection('foodex_legacy');
        $salesTypes = [3, 19];
        $returnTypes = [4, 20];

        $headerColumns = [
            'HeaderId',
            'TransType',
            'TransDate',
            'BranchID',
            'CustomerId',
            'StoreId',
            'SalesRepId',
            'CarHeaderId',
            'SalesHeaderId',
            'Code',
            'DocTransNo',
            'ReferenceNo',
            'HdrDiscount',
            'HdrAddition',
            'HdrTax',
            'PriceType',
            'PayType',
            'Paid',
            'PaidCash',
            'PaidByVisa',
            'Total',
            'NetTotal',
            'Balance',
            'CurrencyId',
            'CurrencyRate',
            'TotalCurrency',
            'Periodterm',
            'Notes',
            'Status',
        ];

        $headerCursor = static fn () => $source->table('wh_TransHeader')
            ->select($headerColumns)
            ->where('Status', 1)
            ->whereIn('TransType', [...$salesTypes, ...$returnTypes])
            ->orderBy('HeaderId')
            ->cursor();

        $salesHeaderIds = [];
        $salesHeaderTotals = [];
        $salesHeaderFinancials = [];
        $returnHeaders = [];
        $headerTypes = [];
        $typeCounts = [];

        $stage('stream_sales_return_header_index', function () use (
            $headerCursor,
            $salesTypes,
            $returnTypes,
            &$salesHeaderIds,
            &$salesHeaderTotals,
            &$salesHeaderFinancials,
            &$returnHeaders,
            &$headerTypes,
            &$typeCounts,
        ): void {
            foreach ($headerCursor() as $header) {
                $headerId = (int) $header->HeaderId;
                $type = (int) $header->TransType;
                $headerTypes[$headerId] = $type;
                $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;

                if (in_array($type, $salesTypes, true)) {
                    $salesHeaderIds[] = $headerId;
                    $salesHeaderTotals[$headerId] = round((float) ($header->Total ?? 0), 3);
                    $salesHeaderFinancials[$headerId] = [
                        'total' => round((float) ($header->Total ?? 0), 3),
                        'net_total' => round((float) ($header->NetTotal ?? 0), 3),
                        'paid' => round((float) ($header->Paid ?? 0), 3),
                        'balance' => round((float) ($header->Balance ?? 0), 3),
                    ];
                } elseif (in_array($type, $returnTypes, true)) {
                    $returnHeaders[$headerId] = $header;
                }
            }
        });

        ksort($typeCounts);

        $maps = $stage('load_sales_history_mappings', function (): array {
            $mapping = static function (string $sourceTable, string $targetTable, ?array $sourceTables = null): array {
                $query = DB::table('legacy_import_mappings')
                    ->where('source_system', self::SOURCE_SYSTEM)
                    ->where('target_table', $targetTable);

                if ($sourceTables !== null) {
                    $query->whereIn('source_table', $sourceTables);
                } else {
                    $query->where('source_table', $sourceTable);
                }

                $result = [];
                foreach ($query->get(['source_key', 'target_id']) as $row) {
                    $result[(int) $row->source_key] = (int) $row->target_id;
                }

                return $result;
            };

            $customers = $mapping('wh_Customers', 'customers');
            $b2bCustomers = $mapping('wh_Customers', 'b2b_customers');
            $platformCustomers = $mapping('wh_Customers', 'platform_customers');
            $b2bAccounts = $mapping('wh_Customers', 'b2b_accounts');
            $warehouses = $mapping('', 'warehouses', ['wh_Stores', 'wh_Cars']);
            $products = $mapping('wh_Items', 'products');
            $priceTiers = $mapping('wh_PriceTypes', 'b2b_price_tiers');

            $warehouseRows = $warehouses === []
                ? collect()
                : DB::table('warehouses')
                    ->whereIn('id', array_values($warehouses))
                    ->get(['id', 'store_id', 'code', 'name']);
            $warehouseMeta = [];
            foreach ($warehouseRows as $row) {
                $warehouseMeta[(int) $row->id] = [
                    'store_id' => $row->store_id === null ? null : (int) $row->store_id,
                    'code' => (string) $row->code,
                    'name' => (string) $row->name,
                ];
            }

            $productRows = $products === []
                ? collect()
                : DB::table('products')
                    ->whereIn('id', array_values($products))
                    ->get(['id', 'sku', 'name']);
            $productMetaByTarget = [];
            foreach ($productRows as $row) {
                $productMetaByTarget[(int) $row->id] = [
                    'sku' => (string) $row->sku,
                    'name' => (string) $row->name,
                ];
            }

            $customerRows = $customers === []
                ? collect()
                : DB::table('customers')
                    ->whereIn('id', array_values($customers))
                    ->get(['id', 'name', 'phone', 'email']);
            $customerMetaByTarget = [];
            foreach ($customerRows as $row) {
                $customerMetaByTarget[(int) $row->id] = [
                    'name' => (string) $row->name,
                    'phone' => $row->phone,
                    'email' => $row->email,
                ];
            }

            $tierRows = $priceTiers === []
                ? collect()
                : DB::table('b2b_price_tiers')
                    ->whereIn('id', array_values($priceTiers))
                    ->get(['id', 'code']);
            $tierCodes = [];
            foreach ($tierRows as $row) {
                $tierCodes[(int) $row->id] = (string) $row->code;
            }

            $storeIds = collect($warehouseMeta)
                ->pluck('store_id')
                ->filter()
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
            $storeNames = $storeIds === []
                ? []
                : DB::table('stores')->whereIn('id', $storeIds)->pluck('name', 'id')
                    ->map(static fn ($name): string => (string) $name)
                    ->all();

            return [
                'customers' => $customers,
                'b2b_customers' => $b2bCustomers,
                'platform_customers' => $platformCustomers,
                'b2b_accounts' => $b2bAccounts,
                'warehouses' => $warehouses,
                'warehouse_meta' => $warehouseMeta,
                'products' => $products,
                'product_meta' => $productMetaByTarget,
                'price_tiers' => $priceTiers,
                'tier_codes' => $tierCodes,
                'customer_meta' => $customerMetaByTarget,
                'store_names' => $storeNames,
            ];
        });

        $unmappedCustomers = [];
        $unmappedB2bCustomers = [];
        $unmappedLocations = [];
        $invalidHeaderDates = [];
        $headerDateTimes = [];
        $returnAmounts = [];
        $returnNonzeroCount = 0;
        $paidSalesCount = 0;
        $paymentBalanceMismatches = 0;
        $paymentBalanceMismatchSamples = [];
        $paidEqualsCashVisa = 0;
        $paidDiffersCashVisa = 0;
        $paidCashVisaNonzero = 0;
        $paidZeroCount = 0;
        $paidOneCount = 0;
        $paidGreaterThanOneCount = 0;
        $paidComparisonSamples = [];

        $parseDate = static function (mixed $value): ?string {
            $date = (int) $value;
            if ($date <= 0) {
                return null;
            }

            $raw = str_pad((string) $date, 8, '0', STR_PAD_LEFT);
            $parsed = \DateTimeImmutable::createFromFormat('!Ymd', $raw);
            if ($parsed === false || $parsed->format('Ymd') !== $raw) {
                return null;
            }

            return $parsed->format('Y-m-d 00:00:00');
        };

        $stage('validate_sales_return_headers', function () use (
            $headerCursor,
            $maps,
            $parseDate,
            $salesTypes,
            &$unmappedCustomers,
            &$unmappedB2bCustomers,
            &$unmappedLocations,
            &$invalidHeaderDates,
            &$headerDateTimes,
            &$returnAmounts,
            &$returnNonzeroCount,
            &$paidSalesCount,
            &$paymentBalanceMismatches,
            &$paymentBalanceMismatchSamples,
            &$paidEqualsCashVisa,
            &$paidDiffersCashVisa,
            &$paidCashVisaNonzero,
            &$paidZeroCount,
            &$paidOneCount,
            &$paidGreaterThanOneCount,
            &$paidComparisonSamples,
        ): void {
            foreach ($headerCursor() as $header) {
                $headerId = (int) $header->HeaderId;
                $customerId = (int) ($header->CustomerId ?? 0);
                $locationId = (int) ($header->StoreId ?? 0);

                if ($customerId <= 0 || ! isset($maps['customers'][$customerId])) {
                    $unmappedCustomers[$customerId] = true;
                }
                if ($customerId <= 0 || ! isset($maps['b2b_customers'][$customerId])) {
                    $unmappedB2bCustomers[$customerId] = true;
                }

                $warehouseId = $maps['warehouses'][$locationId] ?? null;
                if (
                    $locationId <= 0
                    || $warehouseId === null
                    || ! isset($maps['warehouse_meta'][$warehouseId])
                    || $maps['warehouse_meta'][$warehouseId]['store_id'] === null
                ) {
                    $unmappedLocations[$locationId] = true;
                    if (count($invalidLocationHeaderSamples) < 20) {
                        $invalidLocationHeaderSamples[] = [
                            'header_id' => $headerId,
                            'type' => $type,
                            'supplier_id' => $supplierId,
                            'location_id' => $locationId,
                            'total' => round((float) ($header->Total ?? 0), 3),
                            'net_total' => round((float) ($header->NetTotal ?? 0), 3),
                        ];
                    }
                }

                $dateTime = $parseDate($header->TransDate ?? null);
                if ($dateTime === null) {
                    $invalidHeaderDates[$headerId] = (int) ($header->TransDate ?? 0);
                } else {
                    $headerDateTimes[$headerId] = $dateTime;
                }

                $type = (int) $header->TransType;
                if (in_array($type, $salesTypes, true)) {
                    $paid = round((float) ($header->Paid ?? 0), 3);
                    $paidCash = round((float) ($header->PaidCash ?? 0), 3);
                    $paidVisa = round((float) ($header->PaidByVisa ?? 0), 3);
                    $cashVisa = round($paidCash + $paidVisa, 3);

                    if ($paid > 0.0005) {
                        $paidSalesCount++;
                    }

                    if (abs($paid) <= 0.0005) {
                        $paidZeroCount++;
                    } elseif (abs($paid - 1.0) <= 0.0005) {
                        $paidOneCount++;
                    } elseif ($paid > 1.0005) {
                        $paidGreaterThanOneCount++;
                    }

                    if ($cashVisa > 0.0005) {
                        $paidCashVisaNonzero++;
                    }

                    if (abs($paid - $cashVisa) <= 0.050) {
                        $paidEqualsCashVisa++;
                    } else {
                        $paidDiffersCashVisa++;
                        if (count($paidComparisonSamples) < 20) {
                            $paidComparisonSamples[] = [
                                'header_id' => $headerId,
                                'type' => $type,
                                'date' => (int) ($header->TransDate ?? 0),
                                'pay_type' => (int) ($header->PayType ?? 0),
                                'net_total' => round((float) ($header->NetTotal ?? 0), 3),
                                'paid' => $paid,
                                'paid_cash' => $paidCash,
                                'paid_visa' => $paidVisa,
                                'cash_plus_visa' => $cashVisa,
                                'balance' => round((float) ($header->Balance ?? 0), 3),
                            ];
                        }
                    }

                    $net = round((float) ($header->NetTotal ?? 0), 3);
                    $balance = round((float) ($header->Balance ?? 0), 3);
                    if (abs(($net - $paid) - $balance) > 0.050) {
                        $paymentBalanceMismatches++;
                        if (count($paymentBalanceMismatchSamples) < 20) {
                            $paymentBalanceMismatchSamples[] = [
                                'header_id' => $headerId,
                                'type' => $type,
                                'date' => (int) ($header->TransDate ?? 0),
                                'net_total' => $net,
                                'paid' => $paid,
                                'balance' => $balance,
                                'net_minus_paid' => round($net - $paid, 3),
                                'paid_cash' => $paidCash,
                                'paid_visa' => $paidVisa,
                                'pay_type' => (int) ($header->PayType ?? 0),
                            ];
                        }
                    }
                } else {
                    $net = abs(round((float) ($header->NetTotal ?? 0), 3));
                    $total = abs(round((float) ($header->Total ?? 0), 3));
                    $amount = $net > 0.0005 ? $net : $total;
                    $returnAmounts[$headerId] = $amount;
                    if ($amount > 0.0005) {
                        $returnNonzeroCount++;
                    }
                }
            }
        });

        unset($unmappedCustomers[0], $unmappedB2bCustomers[0], $unmappedLocations[0]);

        $detailCounts = [];
        $detailValueSums = [];
        $usedSalesItems = [];
        $sourceDetailRowsScanned = 0;
        $salesDetails = 0;
        $returnDetails = 0;
        $salesZeroQtyLines = 0;
        $salesNegativeQtyLines = 0;
        $invalidSalesItemRows = 0;
        $zeroQtyLineSamples = [];
        $chunkNumber = 0;

        $stage('stream_sales_return_details', function () use (
            $source,
            $progress,
            $headerTypes,
            $salesTypes,
            &$detailCounts,
            &$detailValueSums,
            &$usedSalesItems,
            &$sourceDetailRowsScanned,
            &$salesDetails,
            &$returnDetails,
            &$salesZeroQtyLines,
            &$salesNegativeQtyLines,
            &$invalidSalesItemRows,
            &$zeroQtyLineSamples,
            &$chunkNumber,
        ): void {
            $source->table('wh_TransDetails')
                ->select([
                    'DetailId',
                    'HeaderId',
                    'ItemID',
                    'Qty',
                    'Value',
                    'Status',
                ])
                ->orderBy('DetailId')
                ->chunkById(
                    10000,
                    function ($rows) use (
                        $progress,
                        $headerTypes,
                        $salesTypes,
                        &$detailCounts,
                        &$detailValueSums,
                        &$usedSalesItems,
                        &$sourceDetailRowsScanned,
                        &$salesDetails,
                        &$returnDetails,
                        &$salesZeroQtyLines,
                        &$salesNegativeQtyLines,
                        &$invalidSalesItemRows,
                        &$zeroQtyLineSamples,
                        &$chunkNumber,
                    ): void {
                        $startedAt = microtime(true);
                        $chunkNumber++;

                        foreach ($rows as $row) {
                            $sourceDetailRowsScanned++;

                            if ((int) ($row->Status ?? 0) !== 1) {
                                continue;
                            }

                            $headerId = (int) ($row->HeaderId ?? 0);
                            $type = $headerTypes[$headerId] ?? null;
                            if ($type === null) {
                                continue;
                            }

                            $detailCounts[$headerId] = ($detailCounts[$headerId] ?? 0) + 1;
                            $detailValueSums[$headerId] = ($detailValueSums[$headerId] ?? 0.0)
                                + (float) ($row->Value ?? 0);

                            if (in_array($type, $salesTypes, true)) {
                                $salesDetails++;
                                $itemId = (int) ($row->ItemID ?? 0);
                                if ($itemId <= 0) {
                                    $invalidSalesItemRows++;
                                } else {
                                    $usedSalesItems[$itemId] = true;
                                }

                                $qty = (float) ($row->Qty ?? 0);
                                if ($qty < -0.0005) {
                                    $salesNegativeQtyLines++;
                                } elseif (abs($qty) <= 0.0005) {
                                    $salesZeroQtyLines++;
                                    if (count($zeroQtyLineSamples) < 20) {
                                        $zeroQtyLineSamples[] = [
                                            'detail_id' => (int) ($row->DetailId ?? 0),
                                            'header_id' => $headerId,
                                            'item_id' => $itemId,
                                            'value' => round((float) ($row->Value ?? 0), 3),
                                        ];
                                    }
                                }
                            } else {
                                $returnDetails++;
                            }
                        }

                        if ($progress !== null) {
                            $progress(
                                'sales_history_details_chunk_'.$chunkNumber.'_rows_'.$sourceDetailRowsScanned,
                                'done',
                                round(microtime(true) - $startedAt, 3),
                            );
                        }
                    },
                    'DetailId',
                    'DetailId',
                );
        });

        $unmappedItems = [];
        foreach (array_keys($usedSalesItems) as $itemId) {
            if (! isset($maps['products'][$itemId])) {
                $unmappedItems[$itemId] = true;
            }
        }

        $salesHeadersWithoutLines = 0;
        $zeroEffectSalesHeadersSkipped = 0;
        $returnHeadersWithoutLines = 0;
        $lineTotalMismatches = 0;
        $salesHeadersWithoutLinesSamples = [];
        $zeroEffectSalesHeaderIds = [];
        $lineTotalMismatchSamples = [];

        foreach ($salesHeaderIds as $headerId) {
            if (($detailCounts[$headerId] ?? 0) === 0) {
                $financials = $salesHeaderFinancials[$headerId] ?? [
                    'total' => 0.0,
                    'net_total' => 0.0,
                    'paid' => 0.0,
                    'balance' => 0.0,
                ];

                $zeroEffect = abs((float) $financials['total']) <= 0.0005
                    && abs((float) $financials['net_total']) <= 0.0005
                    && abs((float) $financials['paid']) <= 0.0005
                    && abs((float) $financials['balance']) <= 0.0005;

                if ($zeroEffect) {
                    $zeroEffectSalesHeadersSkipped++;
                    $zeroEffectSalesHeaderIds[] = $headerId;
                } else {
                    $salesHeadersWithoutLines++;
                    if (count($salesHeadersWithoutLinesSamples) < 20) {
                        $salesHeadersWithoutLinesSamples[] = $headerId;
                    }
                }
            }

            $lineTotal = round((float) ($detailValueSums[$headerId] ?? 0), 3);
            $headerTotal = (float) ($salesHeaderTotals[$headerId] ?? 0);
            if (($detailCounts[$headerId] ?? 0) > 0 && abs($lineTotal - $headerTotal) > 0.050) {
                $lineTotalMismatches++;
                if (count($lineTotalMismatchSamples) < 20) {
                    $lineTotalMismatchSamples[] = [
                        'header_id' => $headerId,
                        'header_total' => round($headerTotal, 3),
                        'detail_value_sum' => $lineTotal,
                        'difference' => round($headerTotal - $lineTotal, 3),
                        'detail_count' => (int) ($detailCounts[$headerId] ?? 0),
                    ];
                }
            }
        }

        $materializableSalesHeaderIds = array_values(array_diff($salesHeaderIds, $zeroEffectSalesHeaderIds));

        foreach ($returnHeaders as $headerId => $header) {
            if (($detailCounts[$headerId] ?? 0) === 0) {
                $returnHeadersWithoutLines++;
            }
        }

        $targetCounts = [];
        foreach ([
            'orders',
            'order_items',
            'order_status_history',
            'invoices',
            'invoice_items',
            'payments',
            'customer_account_ledger_entries',
        ] as $table) {
            $targetCounts[$table] = (int) DB::table($table)->count();
        }

        $planned = [
            'sales_headers_source' => count($salesHeaderIds),
            'sales_headers_materialized' => count($materializableSalesHeaderIds),
            'zero_effect_sales_headers_skipped' => $zeroEffectSalesHeadersSkipped,
            'sales_details' => $salesDetails,
            'return_headers' => count($returnHeaders),
            'return_details' => $returnDetails,
            'payments' => $paidSalesCount,
            'return_ledger_entries' => $returnNonzeroCount,
            'source_detail_rows_scanned' => $sourceDetailRowsScanned,
            'unmapped_customers' => count($unmappedCustomers),
            'unmapped_b2b_customers' => count($unmappedB2bCustomers),
            'unmapped_locations' => count($unmappedLocations),
            'unmapped_items' => count($unmappedItems),
            'invalid_header_dates' => count($invalidHeaderDates),
            'invalid_sales_item_rows' => $invalidSalesItemRows,
            'sales_headers_without_lines' => $salesHeadersWithoutLines,
            'return_headers_without_lines' => $returnHeadersWithoutLines,
            'sales_negative_qty_lines' => $salesNegativeQtyLines,
            'sales_zero_qty_lines' => $salesZeroQtyLines,
            'line_total_mismatches' => $lineTotalMismatches,
            'legacy_balance_not_outstanding_count' => $paymentBalanceMismatches,
            'existing_orders' => $targetCounts['orders'],
            'existing_invoices' => $targetCounts['invoices'],
            'existing_payments' => $targetCounts['payments'],
            'existing_customer_ledger_entries' => $targetCounts['customer_account_ledger_entries'],
        ];

        if (! $apply) {
            return [
                'mode' => 'dry-run',
                'phase' => 'sales-history',
                'planned' => $planned,
                'type_counts' => $typeCounts,
                'samples' => [
                    'unmapped_customers' => array_slice(array_keys($unmappedCustomers), 0, 30),
                    'unmapped_locations' => array_slice(array_keys($unmappedLocations), 0, 30),
                    'unmapped_items' => array_slice(array_keys($unmappedItems), 0, 30),
                    'invalid_header_dates' => array_slice($invalidHeaderDates, 0, 20, true),
                    'sales_headers_without_lines' => $salesHeadersWithoutLinesSamples,
                    'zero_effect_sales_headers_skipped' => $zeroEffectSalesHeaderIds,
                    'line_total_mismatches' => $lineTotalMismatchSamples,
                    'sales_zero_qty_lines' => $zeroQtyLineSamples,
                    'payment_balance_mismatches' => $paymentBalanceMismatchSamples,
                    'paid_vs_cash_visa' => $paidComparisonSamples,
                ],
                'diagnostics' => [
                    'paid_zero_count' => $paidZeroCount,
                    'paid_one_count' => $paidOneCount,
                    'paid_greater_than_one_count' => $paidGreaterThanOneCount,
                    'paid_cash_or_visa_nonzero_count' => $paidCashVisaNonzero,
                    'paid_equals_cash_plus_visa_count' => $paidEqualsCashVisa,
                    'paid_differs_cash_plus_visa_count' => $paidDiffersCashVisa,
                ],
                'rules' => [
                    'sales_types' => $salesTypes,
                    'return_types' => $returnTypes,
                    'historical_import_does_not_mutate_inventory' => true,
                    'sales_materialize_as_completed_b2b_orders_and_issued_invoices' => true,
                    'zero_effect_header_without_lines_is_skipped_with_quarantine_provenance' => true,
                    'invoice_total_authority_is_net_total' => true,
                    'subtotal_prefers_active_detail_value_sum' => true,
                    'paid_amounts_materialize_as_payments' => true,
                    'legacy_balance_is_provenance_only_not_invoice_outstanding' => true,
                    'payment_status_uses_paid_vs_net_total' => true,
                    'returns_materialize_as_customer_ledger_credits' => true,
                    'target_commerce_must_be_clean' => true,
                ],
            ];
        }

        $blocking = [
            'unmapped_customers' => count($unmappedCustomers),
            'unmapped_b2b_customers' => count($unmappedB2bCustomers),
            'unmapped_locations' => count($unmappedLocations),
            'unmapped_items' => count($unmappedItems),
            'invalid_header_dates' => count($invalidHeaderDates),
            'invalid_sales_item_rows' => $invalidSalesItemRows,
            'sales_headers_without_lines' => $salesHeadersWithoutLines,
            'sales_negative_qty_lines' => $salesNegativeQtyLines,
        ];

        if (array_sum($blocking) !== 0) {
            throw new RuntimeException(
                'Sales history apply refused: source-to-target validation has blocking issues. '.
                json_encode($blocking, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        if (array_sum($targetCounts) !== 0) {
            throw new RuntimeException(
                'Sales history apply refused: target commerce/ledger tables are not empty. '.
                'Clean cutover forbids merging legacy history into existing target commerce data.'
            );
        }

        unset(
            $headerTypes,
            $detailCounts,
            $usedSalesItems,
            $salesHeaderTotals,
            $salesHeaderFinancials,
            $unmappedCustomers,
            $unmappedB2bCustomers,
            $unmappedLocations,
            $unmappedItems,
            $invalidHeaderDates,
            $salesHeadersWithoutLinesSamples,
            $lineTotalMismatchSamples,
            $zeroQtyLineSamples,
            $paymentBalanceMismatchSamples,
            $paidComparisonSamples,
        );
        gc_collect_cycles();

        $runId = (string) Str::uuid();
        DB::table('legacy_import_runs')->insert([
            'id' => $runId,
            'source_system' => self::SOURCE_SYSTEM,
            'phase' => 'sales-history',
            'mode' => 'apply',
            'status' => 'running',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $paymentMethod = static function (object $header): string {
            $paidCash = (float) ($header->PaidCash ?? 0);
            $paidVisa = (float) ($header->PaidByVisa ?? 0);
            $payType = (int) ($header->PayType ?? 0);

            if ($paidCash > 0.0005 && $paidVisa > 0.0005) {
                return 'legacy_mixed';
            }
            if ($paidVisa > 0.0005) {
                return 'card';
            }
            if ($paidCash > 0.0005) {
                return 'cash';
            }

            return $payType > 0 ? 'legacy_pay_type_'.$payType : 'legacy_unspecified';
        };

        try {
            $summary = DB::transaction(
                fn (): array => $this->materializeSalesHistoryFast(
                    $source,
                    $progress,
                    $materializableSalesHeaderIds,
                    $zeroEffectSalesHeaderIds,
                    $headerColumns,
                    $returnHeaders,
                    $headerDateTimes,
                    $returnAmounts,
                    $detailValueSums,
                    $maps,
                    $runId,
                    $paymentMethod,
                    $planned,
                ),
                3,
            );

            DB::table('legacy_import_runs')->where('id', $runId)->update([
                'status' => 'completed',
                'summary' => $this->json($summary),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'mode' => 'apply',
                'phase' => 'sales-history',
                'status' => 'completed',
                'summary' => $summary,
            ];
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

    /**
     * Materialize validated legacy sales with one streamed header pass and one streamed detail pass.
     *
     * The source SQL Server is intentionally never queried once per target batch; older Foodex
     * databases frequently have no useful HeaderId index on wh_TransDetails, so repeated whereIn
     * lookups degrade into full scans and can turn a migration into a multi-hour job.
     */
    private function materializeSalesHistoryFast(
        mixed $source,
        ?callable $progress,
        array $materializableSalesHeaderIds,
        array $zeroEffectSalesHeaderIds,
        array $headerColumns,
        array $returnHeaders,
        array $headerDateTimes,
        array $returnAmounts,
        array $detailValueSums,
        array $maps,
        string $runId,
        callable $paymentMethod,
        array $planned,
    ): array {
        $ordersCreated = 0;
        $orderItemsCreated = 0;
        $invoicesCreated = 0;
        $invoiceItemsCreated = 0;
        $paymentsCreated = 0;
        $returnLedgerCreated = 0;
        $historyCreated = 0;
        $mappingRows = [];
        $sourceOrderIds = [];
        $sourceInvoiceIds = [];
        $lineMeta = [];

        $materializable = array_fill_keys(
            array_map(static fn ($id): int => (int) $id, $materializableSalesHeaderIds),
            true,
        );

        $flushMappings = function () use (&$mappingRows): void {
            if ($mappingRows === []) {
                return;
            }

            DB::table('legacy_import_mappings')->upsert(
                $mappingRows,
                ['source_system', 'source_table', 'source_key', 'target_table'],
                ['target_id', 'source_hash', 'metadata', 'last_run_id', 'updated_at'],
            );
            $mappingRows = [];
        };

        $headerBatchNumber = 0;
        $headerBatch = [];

        $flushHeaderBatch = function (array $batch) use (
            &$headerBatchNumber,
            &$ordersCreated,
            &$invoicesCreated,
            &$paymentsCreated,
            &$historyCreated,
            &$mappingRows,
            &$sourceOrderIds,
            &$sourceInvoiceIds,
            &$lineMeta,
            $maps,
            $headerDateTimes,
            $detailValueSums,
            $runId,
            $paymentMethod,
            $progress,
            $flushMappings,
        ): void {
            if ($batch === []) {
                return;
            }

            $headerBatchNumber++;
            $startedAt = microtime(true);
            if ($progress !== null) {
                $progress('materialize_sales_headers_batch_'.$headerBatchNumber, 'start', null);
            }

            $orderRows = [];
            $orderNumberToSource = [];

            foreach ($batch as $header) {
                $headerId = (int) $header->HeaderId;
                $customerSourceId = (int) $header->CustomerId;
                $customerId = $maps['customers'][$customerSourceId];
                $b2bCustomerId = $maps['b2b_customers'][$customerSourceId];
                $warehouseId = $maps['warehouses'][(int) $header->StoreId];
                $storeId = (int) $maps['warehouse_meta'][$warehouseId]['store_id'];
                $priceTierId = $maps['price_tiers'][(int) ($header->PriceType ?? 0)] ?? null;
                $accountId = $maps['b2b_accounts'][$customerSourceId] ?? null;
                $dateTime = $headerDateTimes[$headerId];
                $orderNumber = 'FDX-ORDER-'.((int) $header->TransType).'-'.$headerId;
                $subtotal = round(
                    (float) ($detailValueSums[$headerId] ?? ($header->Total ?? 0)),
                    3,
                );

                $orderRows[] = [
                    'store_id' => $storeId,
                    'warehouse_id' => $warehouseId,
                    'customer_id' => $customerId,
                    'b2b_customer_id' => $b2bCustomerId,
                    'b2c_customer_id' => null,
                    'address_id' => null,
                    'order_number' => $orderNumber,
                    'channel' => 'b2b',
                    'status' => 'completed',
                    'currency' => 'EGP',
                    'subtotal' => $subtotal,
                    'discount_total' => abs(round((float) ($header->HdrDiscount ?? 0), 3)),
                    'delivery_total' => 0,
                    'tax_total' => round((float) ($header->HdrTax ?? 0), 3),
                    'grand_total' => round((float) ($header->NetTotal ?? 0), 3),
                    'b2b_account_id_snapshot' => $accountId,
                    'price_tier_id_snapshot' => $priceTierId,
                    'price_tier_code_snapshot' => $priceTierId === null
                        ? null
                        : ($maps['tier_codes'][$priceTierId] ?? null),
                    'pricing_snapshot' => $this->json([
                        'source_system' => self::SOURCE_SYSTEM,
                        'source_table' => 'wh_TransHeader',
                        'source_header_id' => $headerId,
                        'source_trans_type' => (int) $header->TransType,
                        'doc_trans_no' => $header->DocTransNo,
                        'code' => $header->Code,
                        'reference_no' => $header->ReferenceNo,
                        'header_discount' => (float) ($header->HdrDiscount ?? 0),
                        'header_addition' => (float) ($header->HdrAddition ?? 0),
                        'header_tax' => (float) ($header->HdrTax ?? 0),
                        'legacy_price_type' => (int) ($header->PriceType ?? 0),
                        'legacy_pay_type' => (int) ($header->PayType ?? 0),
                        'legacy_paid' => (float) ($header->Paid ?? 0),
                        'legacy_balance' => (float) ($header->Balance ?? 0),
                    ]),
                    'payment_method' => $paymentMethod($header),
                    'customer_note' => $this->legacyText($header->Notes ?? null),
                    'commercial_locked_at' => $dateTime,
                    'created_at' => $dateTime,
                    'updated_at' => $dateTime,
                ];
                $orderNumberToSource[$orderNumber] = $headerId;
            }

            foreach (array_chunk($orderRows, 500) as $rows) {
                DB::table('orders')->insert($rows);
            }
            $ordersCreated += count($orderRows);

            $orders = DB::table('orders')
                ->whereIn('order_number', array_keys($orderNumberToSource))
                ->get(['id', 'order_number'])
                ->keyBy('order_number');

            $invoiceRows = [];
            $invoiceNumberToSource = [];

            foreach ($batch as $header) {
                $headerId = (int) $header->HeaderId;
                $orderNumber = 'FDX-ORDER-'.((int) $header->TransType).'-'.$headerId;
                $order = $orders->get($orderNumber);
                if ($order === null) {
                    throw new RuntimeException("Inserted legacy order {$orderNumber} could not be resolved.");
                }

                $orderId = (int) $order->id;
                $sourceOrderIds[$headerId] = $orderId;

                $customerSourceId = (int) $header->CustomerId;
                $customerId = $maps['customers'][$customerSourceId];
                $b2bCustomerId = $maps['b2b_customers'][$customerSourceId];
                $platformCustomerId = $maps['platform_customers'][$customerSourceId] ?? null;
                $warehouseId = $maps['warehouses'][(int) $header->StoreId];
                $storeId = (int) $maps['warehouse_meta'][$warehouseId]['store_id'];
                $priceTierId = $maps['price_tiers'][(int) ($header->PriceType ?? 0)] ?? null;
                $accountId = $maps['b2b_accounts'][$customerSourceId] ?? null;
                $tierCode = $priceTierId === null ? null : ($maps['tier_codes'][$priceTierId] ?? null);
                $dateTime = $headerDateTimes[$headerId];
                $period = max(0, (int) ($header->Periodterm ?? 0));
                $date = new \DateTimeImmutable($dateTime);
                $dueAt = $period > 0 ? $date->modify('+'.$period.' days')->format('Y-m-d H:i:s') : null;
                $invoiceNumber = 'FDX-INVOICE-'.((int) $header->TransType).'-'.$headerId;
                $paid = round((float) ($header->Paid ?? 0), 3);
                $net = round((float) ($header->NetTotal ?? 0), 3);
                $paymentStatus = $paid <= 0.0005
                    ? 'unpaid'
                    : ($paid >= $net - 0.0005 ? 'paid' : 'partial');
                $subtotal = round(
                    (float) ($detailValueSums[$headerId] ?? ($header->Total ?? 0)),
                    3,
                );
                $customerMeta = $maps['customer_meta'][$customerId] ?? [
                    'name' => 'Legacy customer '.$customerSourceId,
                    'phone' => null,
                    'email' => null,
                ];

                $invoiceRows[] = [
                    'order_id' => $orderId,
                    'store_id' => $storeId,
                    'customer_id' => $customerId,
                    'platform_customer_id' => $platformCustomerId,
                    'b2b_customer_id' => $b2bCustomerId,
                    'b2c_customer_id' => null,
                    'invoice_number' => $invoiceNumber,
                    'status' => 'issued',
                    'channel' => 'b2b',
                    'currency' => 'EGP',
                    'total' => $net,
                    'issued_at' => $dateTime,
                    'due_at' => $dueAt,
                    'order_number_snapshot' => $orderNumber,
                    'store_name_snapshot' => $maps['store_names'][$storeId] ?? null,
                    'customer_name_snapshot' => $customerMeta['name'],
                    'customer_email_snapshot' => $customerMeta['email'],
                    'customer_phone_snapshot' => $customerMeta['phone'],
                    'subtotal' => $subtotal,
                    'discount_total' => abs(round((float) ($header->HdrDiscount ?? 0), 3)),
                    'delivery_total' => 0,
                    'tax_total' => round((float) ($header->HdrTax ?? 0), 3),
                    'payment_method_snapshot' => $paymentMethod($header),
                    'payment_status_snapshot' => $paymentStatus,
                    'b2b_account_id_snapshot' => $accountId,
                    'price_tier_id_snapshot' => $priceTierId,
                    'price_tier_code_snapshot' => $tierCode,
                    'commercial_snapshot' => $this->json([
                        'source_system' => self::SOURCE_SYSTEM,
                        'source_table' => 'wh_TransHeader',
                        'source_header_id' => $headerId,
                        'source_trans_type' => (int) $header->TransType,
                        'source_store_id' => (int) $header->StoreId,
                        'source_customer_id' => $customerSourceId,
                        'source_sales_rep_id' => (int) ($header->SalesRepId ?? 0),
                        'source_car_header_id' => (int) ($header->CarHeaderId ?? 0),
                        'doc_trans_no' => $header->DocTransNo,
                        'code' => $header->Code,
                        'reference_no' => $header->ReferenceNo,
                        'legacy_total' => (float) ($header->Total ?? 0),
                        'detail_value_sum' => $subtotal,
                        'legacy_net_total' => (float) ($header->NetTotal ?? 0),
                        'legacy_paid' => (float) ($header->Paid ?? 0),
                        'legacy_balance' => (float) ($header->Balance ?? 0),
                        'legacy_currency_id' => (int) ($header->CurrencyId ?? 0),
                        'legacy_currency_rate' => (float) ($header->CurrencyRate ?? 0),
                        'run_id' => $runId,
                    ]),
                    'revision' => 1,
                    'created_at' => $dateTime,
                    'updated_at' => $dateTime,
                ];
                $invoiceNumberToSource[$invoiceNumber] = $headerId;

                $lineMeta[$headerId] = [
                    'customer_source_id' => $customerSourceId,
                    'account_id' => $accountId,
                    'price_tier_id' => $priceTierId,
                    'price_tier_code' => $tierCode,
                    'date_time' => $dateTime,
                ];
            }

            foreach (array_chunk($invoiceRows, 500) as $rows) {
                DB::table('invoices')->insert($rows);
            }
            $invoicesCreated += count($invoiceRows);

            $invoices = DB::table('invoices')
                ->whereIn('invoice_number', array_keys($invoiceNumberToSource))
                ->get(['id', 'invoice_number'])
                ->keyBy('invoice_number');

            $statusRows = [];
            $paymentRows = [];

            foreach ($batch as $header) {
                $headerId = (int) $header->HeaderId;
                $invoiceNumber = 'FDX-INVOICE-'.((int) $header->TransType).'-'.$headerId;
                $invoice = $invoices->get($invoiceNumber);
                if ($invoice === null) {
                    throw new RuntimeException("Inserted legacy invoice {$invoiceNumber} could not be resolved.");
                }

                $invoiceId = (int) $invoice->id;
                $sourceInvoiceIds[$headerId] = $invoiceId;
                $orderId = $sourceOrderIds[$headerId];
                $warehouseId = $maps['warehouses'][(int) $header->StoreId];
                $storeId = (int) $maps['warehouse_meta'][$warehouseId]['store_id'];
                $dateTime = $headerDateTimes[$headerId];

                $statusRows[] = [
                    'order_id' => $orderId,
                    'store_id' => $storeId,
                    'user_id' => null,
                    'from_status' => null,
                    'to_status' => 'completed',
                    'note' => 'legacy_foodex_import',
                    'created_at' => $dateTime,
                    'updated_at' => $dateTime,
                ];

                $paid = round((float) ($header->Paid ?? 0), 3);
                if ($paid > 0.0005) {
                    $paymentRows[] = [
                        'order_id' => $orderId,
                        'invoice_id' => $invoiceId,
                        'provider' => 'legacy_foodex',
                        'provider_reference' => 'FDX-PAY-'.$headerId,
                        'status' => 'paid',
                        'amount' => $paid,
                        'currency' => 'EGP',
                        'metadata' => $this->json([
                            'source_system' => self::SOURCE_SYSTEM,
                            'source_header_id' => $headerId,
                            'legacy_pay_type' => (int) ($header->PayType ?? 0),
                            'paid_cash' => (float) ($header->PaidCash ?? 0),
                            'paid_by_visa' => (float) ($header->PaidByVisa ?? 0),
                            'legacy_balance' => (float) ($header->Balance ?? 0),
                        ]),
                        'created_at' => $dateTime,
                        'updated_at' => $dateTime,
                    ];
                }

                $sourceArray = (array) $header;
                $sourceJson = $this->json($sourceArray);
                $mappingBase = [
                    'source_system' => self::SOURCE_SYSTEM,
                    'source_table' => 'wh_TransHeader',
                    'source_key' => (string) $headerId,
                    'source_hash' => hash('sha256', $sourceJson),
                    'last_run_id' => $runId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $mappingRows[] = [
                    ...$mappingBase,
                    'target_table' => 'orders',
                    'target_id' => $orderId,
                    'metadata' => $this->json([
                        'source' => $sourceArray,
                        '_migration' => ['phase' => 'sales-history', 'role' => 'sales_order'],
                    ]),
                ];
                $mappingRows[] = [
                    ...$mappingBase,
                    'target_table' => 'invoices',
                    'target_id' => $invoiceId,
                    'metadata' => $this->json([
                        'source' => $sourceArray,
                        '_migration' => ['phase' => 'sales-history', 'role' => 'sales_invoice'],
                    ]),
                ];
            }

            foreach (array_chunk($statusRows, 1000) as $rows) {
                DB::table('order_status_history')->insert($rows);
            }
            foreach (array_chunk($paymentRows, 1000) as $rows) {
                DB::table('payments')->insert($rows);
            }

            $historyCreated += count($statusRows);
            $paymentsCreated += count($paymentRows);

            if (count($mappingRows) >= 1000) {
                $flushMappings();
            }

            if ($progress !== null) {
                $progress(
                    'materialize_sales_headers_batch_'.$headerBatchNumber.'_headers_'.$ordersCreated,
                    'done',
                    round(microtime(true) - $startedAt, 3),
                );
            }
        };

        $headersStartedAt = microtime(true);
        if ($progress !== null) {
            $progress('stream_materialize_sales_headers', 'start', null);
        }

        foreach (
            $source->table('wh_TransHeader')
                ->select($headerColumns)
                ->where('Status', 1)
                ->whereIn('TransType', [3, 19])
                ->orderBy('HeaderId')
                ->cursor() as $header
        ) {
            $headerId = (int) $header->HeaderId;
            if (! isset($materializable[$headerId])) {
                continue;
            }

            $headerBatch[] = $header;
            if (count($headerBatch) >= 500) {
                $flushHeaderBatch($headerBatch);
                $headerBatch = [];
            }
        }

        if ($headerBatch !== []) {
            $flushHeaderBatch($headerBatch);
            $headerBatch = [];
        }

        if ($ordersCreated !== count($materializableSalesHeaderIds)) {
            throw new RuntimeException(
                'Sales history source changed during apply: materialized header count does not match validated count.'
            );
        }

        if ($progress !== null) {
            $progress(
                'stream_materialize_sales_headers',
                'done',
                round(microtime(true) - $headersStartedAt, 3),
            );
        }

        $flushMappings();

        $detailChunkNumber = 0;
        $detailsScanned = 0;
        $detailsStartedAt = microtime(true);

        if ($progress !== null) {
            $progress('stream_materialize_sales_details', 'start', null);
        }

        $source->table('wh_TransDetails')
            ->select([
                'DetailId',
                'HeaderId',
                'ItemID',
                'ItemBarCode',
                'Package',
                'Qty',
                'TransPkgQty1',
                'TransPkgPrice1',
                'Price',
                'Value',
                'CostPrice',
                'DetailDiscount1',
                'DetailDiscount2',
                'DetailDiscount3',
                'DetailTax',
                'DetailRemark',
                'Status',
            ])
            ->orderBy('DetailId')
            ->chunkById(
                10000,
                function ($rows) use (
                    &$detailChunkNumber,
                    &$detailsScanned,
                    &$orderItemsCreated,
                    &$invoiceItemsCreated,
                    $sourceOrderIds,
                    $sourceInvoiceIds,
                    $lineMeta,
                    $maps,
                    $progress,
                ): void {
                    $detailChunkNumber++;
                    $chunkStartedAt = microtime(true);
                    $orderItemRows = [];
                    $invoiceItemRows = [];

                    $flushItems = function () use (
                        &$orderItemRows,
                        &$invoiceItemRows,
                        &$orderItemsCreated,
                        &$invoiceItemsCreated,
                    ): void {
                        if ($orderItemRows !== []) {
                            DB::table('order_items')->insert($orderItemRows);
                            $orderItemsCreated += count($orderItemRows);
                            $orderItemRows = [];
                        }

                        if ($invoiceItemRows !== []) {
                            DB::table('invoice_items')->insert($invoiceItemRows);
                            $invoiceItemsCreated += count($invoiceItemRows);
                            $invoiceItemRows = [];
                        }
                    };

                    foreach ($rows as $line) {
                        $detailsScanned++;

                        if ((int) ($line->Status ?? 0) !== 1) {
                            continue;
                        }

                        $headerId = (int) ($line->HeaderId ?? 0);
                        if (! isset($sourceOrderIds[$headerId], $sourceInvoiceIds[$headerId], $lineMeta[$headerId])) {
                            continue;
                        }

                        $sourceItemId = (int) $line->ItemID;
                        if (! isset($maps['products'][$sourceItemId])) {
                            throw new RuntimeException(
                                "Validated sales item {$sourceItemId} lost its target mapping during apply."
                            );
                        }

                        $productId = $maps['products'][$sourceItemId];
                        $productMeta = $maps['product_meta'][$productId];
                        $meta = $lineMeta[$headerId];
                        $rawQty = abs((float) ($line->Qty ?? 0));
                        $baseQty = abs((float) ($line->TransPkgQty1 ?? 0));
                        $quantity = $rawQty > 0.0005 ? $rawQty : $baseQty;
                        $conversion = $rawQty > 0.0005 && $baseQty > 0.0005
                            ? round($baseQty / $rawQty, 3)
                            : 1.0;
                        $unitPrice = (float) ($line->Price ?? 0);
                        $lineTotal = round((float) ($line->Value ?? 0), 3);
                        if (abs($unitPrice) <= 0.0005 && $quantity > 0.0005) {
                            $unitPrice = round($lineTotal / $quantity, 3);
                        }
                        $lineDiscount = round(
                            (float) ($line->DetailDiscount1 ?? 0)
                            + (float) ($line->DetailDiscount2 ?? 0)
                            + (float) ($line->DetailDiscount3 ?? 0),
                            3,
                        );
                        $lineTax = round((float) ($line->DetailTax ?? 0), 3);
                        $dateTime = $meta['date_time'];

                        $orderItemRows[] = [
                            'order_id' => $sourceOrderIds[$headerId],
                            'product_id' => $productId,
                            'sku_snapshot' => $productMeta['sku'],
                            'name_snapshot' => $productMeta['name'],
                            'quantity' => round($quantity, 3),
                            'unit_price' => round($unitPrice, 3),
                            'line_total' => $lineTotal,
                            'selling_unit_code_snapshot' => 'LEGACY-PKG-'.((int) ($line->Package ?? 1)),
                            'quantity_conversion_factor' => $conversion,
                            'base_unit_price_snapshot' => round((float) ($line->TransPkgPrice1 ?? 0), 3),
                            'line_discount_total' => $lineDiscount,
                            'line_tax_total' => $lineTax,
                            'currency' => 'EGP',
                            'b2b_account_id_snapshot' => $meta['account_id'],
                            'price_tier_id_snapshot' => $meta['price_tier_id'],
                            'price_tier_code_snapshot' => $meta['price_tier_code'],
                            'created_at' => $dateTime,
                            'updated_at' => $dateTime,
                        ];

                        $invoiceItemRows[] = [
                            'invoice_id' => $sourceInvoiceIds[$headerId],
                            'product_id' => $productId,
                            'description' => $productMeta['name'],
                            'quantity' => round($quantity, 3),
                            'unit_price' => round($unitPrice, 3),
                            'line_total' => $lineTotal,
                            'sku_snapshot' => $productMeta['sku'],
                            'base_unit_price_snapshot' => round((float) ($line->TransPkgPrice1 ?? 0), 3),
                            'line_discount_total' => $lineDiscount,
                            'line_tax_total' => $lineTax,
                            'currency' => 'EGP',
                            'quantity_conversion_factor' => $conversion,
                            'b2b_account_id_snapshot' => $meta['account_id'],
                            'price_tier_id_snapshot' => $meta['price_tier_id'],
                            'price_tier_code_snapshot' => $meta['price_tier_code'],
                            'line_snapshot' => $this->json([
                                'source_system' => self::SOURCE_SYSTEM,
                                'source_table' => 'wh_TransDetails',
                                'source_detail_id' => (int) $line->DetailId,
                                'source_header_id' => $headerId,
                                'source_item_id' => $sourceItemId,
                                'item_barcode' => $line->ItemBarCode,
                                'package' => (int) ($line->Package ?? 0),
                                'raw_qty' => (float) ($line->Qty ?? 0),
                                'base_qty' => (float) ($line->TransPkgQty1 ?? 0),
                                'raw_price' => (float) ($line->Price ?? 0),
                                'base_price' => (float) ($line->TransPkgPrice1 ?? 0),
                                'legacy_value' => (float) ($line->Value ?? 0),
                                'legacy_cost_price' => (float) ($line->CostPrice ?? 0),
                                'detail_remark' => $line->DetailRemark,
                            ]),
                            'created_at' => $dateTime,
                            'updated_at' => $dateTime,
                        ];

                        if (count($orderItemRows) >= 500) {
                            $flushItems();
                        }
                    }

                    $flushItems();

                    if ($progress !== null) {
                        $progress(
                            'materialize_sales_details_chunk_'.$detailChunkNumber.'_rows_'.$detailsScanned,
                            'done',
                            round(microtime(true) - $chunkStartedAt, 3),
                        );
                    }
                },
                'DetailId',
                'DetailId',
            );

        if ($progress !== null) {
            $progress(
                'stream_materialize_sales_details',
                'done',
                round(microtime(true) - $detailsStartedAt, 3),
            );
        }

        $returnRows = [];
        $returnReferenceToSource = [];

        foreach ($returnHeaders as $headerId => $header) {
            $amount = (float) ($returnAmounts[$headerId] ?? 0);
            if ($amount <= 0.0005) {
                continue;
            }

            $customerSourceId = (int) $header->CustomerId;
            $b2bCustomerId = $maps['b2b_customers'][$customerSourceId];
            $warehouseId = $maps['warehouses'][(int) $header->StoreId];
            $storeId = (int) $maps['warehouse_meta'][$warehouseId]['store_id'];
            $dateTime = $headerDateTimes[$headerId];
            $salesHeaderId = (int) ($header->SalesHeaderId ?? 0);
            $linkedOrderId = $salesHeaderId > 0 ? ($sourceOrderIds[$salesHeaderId] ?? null) : null;
            $linkedInvoiceId = $salesHeaderId > 0 ? ($sourceInvoiceIds[$salesHeaderId] ?? null) : null;
            $reference = 'FDX-RETURN-'.((int) $header->TransType).'-'.$headerId;

            $returnRows[] = [
                'b2b_customer_id' => $b2bCustomerId,
                'store_id' => $storeId,
                'invoice_id' => $linkedInvoiceId,
                'order_id' => $linkedOrderId,
                'entry_type' => 'return',
                'reference' => $reference,
                'description' => 'Legacy Foodex return / source header '.$headerId,
                'debit' => 0,
                'credit' => round($amount, 3),
                'currency' => 'EGP',
                'actor_user_id' => null,
                'source' => 'legacy_foodex_import',
                'metadata' => $this->json([
                    'source_system' => self::SOURCE_SYSTEM,
                    'source_table' => 'wh_TransHeader',
                    'source_header_id' => $headerId,
                    'source_trans_type' => (int) $header->TransType,
                    'source_sales_header_id' => $salesHeaderId,
                    'doc_trans_no' => $header->DocTransNo,
                    'legacy_total' => (float) ($header->Total ?? 0),
                    'legacy_net_total' => (float) ($header->NetTotal ?? 0),
                    'run_id' => $runId,
                ]),
                'occurred_at' => $dateTime,
                'created_at' => $dateTime,
            ];
            $returnReferenceToSource[$reference] = $headerId;
        }

        foreach (array_chunk($returnRows, 1000) as $rows) {
            DB::table('customer_account_ledger_entries')->insert($rows);
        }
        $returnLedgerCreated = count($returnRows);

        if ($returnReferenceToSource !== []) {
            $ledgerRows = DB::table('customer_account_ledger_entries')
                ->whereIn('reference', array_keys($returnReferenceToSource))
                ->get(['id', 'reference'])
                ->keyBy('reference');

            foreach ($returnReferenceToSource as $reference => $headerId) {
                $ledgerRow = $ledgerRows->get($reference);
                if ($ledgerRow === null) {
                    throw new RuntimeException("Inserted legacy return ledger entry {$reference} could not be resolved.");
                }

                $header = $returnHeaders[$headerId];
                $sourceArray = (array) $header;
                $sourceJson = $this->json($sourceArray);
                $mappingRows[] = [
                    'source_system' => self::SOURCE_SYSTEM,
                    'source_table' => 'wh_TransHeader',
                    'source_key' => (string) $headerId,
                    'target_table' => 'customer_account_ledger_entries',
                    'target_id' => (int) $ledgerRow->id,
                    'source_hash' => hash('sha256', $sourceJson),
                    'metadata' => $this->json([
                        'source' => $sourceArray,
                        '_migration' => ['phase' => 'sales-history', 'role' => 'return_credit'],
                    ]),
                    'last_run_id' => $runId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if (count($mappingRows) >= 1000) {
                    $flushMappings();
                }
            }
        }

        $flushMappings();

        $zeroEffectQuarantined = 0;
        if ($zeroEffectSalesHeaderIds !== []) {
            foreach (
                $source->table('wh_TransHeader')
                    ->select($headerColumns)
                    ->whereIn('HeaderId', $zeroEffectSalesHeaderIds)
                    ->cursor() as $skippedHeader
            ) {
                $sourceKey = (string) ((int) $skippedHeader->HeaderId);
                DB::table('legacy_import_quarantine')->updateOrInsert(
                    [
                        'source_system' => self::SOURCE_SYSTEM,
                        'source_table' => 'wh_TransHeader',
                        'source_key' => $sourceKey,
                        'reason' => 'zero_effect_empty_sales_header_skipped',
                    ],
                    [
                        'payload' => $this->json([
                            'source' => (array) $skippedHeader,
                            '_migration' => [
                                'phase' => 'sales-history',
                                'decision' => 'skip',
                                'reason' => 'No detail rows and zero Total/NetTotal/Paid/Balance; no business effect.',
                            ],
                        ]),
                        'run_id' => $runId,
                        'resolved_at' => now(),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
                $zeroEffectQuarantined++;
            }
        }

        $target = [
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

        if (
            $target['orders'] !== $ordersCreated
            || $target['invoices'] !== $invoicesCreated
            || $target['order_items'] !== $orderItemsCreated
            || $target['invoice_items'] !== $invoiceItemsCreated
            || $target['order_status_history'] !== $historyCreated
            || $target['payments'] !== $paymentsCreated
            || $target['return_ledger_entries'] !== $returnLedgerCreated
        ) {
            throw new RuntimeException(
                'Sales history target validation failed: target counts do not match materialized counts.'
            );
        }

        return [
            'run_id' => $runId,
            'store_id' => app(WholesalePrincipal::class)->storeId(),
            'planned' => $planned,
            'orders' => ['created' => $ordersCreated],
            'order_items' => ['created' => $orderItemsCreated],
            'order_status_history' => ['created' => $historyCreated],
            'invoices' => ['created' => $invoicesCreated],
            'invoice_items' => ['created' => $invoiceItemsCreated],
            'payments' => ['created' => $paymentsCreated],
            'returns' => ['ledger_entries_created' => $returnLedgerCreated],
            'skipped_zero_effect_sales_headers' => [
                'count' => $zeroEffectQuarantined,
                'source_header_ids' => $zeroEffectSalesHeaderIds,
            ],
            'target' => $target,
            'inventory_mutations' => 0,
        ];
    }

    public function salesHistoryHeaderDiagnostics(array $headerIds, ?callable $progress = null): array
    {
        $source = DB::connection('foodex_legacy');

        $ids = collect($headerIds)
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            throw new RuntimeException('At least one positive legacy HeaderId is required.');
        }

        $byHeader = [];

        foreach ($ids as $id) {
            if ($progress !== null) {
                $progress('header_'.$id, 'start', null);
            }

            $startedAt = microtime(true);

            $header = $source->table('wh_TransHeader')
                ->where('HeaderId', $id)
                ->first([
                    'HeaderId',
                    'TransType',
                    'TransDate',
                    'CustomerId',
                    'StoreId',
                    'SalesRepId',
                    'CarHeaderId',
                    'SalesHeaderId',
                    'Code',
                    'DocTransNo',
                    'ReferenceNo',
                    'PriceType',
                    'PayType',
                    'Paid',
                    'PaidCash',
                    'PaidByVisa',
                    'Total',
                    'NetTotal',
                    'Balance',
                    'HdrDiscount',
                    'HdrTax',
                    'HdrAddtions',
                    'Periodterm',
                    'Status',
                    'Notes',
                ]);

            if ($progress !== null) {
                $progress('header_'.$id, 'done', round(microtime(true) - $startedAt, 3));
                $progress('details_'.$id, 'start', null);
            }

            $detailsStartedAt = microtime(true);

            $rows = $source->table('wh_TransDetails')
                ->where('HeaderId', $id)
                ->orderBy('DetailId')
                ->get([
                    'DetailId',
                    'HeaderId',
                    'ItemID',
                    'Package',
                    'Qty',
                    'TransPkgQty1',
                    'Price',
                    'TransPkgPrice1',
                    'Value',
                    'DetailDiscount1',
                    'DetailDiscount2',
                    'DetailDiscount3',
                    'DetailTax',
                    'Status',
                ]);

            if ($progress !== null) {
                $progress('details_'.$id, 'done', round(microtime(true) - $detailsStartedAt, 3));
            }

            $active = $rows->filter(static fn ($row): bool => (int) ($row->Status ?? 0) === 1)->values();

            $byHeader[$id] = [
                'header' => $header === null ? null : (array) $header,
                'details_total' => $rows->count(),
                'details_active' => $active->count(),
                'detail_value_sum_all' => round((float) $rows->sum('Value'), 3),
                'detail_value_sum_active' => round((float) $active->sum('Value'), 3),
                'detail_base_qty_sum_active' => round((float) $active->sum('TransPkgQty1'), 3),
                'details' => $rows->map(static fn ($row): array => (array) $row)->all(),
            ];
        }

        return [
            'headers' => $byHeader,
            'read_only' => true,
        ];
    }

    public function importPurchaseHistory(bool $apply, ?callable $progress = null): array
    {
        $this->assertTrackingTables();

        foreach (['legacy_procurement_documents', 'legacy_procurement_document_lines'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException(
                    "Missing {$table}. Run php artisan migrate before purchase-history import."
                );
            }
        }

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

        $source = DB::connection('foodex_legacy');
        $purchaseTypes = [1, 2];

        $maps = $stage('load_purchase_history_mappings', function (): array {
            $map = static function (string $sourceTable, string $targetTable, ?array $sourceTables = null): array {
                $query = DB::table('legacy_import_mappings')
                    ->where('source_system', self::SOURCE_SYSTEM)
                    ->where('target_table', $targetTable);

                $sourceTables === null
                    ? $query->where('source_table', $sourceTable)
                    : $query->whereIn('source_table', $sourceTables);

                $result = [];
                foreach ($query->get(['source_key', 'target_id']) as $row) {
                    $result[(int) $row->source_key] = (int) $row->target_id;
                }

                return $result;
            };

            $warehouses = $map('', 'warehouses', ['wh_Stores', 'wh_Cars']);
            $warehouseMeta = [];
            if ($warehouses !== []) {
                foreach (
                    DB::table('warehouses')
                        ->whereIn('id', array_values($warehouses))
                        ->get(['id', 'store_id']) as $row
                ) {
                    $warehouseMeta[(int) $row->id] = [
                        'store_id' => $row->store_id === null ? null : (int) $row->store_id,
                    ];
                }
            }

            return [
                'suppliers' => $map('wh_Suppliers', 'enterprise_suppliers'),
                'warehouses' => $warehouses,
                'warehouse_meta' => $warehouseMeta,
                'products' => $map('wh_Items', 'products'),
            ];
        });

        $parseDate = static function (mixed $value): ?string {
            $date = (int) $value;
            if ($date <= 0) {
                return null;
            }

            $raw = str_pad((string) $date, 8, '0', STR_PAD_LEFT);
            $parsed = \DateTimeImmutable::createFromFormat('!Ymd', $raw);
            if ($parsed === false || $parsed->format('Ymd') !== $raw) {
                return null;
            }

            return $parsed->format('Y-m-d');
        };

        $headerTypes = [];
        $headerFinancials = [];
        $headerDates = [];
        $typeCounts = [];
        $unmappedSuppliers = [];
        $unmappedLocations = [];
        $missingSourceSupplierHeaders = 0;
        $missingSourceSupplierNetTotal = 0.0;
        $invalidSupplierHeaderSamples = [];
        $missingSourceSupplierHeaderSamples = [];
        $invalidLocationHeaderSamples = [];
        $invalidHeaderDates = [];
        $headerCount = 0;

        $stage('stream_purchase_return_header_index', function () use (
            $source,
            $purchaseTypes,
            $maps,
            $parseDate,
            &$headerTypes,
            &$headerFinancials,
            &$headerDates,
            &$typeCounts,
            &$unmappedSuppliers,
            &$unmappedLocations,
            &$missingSourceSupplierHeaders,
            &$missingSourceSupplierNetTotal,
            &$invalidSupplierHeaderSamples,
            &$missingSourceSupplierHeaderSamples,
            &$invalidLocationHeaderSamples,
            &$invalidHeaderDates,
            &$headerCount,
        ): void {
            foreach (
                $source->table('wh_TransHeader')
                    ->select([
                        'HeaderId',
                        'TransType',
                        'TransDate',
                        'SupplierId',
                        'StoreId',
                        'Total',
                        'NetTotal',
                        'Paid',
                        'Balance',
                        'Status',
                    ])
                    ->where('Status', 1)
                    ->whereIn('TransType', $purchaseTypes)
                    ->orderBy('HeaderId')
                    ->cursor() as $header
            ) {
                $headerId = (int) $header->HeaderId;
                $type = (int) $header->TransType;
                $supplierId = (int) ($header->SupplierId ?? 0);
                $locationId = (int) ($header->StoreId ?? 0);
                $headerCount++;
                $headerTypes[$headerId] = $type;
                $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
                $headerFinancials[$headerId] = [
                    'total' => round((float) ($header->Total ?? 0), 3),
                    'net_total' => round((float) ($header->NetTotal ?? 0), 3),
                    'paid' => round((float) ($header->Paid ?? 0), 3),
                    'balance' => round((float) ($header->Balance ?? 0), 3),
                ];

                if ($supplierId <= 0) {
                    $missingSourceSupplierHeaders++;
                    $missingSourceSupplierNetTotal += (float) ($header->NetTotal ?? 0);
                    if (count($missingSourceSupplierHeaderSamples) < 20) {
                        $missingSourceSupplierHeaderSamples[] = [
                            'header_id' => $headerId,
                            'type' => $type,
                            'supplier_id' => $supplierId,
                            'location_id' => $locationId,
                            'total' => round((float) ($header->Total ?? 0), 3),
                            'net_total' => round((float) ($header->NetTotal ?? 0), 3),
                        ];
                    }
                } elseif (! isset($maps['suppliers'][$supplierId])) {
                    $unmappedSuppliers[$supplierId] = true;
                    if (count($invalidSupplierHeaderSamples) < 20) {
                        $invalidSupplierHeaderSamples[] = [
                            'header_id' => $headerId,
                            'type' => $type,
                            'supplier_id' => $supplierId,
                            'location_id' => $locationId,
                            'total' => round((float) ($header->Total ?? 0), 3),
                            'net_total' => round((float) ($header->NetTotal ?? 0), 3),
                        ];
                    }
                }

                $warehouseId = $maps['warehouses'][$locationId] ?? null;
                if (
                    $locationId <= 0
                    || $warehouseId === null
                    || ! isset($maps['warehouse_meta'][$warehouseId])
                    || $maps['warehouse_meta'][$warehouseId]['store_id'] === null
                ) {
                    $unmappedLocations[$locationId] = true;
                }

                $date = $parseDate($header->TransDate ?? null);
                if ($date === null) {
                    $invalidHeaderDates[$headerId] = (int) ($header->TransDate ?? 0);
                } else {
                    $headerDates[$headerId] = $date;
                }
            }
        });

        ksort($typeCounts);

        if ($apply && ($unmappedSuppliers !== [] || $unmappedLocations !== [] || $invalidHeaderDates !== [])) {
            throw new RuntimeException(
                'Purchase history apply refused before detail scan: invalid supplier/location/date mappings exist. '.
                json_encode([
                    'unmapped_suppliers' => array_keys($unmappedSuppliers),
                    'unmapped_locations' => array_keys($unmappedLocations),
                    'invalid_header_dates' => array_slice($invalidHeaderDates, 0, 20, true),
                    'invalid_supplier_headers' => $invalidSupplierHeaderSamples,
                    'missing_source_supplier_headers' => $missingSourceSupplierHeaderSamples,
                    'invalid_location_headers' => $invalidLocationHeaderSamples,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        $detailCounts = [];
        $detailValueSums = [];
        $usedItems = [];
        $sourceDetailRowsScanned = 0;
        $purchaseDetails = 0;
        $returnDetails = 0;
        $zeroQtyLines = 0;
        $negativeQtyLines = 0;
        $chunkNumber = 0;

        $stage('stream_purchase_return_details', function () use (
            $source,
            $progress,
            $headerTypes,
            &$detailCounts,
            &$detailValueSums,
            &$usedItems,
            &$sourceDetailRowsScanned,
            &$purchaseDetails,
            &$returnDetails,
            &$zeroQtyLines,
            &$negativeQtyLines,
            &$chunkNumber,
        ): void {
            $source->table('wh_TransDetails')
                ->select(['DetailId', 'HeaderId', 'ItemID', 'Qty', 'TransPkgQty1', 'Value', 'Status'])
                ->orderBy('DetailId')
                ->chunkById(
                    10000,
                    function ($rows) use (
                        $progress,
                        $headerTypes,
                        &$detailCounts,
                        &$detailValueSums,
                        &$usedItems,
                        &$sourceDetailRowsScanned,
                        &$purchaseDetails,
                        &$returnDetails,
                        &$zeroQtyLines,
                        &$negativeQtyLines,
                        &$chunkNumber,
                    ): void {
                        $chunkStartedAt = microtime(true);
                        $chunkNumber++;

                        foreach ($rows as $row) {
                            $sourceDetailRowsScanned++;

                            if ((int) ($row->Status ?? 0) !== 1) {
                                continue;
                            }

                            $headerId = (int) ($row->HeaderId ?? 0);
                            $type = $headerTypes[$headerId] ?? null;
                            if ($type === null) {
                                continue;
                            }

                            $detailCounts[$headerId] = ($detailCounts[$headerId] ?? 0) + 1;
                            $detailValueSums[$headerId] = round(
                                (float) ($detailValueSums[$headerId] ?? 0)
                                + (float) ($row->Value ?? 0),
                                3,
                            );

                            $itemId = (int) ($row->ItemID ?? 0);
                            if ($itemId > 0) {
                                $usedItems[$itemId] = true;
                            }

                            $qty = (float) ($row->Qty ?? 0);
                            $baseQty = (float) ($row->TransPkgQty1 ?? 0);
                            if ($qty < -0.0005 || $baseQty < -0.0005) {
                                $negativeQtyLines++;
                            } elseif (abs($qty) <= 0.0005 && abs($baseQty) <= 0.0005) {
                                $zeroQtyLines++;
                            }

                            if ($type === 1) {
                                $purchaseDetails++;
                            } else {
                                $returnDetails++;
                            }
                        }

                        if ($progress !== null) {
                            $progress(
                                'purchase_history_details_chunk_'.$chunkNumber.'_rows_'.$sourceDetailRowsScanned,
                                'done',
                                round(microtime(true) - $chunkStartedAt, 3),
                            );
                        }
                    },
                    'DetailId',
                    'DetailId',
                );
        });

        $unmappedItems = [];
        foreach (array_keys($usedItems) as $itemId) {
            if (! isset($maps['products'][$itemId])) {
                $unmappedItems[$itemId] = true;
            }
        }

        $headersWithoutLines = 0;
        $zeroEffectHeadersSkipped = [];
        $nonzeroHeadersWithoutLines = [];
        $lineTotalMismatches = 0;
        $lineTotalMismatchSamples = [];

        foreach ($headerTypes as $headerId => $type) {
            $lineCount = (int) ($detailCounts[$headerId] ?? 0);
            if ($lineCount === 0) {
                $headersWithoutLines++;
                $financial = $headerFinancials[$headerId];
                $zeroEffect = abs((float) $financial['total']) <= 0.0005
                    && abs((float) $financial['net_total']) <= 0.0005
                    && abs((float) $financial['paid']) <= 0.0005
                    && abs((float) $financial['balance']) <= 0.0005;

                if ($zeroEffect) {
                    $zeroEffectHeadersSkipped[] = $headerId;
                } else {
                    $nonzeroHeadersWithoutLines[] = $headerId;
                }
            }

            if ($lineCount > 0) {
                $headerTotal = round((float) ($headerFinancials[$headerId]['total'] ?? 0), 3);
                $lineTotal = round((float) ($detailValueSums[$headerId] ?? 0), 3);
                if (abs($headerTotal - $lineTotal) > 0.050) {
                    $lineTotalMismatches++;
                    if (count($lineTotalMismatchSamples) < 20) {
                        $lineTotalMismatchSamples[] = [
                            'header_id' => $headerId,
                            'type' => $type,
                            'header_total' => $headerTotal,
                            'detail_value_sum' => $lineTotal,
                            'difference' => round($headerTotal - $lineTotal, 3),
                            'detail_count' => $lineCount,
                        ];
                    }
                }
            }
        }

        $materializableHeaderIds = array_values(
            array_diff(array_keys($headerTypes), $zeroEffectHeadersSkipped)
        );

        $targetDocuments = (int) DB::table('legacy_procurement_documents')->count();
        $targetLines = (int) DB::table('legacy_procurement_document_lines')->count();

        $planned = [
            'source_headers' => $headerCount,
            'purchase_headers' => (int) ($typeCounts[1] ?? 0),
            'purchase_return_headers' => (int) ($typeCounts[2] ?? 0),
            'materialized_documents' => count($materializableHeaderIds),
            'zero_effect_headers_skipped' => count($zeroEffectHeadersSkipped),
            'purchase_details' => $purchaseDetails,
            'purchase_return_details' => $returnDetails,
            'source_detail_rows_scanned' => $sourceDetailRowsScanned,
            'unmapped_suppliers' => count($unmappedSuppliers),
            'missing_source_supplier_headers' => $missingSourceSupplierHeaders,
            'missing_source_supplier_net_total' => round($missingSourceSupplierNetTotal, 3),
            'unmapped_locations' => count($unmappedLocations),
            'unmapped_items' => count($unmappedItems),
            'invalid_header_dates' => count($invalidHeaderDates),
            'nonzero_headers_without_lines' => count($nonzeroHeadersWithoutLines),
            'zero_quantity_lines' => $zeroQtyLines,
            'negative_quantity_lines' => $negativeQtyLines,
            'line_total_mismatches' => $lineTotalMismatches,
            'existing_legacy_procurement_documents' => $targetDocuments,
            'existing_legacy_procurement_lines' => $targetLines,
        ];

        if (! $apply) {
            return [
                'mode' => 'dry-run',
                'phase' => 'purchase-history',
                'planned' => $planned,
                'type_counts' => $typeCounts,
                'samples' => [
                    'unmapped_suppliers' => array_slice(array_keys($unmappedSuppliers), 0, 30),
                    'unmapped_locations' => array_slice(array_keys($unmappedLocations), 0, 30),
                    'invalid_supplier_headers' => $invalidSupplierHeaderSamples,
                    'invalid_location_headers' => $invalidLocationHeaderSamples,
                    'unmapped_items' => array_slice(array_keys($unmappedItems), 0, 30),
                    'invalid_header_dates' => array_slice($invalidHeaderDates, 0, 20, true),
                    'nonzero_headers_without_lines' => array_slice($nonzeroHeadersWithoutLines, 0, 20),
                    'zero_effect_headers_skipped' => array_slice($zeroEffectHeadersSkipped, 0, 20),
                    'line_total_mismatches' => $lineTotalMismatchSamples,
                ],
                'rules' => [
                    'historical_procurement_is_immutable' => true,
                    'does_not_create_synthetic_purchase_requests_or_users' => true,
                    'source_supplier_zero_is_preserved_with_nullable_target_supplier' => true,
                    'positive_unmapped_supplier_ids_block_apply' => true,
                    'does_not_mutate_inventory' => true,
                    'does_not_post_to_finance_or_treasury' => true,
                    'purchase_type' => 1,
                    'purchase_return_type' => 2,
                ],
            ];
        }

        $blocking = [
            'unmapped_suppliers' => count($unmappedSuppliers),
            'unmapped_locations' => count($unmappedLocations),
            'unmapped_items' => count($unmappedItems),
            'invalid_header_dates' => count($invalidHeaderDates),
            'nonzero_headers_without_lines' => count($nonzeroHeadersWithoutLines),
        ];

        if (array_sum($blocking) !== 0) {
            throw new RuntimeException(
                'Purchase history apply refused: source-to-target validation has blocking issues. '.
                json_encode($blocking, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        if ($targetDocuments !== 0 || $targetLines !== 0) {
            throw new RuntimeException(
                'Purchase history apply refused: immutable legacy procurement history tables are not empty.'
            );
        }

        $runId = (string) Str::uuid();
        DB::table('legacy_import_runs')->insert([
            'id' => $runId,
            'source_system' => self::SOURCE_SYSTEM,
            'phase' => 'purchase-history',
            'mode' => 'apply',
            'status' => 'running',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $summary = DB::transaction(function () use (
                $source,
                $progress,
                $materializableHeaderIds,
                $zeroEffectHeadersSkipped,
                $headerDates,
                $detailValueSums,
                $maps,
                $runId,
                $planned,
            ): array {
                $materializable = array_fill_keys(
                    array_map(static fn ($id): int => (int) $id, $materializableHeaderIds),
                    true,
                );

                $documentsCreated = 0;
                $linesCreated = 0;
                $documentIds = [];
                $headerBatch = [];
                $headerBatchNumber = 0;
                $mappingRows = [];

                $flushMappings = function () use (&$mappingRows): void {
                    if ($mappingRows === []) {
                        return;
                    }

                    DB::table('legacy_import_mappings')->upsert(
                        $mappingRows,
                        ['source_system', 'source_table', 'source_key', 'target_table'],
                        ['target_id', 'source_hash', 'metadata', 'last_run_id', 'updated_at'],
                    );
                    $mappingRows = [];
                };

                $flushHeaders = function (array $batch) use (
                    &$documentsCreated,
                    &$documentIds,
                    &$headerBatchNumber,
                    &$mappingRows,
                    $maps,
                    $headerDates,
                    $detailValueSums,
                    $runId,
                    $progress,
                    $flushMappings,
                ): void {
                    if ($batch === []) {
                        return;
                    }

                    $headerBatchNumber++;
                    $startedAt = microtime(true);
                    if ($progress !== null) {
                        $progress('materialize_purchase_headers_batch_'.$headerBatchNumber, 'start', null);
                    }

                    $rows = [];
                    foreach ($batch as $header) {
                        $headerId = (int) $header->HeaderId;
                        $supplierSourceId = (int) $header->SupplierId;
                        $sourceLocationId = (int) $header->StoreId;
                        $warehouseId = $maps['warehouses'][$sourceLocationId];
                        $storeId = (int) $maps['warehouse_meta'][$warehouseId]['store_id'];
                        $grossTotal = round(
                            (float) ($detailValueSums[$headerId] ?? ($header->Total ?? 0)),
                            3,
                        );

                        $rows[] = [
                            'source_system' => self::SOURCE_SYSTEM,
                            'source_header_id' => $headerId,
                            'source_trans_type' => (int) $header->TransType,
                            'document_kind' => (int) $header->TransType === 1 ? 'purchase' : 'purchase_return',
                            'document_date' => $headerDates[$headerId],
                            'source_supplier_id' => $supplierSourceId,
                            'supplier_id' => $supplierSourceId > 0
                                ? ($maps['suppliers'][$supplierSourceId] ?? null)
                                : null,
                            'source_location_id' => $sourceLocationId,
                            'warehouse_id' => $warehouseId,
                            'store_id' => $storeId,
                            'currency_code' => 'EGP',
                            'gross_total' => $grossTotal,
                            'net_total' => round((float) ($header->NetTotal ?? 0), 3),
                            'paid_amount' => round((float) ($header->Paid ?? 0), 3),
                            'legacy_balance' => round((float) ($header->Balance ?? 0), 3),
                            'legacy_price_type' => $header->PriceType === null ? null : (int) $header->PriceType,
                            'legacy_pay_type' => $header->PayType === null ? null : (int) $header->PayType,
                            'document_number' => $this->legacyText($header->DocTransNo ?? null),
                            'source_code' => $this->legacyText($header->Code ?? null),
                            'reference_number' => $this->legacyText($header->ReferenceNo ?? null),
                            'notes' => $this->legacyText($header->Notes ?? null),
                            'source_payload' => $this->json((array) $header),
                            'last_run_id' => $runId,
                            'created_at' => $headerDates[$headerId].' 00:00:00',
                            'updated_at' => $headerDates[$headerId].' 00:00:00',
                        ];
                    }

                    foreach (array_chunk($rows, 500) as $chunk) {
                        DB::table('legacy_procurement_documents')->insert($chunk);
                    }
                    $documentsCreated += count($rows);

                    $inserted = DB::table('legacy_procurement_documents')
                        ->where('source_system', self::SOURCE_SYSTEM)
                        ->whereIn('source_header_id', array_map(
                            static fn ($header): int => (int) $header->HeaderId,
                            $batch,
                        ))
                        ->get(['id', 'source_header_id'])
                        ->keyBy('source_header_id');

                    foreach ($batch as $header) {
                        $headerId = (int) $header->HeaderId;
                        $document = $inserted->get($headerId);
                        if ($document === null) {
                            throw new RuntimeException(
                                "Inserted legacy procurement document {$headerId} could not be resolved."
                            );
                        }

                        $documentId = (int) $document->id;
                        $documentIds[$headerId] = $documentId;
                        $sourceArray = (array) $header;
                        $mappingRows[] = [
                            'source_system' => self::SOURCE_SYSTEM,
                            'source_table' => 'wh_TransHeader',
                            'source_key' => (string) $headerId,
                            'target_table' => 'legacy_procurement_documents',
                            'target_id' => $documentId,
                            'source_hash' => hash('sha256', $this->json($sourceArray)),
                            'metadata' => $this->json([
                                'source' => $sourceArray,
                                '_migration' => [
                                    'phase' => 'purchase-history',
                                    'role' => (int) $header->TransType === 1 ? 'purchase' : 'purchase_return',
                                ],
                            ]),
                            'last_run_id' => $runId,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }

                    if (count($mappingRows) >= 1000) {
                        $flushMappings();
                    }

                    if ($progress !== null) {
                        $progress(
                            'materialize_purchase_headers_batch_'.$headerBatchNumber.'_headers_'.$documentsCreated,
                            'done',
                            round(microtime(true) - $startedAt, 3),
                        );
                    }
                };

                $headersStartedAt = microtime(true);
                if ($progress !== null) {
                    $progress('stream_materialize_purchase_headers', 'start', null);
                }

                foreach (
                    $source->table('wh_TransHeader')
                        ->select([
                            'HeaderId',
                            'TransType',
                            'TransDate',
                            'SupplierId',
                            'StoreId',
                            'Code',
                            'DocTransNo',
                            'ReferenceNo',
                            'PriceType',
                            'PayType',
                            'Paid',
                            'Total',
                            'NetTotal',
                            'Balance',
                            'Notes',
                            'Status',
                        ])
                        ->where('Status', 1)
                        ->whereIn('TransType', [1, 2])
                        ->orderBy('HeaderId')
                        ->cursor() as $header
                ) {
                    $headerId = (int) $header->HeaderId;
                    if (! isset($materializable[$headerId])) {
                        continue;
                    }

                    $headerBatch[] = $header;
                    if (count($headerBatch) >= 500) {
                        $flushHeaders($headerBatch);
                        $headerBatch = [];
                    }
                }

                if ($headerBatch !== []) {
                    $flushHeaders($headerBatch);
                }

                if ($documentsCreated !== count($materializableHeaderIds)) {
                    throw new RuntimeException(
                        'Purchase history source changed during apply: header count no longer matches validation.'
                    );
                }

                if ($progress !== null) {
                    $progress(
                        'stream_materialize_purchase_headers',
                        'done',
                        round(microtime(true) - $headersStartedAt, 3),
                    );
                }

                $flushMappings();

                $detailsStartedAt = microtime(true);
                $detailChunkNumber = 0;
                $detailRowsScanned = 0;
                if ($progress !== null) {
                    $progress('stream_materialize_purchase_details', 'start', null);
                }

                $source->table('wh_TransDetails')
                    ->select([
                        'DetailId',
                        'HeaderId',
                        'ItemID',
                        'Package',
                        'Qty',
                        'TransPkgQty1',
                        'Price',
                        'TransPkgPrice1',
                        'Value',
                        'CostPrice',
                        'DetailDiscount1',
                        'DetailDiscount2',
                        'DetailDiscount3',
                        'DetailTax',
                        'DetailRemark',
                        'Status',
                    ])
                    ->orderBy('DetailId')
                    ->chunkById(
                        10000,
                        function ($rows) use (
                            &$detailChunkNumber,
                            &$detailRowsScanned,
                            &$linesCreated,
                            &$mappingRows,
                            $documentIds,
                            $maps,
                            $runId,
                            $progress,
                            $flushMappings,
                        ): void {
                            $detailChunkNumber++;
                            $startedAt = microtime(true);
                            $writeTimestamp = date('Y-m-d H:i:s');
                            $insertRows = [];
                            $mappingPayloads = [];

                            foreach ($rows as $line) {
                                $detailRowsScanned++;

                                if ((int) ($line->Status ?? 0) !== 1) {
                                    continue;
                                }

                                $headerId = (int) ($line->HeaderId ?? 0);
                                if (! isset($documentIds[$headerId])) {
                                    continue;
                                }

                                $sourceItemId = (int) $line->ItemID;
                                $productId = $maps['products'][$sourceItemId];
                                $discount = round(
                                    (float) ($line->DetailDiscount1 ?? 0)
                                    + (float) ($line->DetailDiscount2 ?? 0)
                                    + (float) ($line->DetailDiscount3 ?? 0),
                                    3,
                                );

                                $insertRows[] = [
                                    'document_id' => $documentIds[$headerId],
                                    'source_detail_id' => (int) $line->DetailId,
                                    'source_item_id' => $sourceItemId,
                                    'product_id' => $productId,
                                    'package' => $line->Package === null ? null : (int) $line->Package,
                                    'raw_quantity' => round((float) ($line->Qty ?? 0), 3),
                                    'base_quantity' => round((float) ($line->TransPkgQty1 ?? 0), 3),
                                    'unit_price' => round((float) ($line->Price ?? 0), 3),
                                    'base_unit_price' => round((float) ($line->TransPkgPrice1 ?? 0), 3),
                                    'line_value' => round((float) ($line->Value ?? 0), 3),
                                    'cost_price' => round((float) ($line->CostPrice ?? 0), 3),
                                    'discount_total' => $discount,
                                    'tax_total' => round((float) ($line->DetailTax ?? 0), 3),
                                    'source_payload' => $this->json((array) $line),
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ];
                                $mappingSource[(int) $line->DetailId] = (array) $line;
                            }

                            if ($insertRows !== []) {
                                foreach (array_chunk($insertRows, 500) as $chunk) {
                                    DB::table('legacy_procurement_document_lines')->insert($chunk);
                                }

                                $sourceIds = array_column($insertRows, 'source_detail_id');
                                $inserted = DB::table('legacy_procurement_document_lines')
                                    ->whereIn('source_detail_id', $sourceIds)
                                    ->get(['id', 'source_detail_id'])
                                    ->keyBy('source_detail_id');

                                foreach ($sourceIds as $sourceDetailId) {
                                    $target = $inserted->get($sourceDetailId);
                                    if ($target === null) {
                                        throw new RuntimeException(
                                            "Inserted legacy procurement line {$sourceDetailId} could not be resolved."
                                        );
                                    }

                                    $sourceArray = $mappingSource[$sourceDetailId];
                                    $mappingRows[] = [
                                        'source_system' => self::SOURCE_SYSTEM,
                                        'source_table' => 'wh_TransDetails',
                                        'source_key' => (string) $sourceDetailId,
                                        'target_table' => 'legacy_procurement_document_lines',
                                        'target_id' => (int) $target->id,
                                        'source_hash' => hash('sha256', $this->json($sourceArray)),
                                        'metadata' => $this->json([
                                            'source' => $sourceArray,
                                            '_migration' => [
                                                'phase' => 'purchase-history',
                                                'role' => 'procurement_line',
                                            ],
                                        ]),
                                        'last_run_id' => $runId,
                                        'created_at' => now(),
                                        'updated_at' => now(),
                                    ];
                                }

                                $linesCreated += count($insertRows);
                                if (count($mappingRows) >= 500) {
                                    $flushMappings();
                                }
                            }

                            if ($progress !== null) {
                                $progress(
                                    'materialize_purchase_details_chunk_'.$detailChunkNumber.'_rows_'.$detailRowsScanned,
                                    'done',
                                    round(microtime(true) - $startedAt, 3),
                                );
                            }
                        },
                        'DetailId',
                        'DetailId',
                    );

                if ($progress !== null) {
                    $progress(
                        'stream_materialize_purchase_details',
                        'done',
                        round(microtime(true) - $detailsStartedAt, 3),
                    );
                }

                $flushMappings();

                $skipped = 0;
                foreach ($zeroEffectHeadersSkipped as $headerId) {
                    DB::table('legacy_import_quarantine')->updateOrInsert(
                        [
                            'source_system' => self::SOURCE_SYSTEM,
                            'source_table' => 'wh_TransHeader',
                            'source_key' => (string) $headerId,
                            'reason' => 'zero_effect_empty_procurement_header_skipped',
                        ],
                        [
                            'payload' => $this->json([
                                'source_header_id' => $headerId,
                                '_migration' => [
                                    'phase' => 'purchase-history',
                                    'decision' => 'skip',
                                    'reason' => 'No detail rows and zero Total/NetTotal/Paid/Balance.',
                                ],
                            ]),
                            'run_id' => $runId,
                            'resolved_at' => now(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                    );
                    $skipped++;
                }

                $targetDocuments = (int) DB::table('legacy_procurement_documents')->count();
                $targetLines = (int) DB::table('legacy_procurement_document_lines')->count();

                if (
                    $targetDocuments !== $documentsCreated
                    || $targetLines !== $linesCreated
                ) {
                    throw new RuntimeException(
                        'Purchase history target validation failed: target counts do not match materialized counts.'
                    );
                }

                return [
                    'run_id' => $runId,
                    'planned' => $planned,
                    'documents' => ['created' => $documentsCreated],
                    'lines' => ['created' => $linesCreated],
                    'skipped_zero_effect_headers' => [
                        'count' => $skipped,
                        'source_header_ids' => $zeroEffectHeadersSkipped,
                    ],
                    'target' => [
                        'documents' => $targetDocuments,
                        'lines' => $targetLines,
                    ],
                    'inventory_mutations' => 0,
                    'finance_mutations' => 0,
                ];
            }, 3);

            DB::table('legacy_import_runs')->where('id', $runId)->update([
                'status' => 'completed',
                'summary' => $this->json($summary),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'mode' => 'apply',
                'phase' => 'purchase-history',
                'status' => 'completed',
                'summary' => $summary,
            ];
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

    public function importOperationalHistory(bool $apply, ?callable $progress = null): array
    {
        $this->assertTrackingTables();

        foreach (['legacy_operational_documents', 'legacy_operational_document_lines'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException(
                    "Missing {$table}. Run php artisan migrate before operational-history import."
                );
            }
        }

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

        $source = DB::connection('foodex_legacy');

        $typeRules = [
            5 => ['kind' => 'damaged', 'sign' => -1, 'pair_family' => null, 'pair_role' => null],
            7 => ['kind' => 'warehouse_transfer', 'sign' => 1, 'pair_family' => 'warehouse_transfer', 'pair_role' => 'to'],
            8 => ['kind' => 'warehouse_transfer', 'sign' => -1, 'pair_family' => 'warehouse_transfer', 'pair_role' => 'from'],
            11 => ['kind' => 'inventory_addition', 'sign' => 1, 'pair_family' => null, 'pair_role' => null],
            12 => ['kind' => 'inventory_deficit', 'sign' => -1, 'pair_family' => null, 'pair_role' => null],
            13 => ['kind' => 'car_transfer', 'sign' => 1, 'pair_family' => 'car_to', 'pair_role' => 'car_in'],
            14 => ['kind' => 'car_transfer', 'sign' => -1, 'pair_family' => 'car_from', 'pair_role' => 'car_out'],
            15 => ['kind' => 'car_transfer', 'sign' => -1, 'pair_family' => 'car_to', 'pair_role' => 'store_out'],
            16 => ['kind' => 'car_transfer', 'sign' => 1, 'pair_family' => 'car_from', 'pair_role' => 'store_in'],
            17 => ['kind' => 'car_wrapper', 'sign' => 0, 'pair_family' => 'car_wrapper', 'pair_role' => 'upload'],
            18 => ['kind' => 'car_wrapper', 'sign' => 0, 'pair_family' => 'car_wrapper', 'pair_role' => 'download'],
            22 => ['kind' => 'production', 'sign' => 1, 'pair_family' => 'production', 'pair_role' => 'output'],
            23 => ['kind' => 'production', 'sign' => -1, 'pair_family' => 'production', 'pair_role' => 'material_issue'],
        ];
        $operationalTypes = array_keys($typeRules);

        $maps = $stage('load_operational_history_mappings', function (): array {
            $warehouseMappings = DB::table('legacy_import_mappings')
                ->where('source_system', self::SOURCE_SYSTEM)
                ->where('target_table', 'warehouses')
                ->whereIn('source_table', ['wh_Stores', 'wh_Cars'])
                ->get(['source_key', 'target_id']);

            $warehouses = [];
            foreach ($warehouseMappings as $row) {
                $warehouses[(int) $row->source_key] = (int) $row->target_id;
            }

            $warehouseMeta = [];
            if ($warehouses !== []) {
                foreach (
                    DB::table('warehouses')
                        ->whereIn('id', array_values($warehouses))
                        ->get(['id', 'store_id']) as $row
                ) {
                    $warehouseMeta[(int) $row->id] = [
                        'store_id' => $row->store_id === null ? null : (int) $row->store_id,
                    ];
                }
            }

            $products = [];
            foreach (
                DB::table('legacy_import_mappings')
                    ->where('source_system', self::SOURCE_SYSTEM)
                    ->where('source_table', 'wh_Items')
                    ->where('target_table', 'products')
                    ->get(['source_key', 'target_id']) as $row
            ) {
                $products[(int) $row->source_key] = (int) $row->target_id;
            }

            return [
                'warehouses' => $warehouses,
                'warehouse_meta' => $warehouseMeta,
                'products' => $products,
            ];
        });

        $parseDate = static function (mixed $value): ?string {
            $date = (int) $value;
            if ($date <= 0) {
                return null;
            }

            $raw = str_pad((string) $date, 8, '0', STR_PAD_LEFT);
            $parsed = \DateTimeImmutable::createFromFormat('!Ymd', $raw);
            if ($parsed === false || $parsed->format('Ymd') !== $raw) {
                return null;
            }

            return $parsed->format('Y-m-d');
        };

        $headerTypes = [];
        $headerDates = [];
        $headerPairMeta = [];
        $typeCounts = [];
        $unmappedLocations = [];
        $invalidLocationHeaderSamples = [];
        $invalidHeaderDates = [];
        $pairGroups = [];
        $headerCount = 0;

        $stage('stream_operational_header_index', function () use (
            $source,
            $operationalTypes,
            $typeRules,
            $maps,
            $parseDate,
            &$headerTypes,
            &$headerDates,
            &$headerPairMeta,
            &$typeCounts,
            &$unmappedLocations,
            &$invalidLocationHeaderSamples,
            &$invalidHeaderDates,
            &$pairGroups,
            &$headerCount,
        ): void {
            foreach (
                $source->table('wh_TransHeader')
                    ->select([
                        'HeaderId',
                        'TransType',
                        'TransDate',
                        'StoreId',
                        'toStoreId',
                        'TransferHeaderID',
                        'CarHeaderId',
                        'ProductionId',
                        'Status',
                    ])
                    ->where('Status', 1)
                    ->whereIn('TransType', $operationalTypes)
                    ->orderBy('HeaderId')
                    ->cursor() as $header
            ) {
                $headerId = (int) $header->HeaderId;
                $type = (int) $header->TransType;
                $rule = $typeRules[$type];
                $locationId = (int) ($header->StoreId ?? 0);
                $headerCount++;
                $headerTypes[$headerId] = $type;
                $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;

                $warehouseId = $maps['warehouses'][$locationId] ?? null;
                $locationRequired = (int) $rule['sign'] !== 0;
                if (
                    $locationRequired
                    && (
                        $locationId <= 0
                        || $warehouseId === null
                        || ! isset($maps['warehouse_meta'][$warehouseId])
                        || $maps['warehouse_meta'][$warehouseId]['store_id'] === null
                    )
                ) {
                    $unmappedLocations[$locationId] = true;
                    if (count($invalidLocationHeaderSamples) < 20) {
                        $invalidLocationHeaderSamples[] = [
                            'header_id' => $headerId,
                            'type' => $type,
                            'location_id' => $locationId,
                        ];
                    }
                }

                $date = $parseDate($header->TransDate ?? null);
                if ($date === null) {
                    $invalidHeaderDates[$headerId] = (int) ($header->TransDate ?? 0);
                } else {
                    $headerDates[$headerId] = $date;
                }

                $pairFamily = $rule['pair_family'];
                $pairKey = null;
                if ($pairFamily === 'warehouse_transfer') {
                    $value = (int) ($header->TransferHeaderID ?? 0);
                    $pairKey = $value > 0 ? (string) $value : null;
                } elseif (in_array($pairFamily, ['car_to', 'car_from', 'car_wrapper'], true)) {
                    $value = (int) ($header->CarHeaderId ?? 0);
                    $pairKey = $value > 0 ? (string) $value : null;
                } elseif ($pairFamily === 'production') {
                    $value = (int) ($header->ProductionId ?? 0);
                    $pairKey = $value > 0 ? (string) $value : null;
                }

                $headerPairMeta[$headerId] = [
                    'pair_family' => $pairFamily,
                    'pair_group_key' => $pairKey,
                    'pair_role' => $rule['pair_role'],
                ];

                if ($pairFamily !== null && $pairKey !== null) {
                    $groupKey = $pairFamily.':'.$pairKey;
                    if (! isset($pairGroups[$groupKey])) {
                        $pairGroups[$groupKey] = [
                            'family' => $pairFamily,
                            'key' => $pairKey,
                            'types' => [],
                            'headers' => 0,
                        ];
                    }
                    $pairGroups[$groupKey]['headers']++;
                    $pairGroups[$groupKey]['types'][$type] = ($pairGroups[$groupKey]['types'][$type] ?? 0) + 1;
                }
            }
        });

        ksort($typeCounts);

        if ($apply && ($unmappedLocations !== [] || $invalidHeaderDates !== [])) {
            throw new RuntimeException(
                'Operational history apply refused before detail scan: invalid location/date mappings exist. '.
                json_encode([
                    'unmapped_locations' => array_keys($unmappedLocations),
                    'invalid_header_dates' => array_slice($invalidHeaderDates, 0, 20, true),
                    'invalid_location_headers' => $invalidLocationHeaderSamples,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        $detailCounts = [];
        $usedItems = [];
        $detailsByType = [];
        $sourceDetailRowsScanned = 0;
        $zeroQtyLines = 0;
        $negativeQtyLines = 0;
        $chunkNumber = 0;

        $stage('stream_operational_details', function () use (
            $source,
            $progress,
            $headerTypes,
            &$detailCounts,
            &$usedItems,
            &$detailsByType,
            &$sourceDetailRowsScanned,
            &$zeroQtyLines,
            &$negativeQtyLines,
            &$chunkNumber,
        ): void {
            $source->table('wh_TransDetails')
                ->select(['DetailId', 'HeaderId', 'ItemID', 'Qty', 'TransPkgQty1', 'Status'])
                ->orderBy('DetailId')
                ->chunkById(
                    10000,
                    function ($rows) use (
                        $progress,
                        $headerTypes,
                        &$detailCounts,
                        &$usedItems,
                        &$detailsByType,
                        &$sourceDetailRowsScanned,
                        &$zeroQtyLines,
                        &$negativeQtyLines,
                        &$chunkNumber,
                    ): void {
                        $startedAt = microtime(true);
                        $chunkNumber++;

                        foreach ($rows as $row) {
                            $sourceDetailRowsScanned++;

                            if ((int) ($row->Status ?? 0) !== 1) {
                                continue;
                            }

                            $headerId = (int) ($row->HeaderId ?? 0);
                            $type = $headerTypes[$headerId] ?? null;
                            if ($type === null) {
                                continue;
                            }

                            $detailCounts[$headerId] = ($detailCounts[$headerId] ?? 0) + 1;
                            $detailsByType[$type] = ($detailsByType[$type] ?? 0) + 1;

                            $itemId = (int) ($row->ItemID ?? 0);
                            if ($itemId > 0) {
                                $usedItems[$itemId] = true;
                            }

                            $rawQty = (float) ($row->Qty ?? 0);
                            $baseQty = (float) ($row->TransPkgQty1 ?? 0);
                            if ($rawQty < -0.0005 || $baseQty < -0.0005) {
                                $negativeQtyLines++;
                            } elseif (abs($rawQty) <= 0.0005 && abs($baseQty) <= 0.0005) {
                                $zeroQtyLines++;
                            }
                        }

                        if ($progress !== null) {
                            $progress(
                                'operational_details_chunk_'.$chunkNumber.'_rows_'.$sourceDetailRowsScanned,
                                'done',
                                round(microtime(true) - $startedAt, 3),
                            );
                        }
                    },
                    'DetailId',
                    'DetailId',
                );
        });

        $unmappedItems = [];
        foreach (array_keys($usedItems) as $itemId) {
            if (! isset($maps['products'][$itemId])) {
                $unmappedItems[$itemId] = true;
            }
        }

        $headersWithoutLines = 0;
        foreach ($headerTypes as $headerId => $type) {
            if (($detailCounts[$headerId] ?? 0) === 0) {
                $headersWithoutLines++;
            }
        }

        $pairDiagnostics = [
            'warehouse_transfer_complete' => 0,
            'warehouse_transfer_incomplete' => 0,
            'car_to_complete' => 0,
            'car_to_incomplete' => 0,
            'car_from_complete' => 0,
            'car_from_incomplete' => 0,
            'production_groups' => 0,
            'car_wrapper_groups' => 0,
        ];
        $pairAnomalySamples = [];

        foreach ($pairGroups as $group) {
            $types = $group['types'];
            $family = $group['family'];
            $complete = true;

            if ($family === 'warehouse_transfer') {
                $complete = ($types[7] ?? 0) === 1 && ($types[8] ?? 0) === 1;
                $pairDiagnostics[$complete ? 'warehouse_transfer_complete' : 'warehouse_transfer_incomplete']++;
            } elseif ($family === 'car_to') {
                $complete = ($types[13] ?? 0) === 1 && ($types[15] ?? 0) === 1;
                $pairDiagnostics[$complete ? 'car_to_complete' : 'car_to_incomplete']++;
            } elseif ($family === 'car_from') {
                $complete = ($types[14] ?? 0) === 1 && ($types[16] ?? 0) === 1;
                $pairDiagnostics[$complete ? 'car_from_complete' : 'car_from_incomplete']++;
            } elseif ($family === 'production') {
                $pairDiagnostics['production_groups']++;
            } elseif ($family === 'car_wrapper') {
                $pairDiagnostics['car_wrapper_groups']++;
            }

            if (! $complete && count($pairAnomalySamples) < 20) {
                $pairAnomalySamples[] = [
                    'family' => $family,
                    'key' => $group['key'],
                    'types' => $types,
                    'headers' => $group['headers'],
                ];
            }
        }

        $targetDocuments = (int) DB::table('legacy_operational_documents')->count();
        $targetLines = (int) DB::table('legacy_operational_document_lines')->count();
        $operationalDetailCount = array_sum($detailsByType);

        $planned = [
            'source_headers' => $headerCount,
            'materialized_documents' => $headerCount,
            'operational_details' => $operationalDetailCount,
            'source_detail_rows_scanned' => $sourceDetailRowsScanned,
            'unmapped_locations' => count($unmappedLocations),
            'unmapped_items' => count($unmappedItems),
            'invalid_header_dates' => count($invalidHeaderDates),
            'headers_without_lines' => $headersWithoutLines,
            'zero_quantity_lines' => $zeroQtyLines,
            'negative_quantity_lines' => $negativeQtyLines,
            'warehouse_transfer_complete_pairs' => $pairDiagnostics['warehouse_transfer_complete'],
            'warehouse_transfer_incomplete_pairs' => $pairDiagnostics['warehouse_transfer_incomplete'],
            'car_to_complete_pairs' => $pairDiagnostics['car_to_complete'],
            'car_to_incomplete_pairs' => $pairDiagnostics['car_to_incomplete'],
            'car_from_complete_pairs' => $pairDiagnostics['car_from_complete'],
            'car_from_incomplete_pairs' => $pairDiagnostics['car_from_incomplete'],
            'production_groups' => $pairDiagnostics['production_groups'],
            'existing_operational_documents' => $targetDocuments,
            'existing_operational_lines' => $targetLines,
        ];

        if (! $apply) {
            return [
                'mode' => 'dry-run',
                'phase' => 'operational-history',
                'planned' => $planned,
                'type_counts' => $typeCounts,
                'detail_counts_by_type' => $detailsByType,
                'pairing' => $pairDiagnostics,
                'samples' => [
                    'unmapped_locations' => array_slice(array_keys($unmappedLocations), 0, 30),
                    'unmapped_items' => array_slice(array_keys($unmappedItems), 0, 30),
                    'invalid_header_dates' => array_slice($invalidHeaderDates, 0, 20, true),
                    'invalid_location_headers' => $invalidLocationHeaderSamples,
                    'pair_anomalies' => $pairAnomalySamples,
                ],
                'rules' => [
                    'historical_operational_data_is_immutable' => true,
                    'does_not_mutate_inventory' => true,
                    'does_not_create_historical_stock_movements' => true,
                    'does_not_post_to_finance' => true,
                    'paired_movements_are_preserved_as_source_documents_not_reposted' => true,
                    'stock_signs_match_canonical_foodex_semantics' => true,
                ],
            ];
        }

        $blocking = [
            'unmapped_locations' => count($unmappedLocations),
            'unmapped_items' => count($unmappedItems),
            'invalid_header_dates' => count($invalidHeaderDates),
        ];

        if (array_sum($blocking) !== 0) {
            throw new RuntimeException(
                'Operational history apply refused: source-to-target validation has blocking issues. '.
                json_encode($blocking, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        if ($targetDocuments !== 0 || $targetLines !== 0) {
            throw new RuntimeException(
                'Operational history apply refused: immutable operational history tables are not empty.'
            );
        }

        DB::table('legacy_import_runs')
            ->where('source_system', self::SOURCE_SYSTEM)
            ->where('phase', 'operational-history')
            ->where('status', 'running')
            ->update([
                'status' => 'aborted',
                'error' => 'Previous operational-history process terminated before completion; target history was clean on retry.',
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

        unset(
            $headerTypes,
            $detailCounts,
            $usedItems,
            $detailsByType,
            $unmappedLocations,
            $invalidLocationHeaderSamples,
            $invalidHeaderDates,
            $pairGroups,
            $pairAnomalySamples,
        );
        gc_collect_cycles();

        $runId = (string) Str::uuid();
        DB::table('legacy_import_runs')->insert([
            'id' => $runId,
            'source_system' => self::SOURCE_SYSTEM,
            'phase' => 'operational-history',
            'mode' => 'apply',
            'status' => 'running',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $summary = DB::transaction(function () use (
                $source,
                $progress,
                $operationalTypes,
                $typeRules,
                $headerDates,
                $headerPairMeta,
                $maps,
                $runId,
                $planned,
            ): array {
                $documentsCreated = 0;
                $linesCreated = 0;
                $documentIds = [];
                $mappingRows = [];
                $headerBatch = [];
                $headerBatchNumber = 0;

                $flushMappings = function () use (&$mappingRows): void {
                    if ($mappingRows === []) {
                        return;
                    }

                    DB::table('legacy_import_mappings')->upsert(
                        $mappingRows,
                        ['source_system', 'source_table', 'source_key', 'target_table'],
                        ['target_id', 'source_hash', 'metadata', 'last_run_id', 'updated_at'],
                    );
                    $mappingRows = [];
                };

                $flushHeaders = function (array $batch) use (
                    &$documentsCreated,
                    &$documentIds,
                    &$mappingRows,
                    &$headerBatchNumber,
                    $typeRules,
                    $headerDates,
                    $headerPairMeta,
                    $maps,
                    $runId,
                    $progress,
                    $flushMappings,
                ): void {
                    if ($batch === []) {
                        return;
                    }

                    $headerBatchNumber++;
                    $startedAt = microtime(true);
                    if ($progress !== null) {
                        $progress('materialize_operational_headers_batch_'.$headerBatchNumber, 'start', null);
                    }

                    $rows = [];
                    foreach ($batch as $header) {
                        $headerId = (int) $header->HeaderId;
                        $type = (int) $header->TransType;
                        $rule = $typeRules[$type];
                        $locationId = (int) ($header->StoreId ?? 0);
                        $warehouseId = $maps['warehouses'][$locationId] ?? null;
                        $storeId = $warehouseId === null
                            ? null
                            : ($maps['warehouse_meta'][$warehouseId]['store_id'] ?? null);
                        $pair = $headerPairMeta[$headerId] ?? [
                            'pair_family' => null,
                            'pair_group_key' => null,
                            'pair_role' => null,
                        ];

                        $rows[] = [
                            'source_system' => self::SOURCE_SYSTEM,
                            'source_header_id' => $headerId,
                            'source_trans_type' => $type,
                            'movement_kind' => $rule['kind'],
                            'stock_sign' => (int) $rule['sign'],
                            'document_date' => $headerDates[$headerId],
                            'source_location_id' => $locationId > 0 ? $locationId : null,
                            'warehouse_id' => $warehouseId,
                            'store_id' => $storeId,
                            'source_target_location_id' => (int) ($header->toStoreId ?? 0) > 0
                                ? (int) $header->toStoreId
                                : null,
                            'source_transfer_header_id' => (int) ($header->TransferHeaderID ?? 0) > 0
                                ? (int) $header->TransferHeaderID
                                : null,
                            'source_car_header_id' => (int) ($header->CarHeaderId ?? 0) > 0
                                ? (int) $header->CarHeaderId
                                : null,
                            'source_production_id' => (int) ($header->ProductionId ?? 0) > 0
                                ? (int) $header->ProductionId
                                : null,
                            'pair_family' => $pair['pair_family'],
                            'pair_group_key' => $pair['pair_group_key'],
                            'pair_role' => $pair['pair_role'],
                            'gross_total' => round((float) ($header->Total ?? 0), 3),
                            'net_total' => round((float) ($header->NetTotal ?? 0), 3),
                            'document_number' => $this->legacyText($header->DocTransNo ?? null),
                            'source_code' => $this->legacyText($header->Code ?? null),
                            'reference_number' => $this->legacyText($header->ReferenceNo ?? null),
                            'notes' => $this->legacyText($header->Notes ?? null),
                            'source_payload' => $this->json((array) $header),
                            'last_run_id' => $runId,
                            'created_at' => $headerDates[$headerId].' 00:00:00',
                            'updated_at' => $headerDates[$headerId].' 00:00:00',
                        ];
                    }

                    foreach (array_chunk($rows, 500) as $chunk) {
                        DB::table('legacy_operational_documents')->insert($chunk);
                    }
                    $documentsCreated += count($rows);

                    $sourceHeaderIds = array_map(
                        static fn ($header): int => (int) $header->HeaderId,
                        $batch,
                    );
                    $inserted = DB::table('legacy_operational_documents')
                        ->where('source_system', self::SOURCE_SYSTEM)
                        ->whereIn('source_header_id', $sourceHeaderIds)
                        ->get(['id', 'source_header_id'])
                        ->keyBy('source_header_id');

                    foreach ($batch as $header) {
                        $headerId = (int) $header->HeaderId;
                        $document = $inserted->get($headerId);
                        if ($document === null) {
                            throw new RuntimeException(
                                "Inserted legacy operational document {$headerId} could not be resolved."
                            );
                        }

                        $documentId = (int) $document->id;
                        $documentIds[$headerId] = $documentId;
                        $sourceArray = (array) $header;
                        $mappingRows[] = [
                            'source_system' => self::SOURCE_SYSTEM,
                            'source_table' => 'wh_TransHeader',
                            'source_key' => (string) $headerId,
                            'target_table' => 'legacy_operational_documents',
                            'target_id' => $documentId,
                            'source_hash' => hash('sha256', $this->json($sourceArray)),
                            'metadata' => $this->json([
                                'source' => $sourceArray,
                                '_migration' => [
                                    'phase' => 'operational-history',
                                    'movement_kind' => $typeRules[(int) $header->TransType]['kind'],
                                    'stock_effect_replayed' => false,
                                ],
                            ]),
                            'last_run_id' => $runId,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }

                    if (count($mappingRows) >= 1000) {
                        $flushMappings();
                    }

                    if ($progress !== null) {
                        $progress(
                            'materialize_operational_headers_batch_'.$headerBatchNumber.'_headers_'.$documentsCreated,
                            'done',
                            round(microtime(true) - $startedAt, 3),
                        );
                    }
                };

                $headersStartedAt = microtime(true);
                if ($progress !== null) {
                    $progress('stream_materialize_operational_headers', 'start', null);
                }

                foreach (
                    $source->table('wh_TransHeader')
                        ->select([
                            'HeaderId',
                            'TransType',
                            'TransDate',
                            'StoreId',
                            'toStoreId',
                            'TransferHeaderID',
                            'CarHeaderId',
                            'ProductionId',
                            'Code',
                            'DocTransNo',
                            'ReferenceNo',
                            'Total',
                            'NetTotal',
                            'Notes',
                            'Status',
                        ])
                        ->where('Status', 1)
                        ->whereIn('TransType', $operationalTypes)
                        ->orderBy('HeaderId')
                        ->cursor() as $header
                ) {
                    $headerBatch[] = $header;
                    if (count($headerBatch) >= 500) {
                        $flushHeaders($headerBatch);
                        $headerBatch = [];
                    }
                }

                if ($headerBatch !== []) {
                    $flushHeaders($headerBatch);
                }

                if ($progress !== null) {
                    $progress(
                        'stream_materialize_operational_headers',
                        'done',
                        round(microtime(true) - $headersStartedAt, 3),
                    );
                }

                $flushMappings();

                $detailsStartedAt = microtime(true);
                $detailChunkNumber = 0;
                $detailsScanned = 0;
                if ($progress !== null) {
                    $progress('stream_materialize_operational_details', 'start', null);
                }

                $source->table('wh_TransDetails')
                    ->select([
                        'DetailId',
                        'HeaderId',
                        'ItemID',
                        'Package',
                        'Qty',
                        'TransPkgQty1',
                        'Price',
                        'TransPkgPrice1',
                        'Value',
                        'CostPrice',
                        'DetailDiscount1',
                        'DetailDiscount2',
                        'DetailDiscount3',
                        'DetailTax',
                        'DetailRemark',
                        'Status',
                    ])
                    ->orderBy('DetailId')
                    ->chunkById(
                        1000,
                        function ($rows) use (
                            &$detailChunkNumber,
                            &$detailsScanned,
                            &$linesCreated,
                            &$mappingRows,
                            $documentIds,
                            $maps,
                            $runId,
                            $progress,
                            $flushMappings,
                        ): void {
                            $detailChunkNumber++;
                            $startedAt = microtime(true);
                            $writeTimestamp = date('Y-m-d H:i:s');
                            $insertRows = [];
                            $mappingPayloads = [];

                            foreach ($rows as $line) {
                                $detailsScanned++;

                                if ((int) ($line->Status ?? 0) !== 1) {
                                    continue;
                                }

                                $headerId = (int) ($line->HeaderId ?? 0);
                                if (! isset($documentIds[$headerId])) {
                                    continue;
                                }

                                $sourceItemId = (int) $line->ItemID;
                                if (! isset($maps['products'][$sourceItemId])) {
                                    throw new RuntimeException(
                                        "Validated operational item {$sourceItemId} lost its product mapping during apply."
                                    );
                                }

                                $discount = round(
                                    (float) ($line->DetailDiscount1 ?? 0)
                                    + (float) ($line->DetailDiscount2 ?? 0)
                                    + (float) ($line->DetailDiscount3 ?? 0),
                                    3,
                                );

                                $sourceArray = (array) $line;
                                $sourceJson = $this->json($sourceArray);
                                $sourceDetailId = (int) $line->DetailId;

                                $insertRows[] = [
                                    'document_id' => $documentIds[$headerId],
                                    'source_detail_id' => $sourceDetailId,
                                    'source_item_id' => $sourceItemId,
                                    'product_id' => $maps['products'][$sourceItemId],
                                    'package' => $line->Package === null ? null : (int) $line->Package,
                                    'raw_quantity' => round((float) ($line->Qty ?? 0), 3),
                                    'base_quantity' => round((float) ($line->TransPkgQty1 ?? 0), 3),
                                    'unit_price' => round((float) ($line->Price ?? 0), 3),
                                    'base_unit_price' => round((float) ($line->TransPkgPrice1 ?? 0), 3),
                                    'line_value' => round((float) ($line->Value ?? 0), 3),
                                    'cost_price' => round((float) ($line->CostPrice ?? 0), 3),
                                    'discount_total' => $discount,
                                    'tax_total' => round((float) ($line->DetailTax ?? 0), 3),
                                    'source_payload' => $sourceJson,
                                    'created_at' => $writeTimestamp,
                                    'updated_at' => $writeTimestamp,
                                ];
                                $mappingPayloads[$sourceDetailId] = [
                                    'source_hash' => hash('sha256', $sourceJson),
                                    'metadata' => $this->json([
                                        'source' => $sourceArray,
                                        '_migration' => [
                                            'phase' => 'operational-history',
                                            'stock_effect_replayed' => false,
                                        ],
                                    ]),
                                ];
                            }

                            if ($insertRows !== []) {
                                foreach (array_chunk($insertRows, 500) as $chunk) {
                                    DB::table('legacy_operational_document_lines')->insert($chunk);
                                }

                                $sourceIds = array_column($insertRows, 'source_detail_id');
                                $inserted = DB::table('legacy_operational_document_lines')
                                    ->whereIn('source_detail_id', $sourceIds)
                                    ->get(['id', 'source_detail_id'])
                                    ->keyBy('source_detail_id');

                                foreach ($sourceIds as $sourceDetailId) {
                                    $target = $inserted->get($sourceDetailId);
                                    if ($target === null) {
                                        throw new RuntimeException(
                                            "Inserted legacy operational line {$sourceDetailId} could not be resolved."
                                        );
                                    }

                                    $payload = $mappingPayloads[$sourceDetailId];
                                    $mappingRows[] = [
                                        'source_system' => self::SOURCE_SYSTEM,
                                        'source_table' => 'wh_TransDetails',
                                        'source_key' => (string) $sourceDetailId,
                                        'target_table' => 'legacy_operational_document_lines',
                                        'target_id' => (int) $target->id,
                                        'source_hash' => $payload['source_hash'],
                                        'metadata' => $payload['metadata'],
                                        'last_run_id' => $runId,
                                        'created_at' => $writeTimestamp,
                                        'updated_at' => $writeTimestamp,
                                    ];
                                }

                                $linesCreated += count($insertRows);
                                if (count($mappingRows) >= 500) {
                                    $flushMappings();
                                }
                            }

                            if ($progress !== null) {
                                $progress(
                                    'materialize_operational_details_chunk_'.$detailChunkNumber.'_rows_'.$detailsScanned,
                                    'done',
                                    round(microtime(true) - $startedAt, 3),
                                );
                            }
                        },
                        'DetailId',
                        'DetailId',
                    );

                if ($progress !== null) {
                    $progress(
                        'stream_materialize_operational_details',
                        'done',
                        round(microtime(true) - $detailsStartedAt, 3),
                    );
                }

                $flushMappings();

                $targetDocuments = (int) DB::table('legacy_operational_documents')->count();
                $targetLines = (int) DB::table('legacy_operational_document_lines')->count();

                if (
                    $targetDocuments !== $documentsCreated
                    || $targetLines !== $linesCreated
                    || $documentsCreated !== (int) ($planned['materialized_documents'] ?? -1)
                    || $linesCreated !== (int) ($planned['operational_details'] ?? -1)
                ) {
                    throw new RuntimeException(
                        'Operational history target validation failed: target counts do not match planned/materialized counts.'
                    );
                }

                return [
                    'run_id' => $runId,
                    'planned' => $planned,
                    'documents' => ['created' => $documentsCreated],
                    'lines' => ['created' => $linesCreated],
                    'target' => [
                        'documents' => $targetDocuments,
                        'lines' => $targetLines,
                    ],
                    'inventory_mutations' => 0,
                    'stock_movement_mutations' => 0,
                    'finance_mutations' => 0,
                ];
            }, 3);

            DB::table('legacy_import_runs')->where('id', $runId)->update([
                'status' => 'completed',
                'summary' => $this->json($summary),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'mode' => 'apply',
                'phase' => 'operational-history',
                'status' => 'completed',
                'summary' => $summary,
            ];
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

    public function financeDiagnostics(?callable $progress = null): array
    {
        $this->assertTrackingTables();

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

        $source = DB::connection('foodex_legacy');

        $tableCounts = $stage('finance_table_counts', fn (): array => [
            'gl_accounts' => (int) $source->table('gl_Accounts')->count(),
            'gl_entry_headers' => (int) $source->table('gl_EntryHeader')->count(),
            'gl_entry_details' => (int) $source->table('gl_EntryDetails')->count(),
            'wh_pr_trans' => (int) $source->table('wh_PrTrans')->count(),
            'wh_in_out_bank' => (int) $source->table('wh_InOutBank')->count(),
        ]);

        $glHeader = $stage('gl_header_summary', function () use ($source): array {
            $row = $source->table('gl_EntryHeader')
                ->selectRaw('COUNT(*) AS row_count')
                ->selectRaw('SUM(CASE WHEN Posted = 1 THEN 1 ELSE 0 END) AS posted_count')
                ->selectRaw('SUM(CASE WHEN Posted <> 1 OR Posted IS NULL THEN 1 ELSE 0 END) AS unposted_count')
                ->selectRaw('MIN(TransDate) AS first_date')
                ->selectRaw('MAX(TransDate) AS last_date')
                ->first();

            return [
                'rows' => (int) ($row->row_count ?? 0),
                'posted' => (int) ($row->posted_count ?? 0),
                'unposted' => (int) ($row->unposted_count ?? 0),
                'first_date' => $row->first_date === null ? null : (int) $row->first_date,
                'last_date' => $row->last_date === null ? null : (int) $row->last_date,
            ];
        });

        $glFlags = $stage('gl_detail_flag_summary', function () use ($source): array {
            $rows = $source->table('gl_EntryDetails')
                ->select(['DrCrFlag'])
                ->selectRaw('COUNT(*) AS row_count')
                ->selectRaw('SUM(CASE WHEN Status = 1 THEN 1 ELSE 0 END) AS active_count')
                ->selectRaw('SUM(CASE WHEN Status = 1 THEN AccountValue ELSE 0 END) AS active_value')
                ->groupBy('DrCrFlag')
                ->orderBy('DrCrFlag')
                ->get();

            return collect($rows)->map(static fn ($row): array => [
                'flag' => $row->DrCrFlag === null ? null : (int) $row->DrCrFlag,
                'rows' => (int) ($row->row_count ?? 0),
                'active_rows' => (int) ($row->active_count ?? 0),
                'active_value' => round((float) ($row->active_value ?? 0), 3),
            ])->all();
        });

        $glBalance = $stage('gl_posted_balance_check', function () use ($source): array {
            $rows = $source->table('gl_EntryDetails as d')
                ->join('gl_EntryHeader as h', 'h.HeaderID', '=', 'd.HeaderId')
                ->where('h.Posted', 1)
                ->where('d.Status', 1)
                ->select('d.HeaderId')
                ->selectRaw('SUM(CASE WHEN d.DrCrFlag = 1 THEN d.AccountValue ELSE 0 END) AS flag_1_total')
                ->selectRaw('SUM(CASE WHEN d.DrCrFlag = 2 THEN d.AccountValue ELSE 0 END) AS flag_2_total')
                ->selectRaw('SUM(CASE WHEN d.DrCrFlag NOT IN (1,2) OR d.DrCrFlag IS NULL THEN 1 ELSE 0 END) AS other_flag_rows')
                ->groupBy('d.HeaderId')
                ->get();

            $balanced = 0;
            $unbalanced = 0;
            $otherFlagRows = 0;
            $differenceAbsTotal = 0.0;
            $samples = [];

            foreach ($rows as $row) {
                $flag1 = round((float) ($row->flag_1_total ?? 0), 3);
                $flag2 = round((float) ($row->flag_2_total ?? 0), 3);
                $difference = round($flag1 - $flag2, 3);
                $other = (int) ($row->other_flag_rows ?? 0);
                $otherFlagRows += $other;
                $differenceAbsTotal += abs($difference);

                if (abs($difference) <= 0.001 && $other === 0) {
                    $balanced++;
                } else {
                    $unbalanced++;
                    if (count($samples) < 20) {
                        $samples[] = [
                            'header_id' => (int) $row->HeaderId,
                            'flag_1_total' => $flag1,
                            'flag_2_total' => $flag2,
                            'difference' => $difference,
                            'other_flag_rows' => $other,
                        ];
                    }
                }
            }

            return [
                'posted_headers_with_active_details' => count($rows),
                'balanced_if_flags_1_2_are_dr_cr' => $balanced,
                'unbalanced_if_flags_1_2_are_dr_cr' => $unbalanced,
                'other_flag_rows' => $otherFlagRows,
                'absolute_difference_total' => round($differenceAbsTotal, 3),
                'unbalanced_samples' => $samples,
            ];
        });

        $accountIntegrity = $stage('gl_account_integrity', function () use ($source): array {
            $activeDetailAccounts = $source->table('gl_EntryDetails')
                ->where('Status', 1)
                ->whereNotNull('AccountID')
                ->where('AccountID', '>', 0)
                ->distinct()
                ->pluck('AccountID')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $known = $source->table('gl_Accounts')
                ->whereIn('ID', $activeDetailAccounts === [] ? [-1] : $activeDetailAccounts)
                ->pluck('ID')
                ->map(static fn ($id): int => (int) $id)
                ->flip();

            $missing = [];
            foreach ($activeDetailAccounts as $accountId) {
                if (! $known->has($accountId)) {
                    $missing[] = $accountId;
                }
            }

            return [
                'used_active_accounts' => count($activeDetailAccounts),
                'missing_account_ids' => count($missing),
                'missing_account_samples' => array_slice($missing, 0, 30),
            ];
        });

        $partyAccountLinks = $stage('party_gl_account_links', function () use ($source): array {
            $customerGlRows = $source->table('wh_Customers')
                ->whereNotNull('GlAccId')
                ->where('GlAccId', '>', 0)
                ->count();
            $supplierGlRows = $source->table('wh_Suppliers')
                ->whereNotNull('GlAccId')
                ->where('GlAccId', '>', 0)
                ->count();

            $customerAccountIds = $source->table('wh_Customers')
                ->whereNotNull('GlAccId')
                ->where('GlAccId', '>', 0)
                ->distinct()
                ->pluck('GlAccId')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            $supplierAccountIds = $source->table('wh_Suppliers')
                ->whereNotNull('GlAccId')
                ->where('GlAccId', '>', 0)
                ->distinct()
                ->pluck('GlAccId')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $allPartyAccountIds = array_values(array_unique(array_merge($customerAccountIds, $supplierAccountIds)));
            $known = $source->table('gl_Accounts')
                ->whereIn('ID', $allPartyAccountIds === [] ? [-1] : $allPartyAccountIds)
                ->pluck('ID')
                ->map(static fn ($id): int => (int) $id)
                ->flip();

            $missingCustomer = array_values(array_filter(
                $customerAccountIds,
                static fn (int $id): bool => ! $known->has($id),
            ));
            $missingSupplier = array_values(array_filter(
                $supplierAccountIds,
                static fn (int $id): bool => ! $known->has($id),
            ));

            return [
                'customers_with_gl_account' => (int) $customerGlRows,
                'customer_gl_accounts' => count($customerAccountIds),
                'customer_missing_gl_accounts' => count($missingCustomer),
                'customer_missing_samples' => array_slice($missingCustomer, 0, 20),
                'suppliers_with_gl_account' => (int) $supplierGlRows,
                'supplier_gl_accounts' => count($supplierAccountIds),
                'supplier_missing_gl_accounts' => count($missingSupplier),
                'supplier_missing_samples' => array_slice($missingSupplier, 0, 20),
                'customers_with_credit_limit' => (int) $source->table('wh_Customers')
                    ->where('CreditLimit', '>', 0)
                    ->count(),
                'customers_with_credit_period' => (int) $source->table('wh_Customers')
                    ->where('CreditPeriod', '>', 0)
                    ->count(),
                'suppliers_with_credit_period' => (int) $source->table('wh_Suppliers')
                    ->where('Creditperiod', '>', 0)
                    ->count(),
            ];
        });

        $prTrans = $stage('pr_trans_summary', function () use ($source): array {
            $rows = $source->table('wh_PrTrans')
                ->where('Status', 1)
                ->select('PrTransType')
                ->selectRaw('COUNT(*) AS row_count')
                ->selectRaw('SUM(TransVal) AS trans_value')
                ->selectRaw('SUM(CASE WHEN CustomerId > 0 THEN 1 ELSE 0 END) AS customer_rows')
                ->selectRaw('SUM(CASE WHEN SupplierId > 0 THEN 1 ELSE 0 END) AS supplier_rows')
                ->selectRaw('SUM(CASE WHEN AccountID > 0 THEN 1 ELSE 0 END) AS account_rows')
                ->selectRaw('SUM(CASE WHEN GlPosted = 1 THEN 1 ELSE 0 END) AS gl_posted_rows')
                ->selectRaw('SUM(CASE WHEN b_OpeningBal = 1 THEN 1 ELSE 0 END) AS opening_rows')
                ->groupBy('PrTransType')
                ->orderBy('PrTransType')
                ->get();

            return collect($rows)->map(static fn ($row): array => [
                'type' => (int) $row->PrTransType,
                'rows' => (int) ($row->row_count ?? 0),
                'trans_value' => round((float) ($row->trans_value ?? 0), 3),
                'customer_rows' => (int) ($row->customer_rows ?? 0),
                'supplier_rows' => (int) ($row->supplier_rows ?? 0),
                'account_rows' => (int) ($row->account_rows ?? 0),
                'gl_posted_rows' => (int) ($row->gl_posted_rows ?? 0),
                'opening_rows' => (int) ($row->opening_rows ?? 0),
            ])->all();
        });

        $prSemantics = $stage('pr_trans_semantics', function () use ($source): array {
            $lookup = $source->table('wh_PrTransTypes')
                ->orderBy('PrTypeId')
                ->get(['PrTypeId', 'PrTypeName', 'PrTypeNameEn', 'Status'])
                ->map(static fn ($row): array => [
                    'type' => (int) $row->PrTypeId,
                    'name_ar' => trim((string) ($row->PrTypeName ?? '')),
                    'name_en' => trim((string) ($row->PrTypeNameEn ?? '')),
                    'status' => (int) ($row->Status ?? 0),
                ])
                ->all();

            $paymentShapes = $source->table('wh_PrTrans')
                ->where('Status', 1)
                ->select(['PrTransType', 'PaymentType', 'PayType'])
                ->selectRaw('COUNT(*) AS row_count')
                ->selectRaw('SUM(TransVal) AS trans_value')
                ->groupBy('PrTransType', 'PaymentType', 'PayType')
                ->orderBy('PrTransType')
                ->orderBy('PaymentType')
                ->orderBy('PayType')
                ->get()
                ->map(static fn ($row): array => [
                    'type' => (int) $row->PrTransType,
                    'payment_type' => $row->PaymentType === null ? null : (int) $row->PaymentType,
                    'pay_type' => $row->PayType === null ? null : (int) $row->PayType,
                    'rows' => (int) ($row->row_count ?? 0),
                    'value' => round((float) ($row->trans_value ?? 0), 3),
                ])
                ->all();

            $directInvoiceLinks = $source->table('wh_PrTrans as p')
                ->leftJoin('wh_TransHeader as h', 'h.HeaderId', '=', 'p.InvHeaderID')
                ->where('p.Status', 1)
                ->where('p.InvHeaderID', '>', 0)
                ->select(['p.PrTransType', 'h.TransType'])
                ->selectRaw('COUNT(*) AS row_count')
                ->selectRaw('SUM(p.TransVal) AS trans_value')
                ->groupBy('p.PrTransType', 'h.TransType')
                ->orderBy('p.PrTransType')
                ->orderBy('h.TransType')
                ->get()
                ->map(static fn ($row): array => [
                    'pr_type' => (int) $row->PrTransType,
                    'invoice_trans_type' => $row->TransType === null ? null : (int) $row->TransType,
                    'rows' => (int) ($row->row_count ?? 0),
                    'value' => round((float) ($row->trans_value ?? 0), 3),
                ])
                ->all();

            $detailLinks = $source->table('wh_PrTransDtls as d')
                ->join('wh_PrTrans as p', 'p.ID', '=', 'd.PrHeaderId')
                ->leftJoin('wh_TransHeader as h', 'h.HeaderId', '=', 'd.TransHeaderId')
                ->where('p.Status', 1)
                ->select(['p.PrTransType', 'h.TransType'])
                ->selectRaw('COUNT(*) AS detail_rows')
                ->selectRaw('SUM(d.TransVal) AS detail_value')
                ->groupBy('p.PrTransType', 'h.TransType')
                ->orderBy('p.PrTransType')
                ->orderBy('h.TransType')
                ->get()
                ->map(static fn ($row): array => [
                    'pr_type' => (int) $row->PrTransType,
                    'linked_trans_type' => $row->TransType === null ? null : (int) $row->TransType,
                    'rows' => (int) ($row->detail_rows ?? 0),
                    'value' => round((float) ($row->detail_value ?? 0), 3),
                ])
                ->all();

            $detailIntegrity = $source->table('wh_PrTransDtls as d')
                ->leftJoin('wh_PrTrans as p', 'p.ID', '=', 'd.PrHeaderId')
                ->selectRaw('COUNT(*) AS row_count')
                ->selectRaw('SUM(CASE WHEN p.ID IS NULL THEN 1 ELSE 0 END) AS orphan_parent_rows')
                ->selectRaw('SUM(CASE WHEN d.TransHeaderId > 0 THEN 1 ELSE 0 END) AS linked_transaction_rows')
                ->selectRaw('SUM(d.TransVal) AS trans_value')
                ->first();

            $types = $source->table('wh_PrTrans')
                ->where('Status', 1)
                ->distinct()
                ->orderBy('PrTransType')
                ->pluck('PrTransType')
                ->map(static fn ($type): int => (int) $type)
                ->all();

            $samples = [];
            foreach ($types as $type) {
                $samples[$type] = $source->table('wh_PrTrans')
                    ->where('Status', 1)
                    ->where('PrTransType', $type)
                    ->orderBy('ID')
                    ->limit(6)
                    ->get([
                        'ID',
                        'ReceiptNo',
                        'TransDate',
                        'TransVal',
                        'CustomerId',
                        'SupplierId',
                        'AccountID',
                        'InvHeaderID',
                        'PaymentType',
                        'PayType',
                        'GlPosted',
                        'CashBoxNo',
                        'BankId',
                        'TransNotes',
                    ])
                    ->map(static fn ($row): array => [
                        'id' => (int) $row->ID,
                        'receipt' => trim((string) ($row->ReceiptNo ?? '')),
                        'date' => (int) ($row->TransDate ?? 0),
                        'value' => round((float) ($row->TransVal ?? 0), 3),
                        'customer_id' => (int) ($row->CustomerId ?? 0),
                        'supplier_id' => (int) ($row->SupplierId ?? 0),
                        'account_id' => (int) ($row->AccountID ?? 0),
                        'invoice_header_id' => (int) ($row->InvHeaderID ?? 0),
                        'payment_type' => $row->PaymentType === null ? null : (int) $row->PaymentType,
                        'pay_type' => $row->PayType === null ? null : (int) $row->PayType,
                        'gl_posted' => (int) ($row->GlPosted ?? 0),
                        'cashbox_no' => (int) ($row->CashBoxNo ?? 0),
                        'bank_id' => (int) ($row->BankId ?? 0),
                        'notes' => trim((string) ($row->TransNotes ?? '')),
                    ])
                    ->all();
            }

            $bankTypes = $source->table('wh_InOutBank')
                ->where('Status', 1)
                ->select('TransType')
                ->selectRaw('COUNT(*) AS row_count')
                ->selectRaw('SUM(TransVal) AS trans_value')
                ->selectRaw('SUM(CASE WHEN CustomerId > 0 THEN 1 ELSE 0 END) AS customer_rows')
                ->selectRaw('SUM(CASE WHEN SupplierId > 0 THEN 1 ELSE 0 END) AS supplier_rows')
                ->selectRaw('SUM(CASE WHEN AccountID > 0 THEN 1 ELSE 0 END) AS account_rows')
                ->selectRaw('SUM(CASE WHEN GlPosted = 1 THEN 1 ELSE 0 END) AS gl_posted_rows')
                ->groupBy('TransType')
                ->orderBy('TransType')
                ->get()
                ->map(static fn ($row): array => [
                    'type' => (int) $row->TransType,
                    'rows' => (int) ($row->row_count ?? 0),
                    'value' => round((float) ($row->trans_value ?? 0), 3),
                    'customer_rows' => (int) ($row->customer_rows ?? 0),
                    'supplier_rows' => (int) ($row->supplier_rows ?? 0),
                    'account_rows' => (int) ($row->account_rows ?? 0),
                    'gl_posted_rows' => (int) ($row->gl_posted_rows ?? 0),
                ])
                ->all();

            return [
                'type_lookup' => $lookup,
                'payment_shapes' => $paymentShapes,
                'direct_invoice_links' => $directInvoiceLinks,
                'detail_links' => $detailLinks,
                'detail_integrity' => [
                    'rows' => (int) ($detailIntegrity->row_count ?? 0),
                    'orphan_parent_rows' => (int) ($detailIntegrity->orphan_parent_rows ?? 0),
                    'linked_transaction_rows' => (int) ($detailIntegrity->linked_transaction_rows ?? 0),
                    'value' => round((float) ($detailIntegrity->trans_value ?? 0), 3),
                ],
                'samples' => $samples,
                'bank_types' => $bankTypes,
            ];
        });

        $commercialGlLinks = $stage('commercial_gl_links', function () use ($source): array {
            $linked = (int) $source->table('wh_TransHeader')
                ->where('Status', 1)
                ->where(function ($query): void {
                    $query
                        ->where('glEntryHeaderId', '>', 0)
                        ->orWhere('glEntryHeaderId2', '>', 0);
                })
                ->count();

            $linkedPrimary = (int) $source->table('wh_TransHeader')
                ->where('Status', 1)
                ->where('glEntryHeaderId', '>', 0)
                ->count();

            $linkedSecondary = (int) $source->table('wh_TransHeader')
                ->where('Status', 1)
                ->where('glEntryHeaderId2', '>', 0)
                ->count();

            return [
                'posted_commercial_headers_with_any_gl_link' => $linked,
                'with_primary_gl_link' => $linkedPrimary,
                'with_secondary_gl_link' => $linkedSecondary,
            ];
        });

        return [
            'read_only' => true,
            'tables' => $tableCounts,
            'gl_header' => $glHeader,
            'gl_flags' => $glFlags,
            'gl_balance' => $glBalance,
            'account_integrity' => $accountIntegrity,
            'party_account_links' => $partyAccountLinks,
            'pr_trans_types' => $prTrans,
            'pr_semantics' => $prSemantics,
            'commercial_gl_links' => $commercialGlLinks,
            'rules' => [
                'no_source_writes' => true,
                'no_target_writes' => true,
                'do_not_treat_wh_transheader_balance_as_outstanding' => true,
                'finance_authority_candidate' => $tableCounts['gl_entry_headers'] > 0
                    ? 'gl_EntryHeader + gl_EntryDetails + gl_Accounts'
                    : 'wh_PrTrans + wh_PrTransDtls + wh_PrTransTypes',
                'cash_receipt_payment_candidate' => 'wh_PrTrans',
            ],
        ];
    }

    public function importExtendedMasters(bool $apply): array
    {
        $preflight = $this->preflight();
        $source = DB::connection('foodex_legacy');

        $addressCandidates = (int) $source->table('wh_Customers')
            ->whereRaw(
                "NULLIF(LTRIM(RTRIM(COALESCE(Address, ''))), '') IS NOT NULL ".
                "OR NULLIF(LTRIM(RTRIM(COALESCE(Address2, ''))), '') IS NOT NULL ".
                "OR NULLIF(LTRIM(RTRIM(COALESCE(StreetName, ''))), '') IS NOT NULL ".
                "OR COALESCE(Latitude, 0) <> 0 OR COALESCE(Longitude, 0) <> 0"
            )
            ->count();

        if (! $apply) {
            return [
                'mode' => 'dry-run',
                'phase' => 'extended-masters',
                'planned' => [
                    'customer_addresses' => $addressCandidates,
                    'suppliers' => (int) $preflight['source_counts']['wh_Suppliers'],
                    'vans' => (int) $preflight['source_counts']['wh_Cars'],
                    'sales_rep_references' => (int) $preflight['source_counts']['wh_SalesReps'],
                ],
                'preflight' => $preflight,
            ];
        }

        $this->assertTrackingTables();
        if (! Schema::hasTable('legacy_import_reference_records')) {
            throw new RuntimeException('Missing legacy_import_reference_records. Run php artisan migrate first.');
        }

        if (
            ! $this->legacyMastersRerunReady($preflight['source_counts'])
            && ! $this->legacyExtendedMastersRerunReady($preflight['source_counts'])
        ) {
            throw new RuntimeException(
                'Extended legacy import requires the verified provenance-owned masters baseline.'
            );
        }

        $runId = (string) Str::uuid();
        DB::table('legacy_import_runs')->insert([
            'id' => $runId,
            'source_system' => self::SOURCE_SYSTEM,
            'phase' => 'extended-masters',
            'mode' => 'apply',
            'status' => 'running',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $summary = DB::transaction(function () use ($runId): array {
                $storeId = app(WholesalePrincipal::class)->storeId();

                return [
                    'run_id' => $runId,
                    'store_id' => $storeId,
                    'addresses' => $this->importCustomerAddresses($runId),
                    'suppliers' => $this->importSuppliers($storeId, $runId),
                    'vans' => $this->importVans($runId),
                    'sales_reps' => $this->preserveSalesRepReferences($runId),
                ];
            }, 3);

            DB::table('legacy_import_runs')->where('id', $runId)->update([
                'status' => 'completed',
                'summary' => $this->json($summary),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'mode' => 'apply',
                'phase' => 'extended-masters',
                'status' => 'completed',
                'summary' => $summary,
            ];
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

    private function importCustomerAddresses(string $runId): array
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;

        $cities = DB::connection('foodex_legacy')->table('fw_Cities')
            ->get(['ID', 'Name_Ar', 'Name_En'])
            ->keyBy('ID');
        $regions = DB::connection('foodex_legacy')->table('fw_Regions')
            ->get(['ID', 'Name_Ar', 'Name_En'])
            ->keyBy('ID');
        $districts = DB::connection('foodex_legacy')->table('fw_Districts')
            ->get(['ID', 'Name_Ar', 'Name_En'])
            ->keyBy('ID');

        $rows = DB::connection('foodex_legacy')->table('wh_Customers')->orderBy('ID')->get();
        foreach ($rows as $row) {
            $customerId = $this->mappedTargetId('wh_Customers', (string) $row->ID, 'customers');
            if ($customerId === null) {
                $skipped++;
                continue;
            }

            $line1 = $this->legacyText($row->Address ?? null);
            $line2 = $this->legacyText($row->Address2 ?? null);
            $street = $this->legacyText($row->StreetName ?? null);
            $latitude = $row->Latitude === null ? null : (float) $row->Latitude;
            $longitude = $row->Longitude === null ? null : (float) $row->Longitude;
            $hasCoordinates = ($latitude !== null && abs($latitude) > 0.0000001)
                || ($longitude !== null && abs($longitude) > 0.0000001);

            if ($line1 === null && $line2 === null && $street === null && ! $hasCoordinates) {
                $skipped++;
                continue;
            }

            $cityId = (int) ($row->CityID ?? 0);
            $regionId = (int) ($row->RegionID ?? 0);
            $districtId = (int) ($row->DistrictID ?? 0);

            $cityRow = $cityId > 0 ? $cities->get($cityId) : null;
            $regionRow = $regionId > 0 ? $regions->get($regionId) : null;
            $districtRow = $districtId > 0 ? $districts->get($districtId) : null;

            $city = $cityRow === null
                ? ($cityId > 0 ? 'Legacy city #'.$cityId : 'Unspecified')
                : $this->name($cityRow->Name_Ar ?? null, $cityRow->Name_En ?? null, 'Legacy city #'.$cityId);
            $region = $regionRow === null
                ? ($regionId > 0 ? 'Legacy region #'.$regionId : null)
                : $this->name($regionRow->Name_Ar ?? null, $regionRow->Name_En ?? null, 'Legacy region #'.$regionId);
            $district = $districtRow === null
                ? ($districtId > 0 ? 'Legacy district #'.$districtId : null)
                : $this->name($districtRow->Name_Ar ?? null, $districtRow->Name_En ?? null, 'Legacy district #'.$districtId);

            $targetId = $this->mappedTargetId('wh_Customers.Address', (string) $row->ID, 'addresses');
            $payload = [
                'customer_id' => $customerId,
                'platform_customer_id' => null,
                'label' => 'Legacy Foodex',
                'recipient_name' => mb_substr(
                    $this->name($row->Name_Ar ?? null, $row->Name_En ?? null, 'Legacy customer '.(int) $row->ID),
                    0,
                    255,
                ),
                'delivery_phone' => $this->legacyText($row->Mobile ?? null, true)
                    ?? $this->legacyText($row->Phone1 ?? null, true),
                'line1' => mb_substr($line1 ?? $street ?? $line2 ?? 'Legacy geocoded location', 0, 255),
                'line2' => $line2 === null ? null : mb_substr($line2, 0, 255),
                'city' => mb_substr($city, 0, 255),
                'area' => $district === null ? ($region === null ? null : mb_substr($region, 0, 255)) : mb_substr($district, 0, 255),
                'country_code' => 'ZZ',
                'country' => null,
                'governorate' => $region === null ? null : mb_substr($region, 0, 120),
                'block' => null,
                'street' => $street === null ? null : mb_substr($street, 0, 255),
                'avenue' => null,
                'building' => null,
                'floor' => null,
                'apartment' => null,
                'landmark' => null,
                'delivery_notes' => null,
                'latitude' => $hasCoordinates ? $latitude : null,
                'longitude' => $hasCoordinates ? $longitude : null,
                'location_accuracy_meters' => null,
                'location_source' => 'legacy_foodex',
                'is_default' => true,
                'deleted_at' => null,
                'updated_at' => now(),
            ];

            if ($targetId !== null && DB::table('addresses')->where('id', $targetId)->exists()) {
                DB::table('addresses')->where('id', $targetId)->update($payload);
                $updated++;
            } else {
                $payload['created_at'] = now();
                $targetId = (int) DB::table('addresses')->insertGetId($payload);
                $created++;
            }

            $this->syncMapping(
                'wh_Customers.Address',
                (string) $row->ID,
                'addresses',
                $targetId,
                $row,
                $runId,
                [
                    'legacy_country_id' => (int) ($row->CountryID ?? 0),
                    'legacy_region_id' => $regionId,
                    'legacy_city_id' => $cityId,
                    'legacy_district_id' => $districtId,
                ],
            );
        }

        return compact('created', 'updated', 'skipped');
    }

    private function importSuppliers(int $storeId, string $runId): array
    {
        $organizationId = (int) DB::table('enterprise_organizations')
            ->where('code', \App\Enterprise\Organization\OrganizationHierarchyService::DEFAULT_ORGANIZATION)
            ->value('id');
        $companyId = (int) DB::table('enterprise_companies')
            ->where('organization_id', $organizationId)
            ->where('code', \App\Enterprise\Organization\OrganizationHierarchyService::DEFAULT_COMPANY)
            ->value('id');

        if ($organizationId <= 0 || $companyId <= 0) {
            throw new RuntimeException('Default enterprise organization/company scope is missing.');
        }

        $created = 0;
        $updated = 0;
        $rows = DB::connection('foodex_legacy')->table('wh_Suppliers')->orderBy('ID')->get();

        foreach ($rows as $row) {
            $sourceId = (string) $row->ID;
            $targetId = $this->mappedTargetId('wh_Suppliers', $sourceId, 'enterprise_suppliers');
            $legacyCode = $this->legacyText($row->Code ?? null);
            $nameAr = $this->legacyText($row->Name_Ar ?? null);
            $nameEn = $this->legacyText($row->Name_En ?? null);
            $displayName = $nameEn ?? $nameAr ?? 'Legacy supplier '.(int) $row->ID;
            $creditPeriod = max(0, min(65535, (int) ($row->Creditperiod ?? 0)));

            $payload = [
                'organization_id' => $organizationId,
                'company_id' => $companyId,
                'store_id' => $storeId,
                'scope_type' => 'store',
                'code' => 'FDX-SUP-'.(int) $row->ID,
                'name_en' => mb_substr($displayName, 0, 190),
                'name_ar' => $nameAr === null ? null : mb_substr($nameAr, 0, 190),
                'status' => (int) ($row->Status ?? 1) === 0 ? 'inactive' : 'active',
                'contact_name' => null,
                'email' => null,
                'phone' => $this->legacyText($row->Mobile ?? null, true)
                    ?? $this->legacyText($row->Phone1 ?? null, true),
                'tax_registration_number' => $this->legacyText($row->RegistrationNumber ?? null, true),
                'commercial_registration_number' => $this->legacyText($row->FileNumber ?? null, true),
                'business_type' => null,
                'payment_terms_days' => $creditPeriod > 0 ? $creditPeriod : null,
                'notes' => $this->json([
                    'legacy_code' => $legacyCode,
                    'legacy_supplier_type' => (int) ($row->Suppliertype ?? 0),
                    'legacy_contract_type' => (int) ($row->ContractType ?? 0),
                    'legacy_supplier_group_id' => (int) ($row->SupplierGroupID ?? 0),
                    'legacy_region_id' => (int) ($row->RegionID ?? 0),
                    'legacy_city_id' => (int) ($row->CityID ?? 0),
                    'address' => $this->legacyText($row->Address ?? null),
                    'address_2' => $this->legacyText($row->Address2 ?? null),
                    'phone_2' => $this->legacyText($row->Phone2 ?? null, true),
                    'tax_office' => $this->legacyText($row->TaxOffice ?? null, true),
                    'notification_period' => (int) ($row->NotificationPeriod ?? 0),
                ]),
                'created_by' => null,
                'updated_by' => null,
                'updated_at' => now(),
            ];

            if ($targetId !== null && DB::table('enterprise_suppliers')->where('id', $targetId)->exists()) {
                DB::table('enterprise_suppliers')->where('id', $targetId)->update($payload);
                $updated++;
            } else {
                $payload['created_at'] = now();
                $targetId = (int) DB::table('enterprise_suppliers')->insertGetId($payload);
                $created++;
            }

            $this->syncMapping('wh_Suppliers', $sourceId, 'enterprise_suppliers', $targetId, $row, $runId);
        }

        return compact('created', 'updated');
    }

    private function importVans(string $runId): array
    {
        $created = 0;
        $updated = 0;
        $rows = DB::connection('foodex_legacy')->table('wh_Cars')->orderBy('ID')->get();

        foreach ($rows as $row) {
            $sourceId = (string) $row->ID;
            $targetId = $this->mappedTargetId('wh_Cars', $sourceId, 'vans');
            $warehouseId = $this->mappedTargetId('wh_Cars', $sourceId, 'warehouses');
            $plate = $this->legacyText($row->PlateNo ?? null, true);

            if ($plate !== null) {
                $duplicate = DB::table('vans')->where('plate_number', $plate);
                if ($targetId !== null) {
                    $duplicate->where('id', '<>', $targetId);
                }
                if ($duplicate->exists()) {
                    $plate = null;
                }
            }

            $payload = [
                'code' => 'FDX-VAN-'.(int) $row->ID,
                'plate_number' => $plate,
                'vehicle_type' => 'legacy_foodex',
                'status' => (int) ($row->Status ?? 1) === 0 ? 'inactive' : 'active',
                'capacity_units' => null,
                'capacity_weight' => null,
                'capabilities' => $this->json([
                    'legacy_car_id' => (int) $row->ID,
                    'legacy_code' => $this->legacyText($row->Code ?? null),
                    'legacy_name_ar' => $this->legacyText($row->Name_Ar ?? null),
                    'legacy_name_en' => $this->legacyText($row->Name_En ?? null),
                    'legacy_branch_id' => (int) ($row->BranchID ?? 0),
                    'legacy_sales_rep_id' => (int) ($row->SalesRepID ?? 0),
                    'legacy_driver_id' => (int) ($row->DriverId ?? 0),
                    'legacy_owner' => (bool) ($row->Owner ?? false),
                    'legacy_meter_reading' => (int) ($row->MeterReading ?? 0),
                    'legacy_chassis_no' => $this->legacyText($row->ChassisNo ?? null),
                    'legacy_motor_no' => $this->legacyText($row->MotorNo ?? null),
                    'legacy_cost_center_id' => (int) ($row->CostCenterId ?? 0),
                    'legacy_inventory_warehouse_id' => $warehouseId,
                ]),
                'home_warehouse_id' => $warehouseId,
                'notes' => $this->legacyText($row->Notes ?? null),
                'updated_at' => now(),
            ];

            if ($targetId !== null && DB::table('vans')->where('id', $targetId)->exists()) {
                DB::table('vans')->where('id', $targetId)->update($payload);
                $updated++;
            } else {
                $payload['public_id'] = (string) Str::uuid();
                $payload['created_at'] = now();
                $targetId = (int) DB::table('vans')->insertGetId($payload);
                $created++;
            }

            $this->syncMapping('wh_Cars', $sourceId, 'vans', $targetId, $row, $runId, [
                'inventory_warehouse_id' => $warehouseId,
                'legacy_sales_rep_id' => (int) ($row->SalesRepID ?? 0),
                'legacy_driver_id' => (int) ($row->DriverId ?? 0),
            ]);
        }

        return compact('created', 'updated');
    }

    private function preserveSalesRepReferences(string $runId): array
    {
        $created = 0;
        $updated = 0;
        $rows = DB::connection('foodex_legacy')->table('wh_SalesReps')->orderBy('ID')->get();

        foreach ($rows as $row) {
            $sourceKey = (string) $row->ID;
            $exists = DB::table('legacy_import_reference_records')
                ->where('source_system', self::SOURCE_SYSTEM)
                ->where('source_table', 'wh_SalesReps')
                ->where('source_key', $sourceKey)
                ->exists();

            $linkedCars = DB::connection('foodex_legacy')->table('wh_Cars')
                ->where('SalesRepID', (int) $row->ID)
                ->orderBy('ID')
                ->pluck('ID')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            DB::table('legacy_import_reference_records')->updateOrInsert(
                [
                    'source_system' => self::SOURCE_SYSTEM,
                    'source_table' => 'wh_SalesReps',
                    'source_key' => $sourceKey,
                ],
                [
                    'payload' => $this->json((array) $row),
                    'metadata' => $this->json([
                        'linked_legacy_car_ids' => $linkedCars,
                        'canonical_target' => null,
                        'reason' => 'FOODEX has no canonical sales representative entity; preserved without creating login identities.',
                    ]),
                    'last_run_id' => $runId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );

            $exists ? $updated++ : $created++;
        }

        return compact('created', 'updated');
    }

    private function legacyExtendedMastersRerunReady(array $sourceCounts): bool
    {
        if (
            ! Schema::hasTable('legacy_import_reference_records')
            || ! DB::table('legacy_import_runs')
                ->where('source_system', self::SOURCE_SYSTEM)
                ->where('phase', 'extended-masters')
                ->where('status', 'completed')
                ->exists()
        ) {
            return false;
        }

        foreach ([
            'addresses' => ['source' => 'wh_Customers.Address'],
            'enterprise_suppliers' => ['source' => 'wh_Suppliers'],
            'vans' => ['source' => 'wh_Cars'],
        ] as $targetTable => $ownership) {
            $mappedIds = DB::table('legacy_import_mappings')
                ->where('source_system', self::SOURCE_SYSTEM)
                ->where('source_table', $ownership['source'])
                ->where('target_table', $targetTable)
                ->pluck('target_id');

            if (
                (int) DB::table($targetTable)->count() !== $mappedIds->unique()->count()
                || DB::table($targetTable)->whereNotIn('id', $mappedIds)->exists()
            ) {
                return false;
            }
        }

        $salesRepReferences = (int) DB::table('legacy_import_reference_records')
            ->where('source_system', self::SOURCE_SYSTEM)
            ->where('source_table', 'wh_SalesReps')
            ->count();

        return $salesRepReferences === (int) $sourceCounts['wh_SalesReps'];
    }

    private function legacyText(mixed $value, bool $dropPlaceholderOne = false): ?string
    {
        $text = trim((string) $value);
        if ($text === '' || $text === '0' || ($dropPlaceholderOne && $text === '1')) {
            return null;
        }

        return $text;
    }

    private function ensureWholesaleCatalog(int $storeId): int
    {
        $catalog = DB::table('catalogs')
            ->where('store_id', $storeId)
            ->where('channel', 'b2b')
            ->where('is_migration_quarantine', false)
            ->orderByRaw("CASE WHEN code = 'default' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->first(['id', 'is_active']);

        if ($catalog !== null) {
            if (! (bool) $catalog->is_active) {
                DB::table('catalogs')->where('id', $catalog->id)->update([
                    'is_active' => true,
                    'updated_at' => now(),
                ]);
            }

            return (int) $catalog->id;
        }

        $existing = DB::table('catalogs')
            ->where('store_id', $storeId)
            ->where('code', 'legacy-foodex')
            ->first(['id']);

        if ($existing !== null) {
            DB::table('catalogs')->where('id', $existing->id)->update([
                'channel' => 'b2b',
                'name' => 'Legacy Foodex Catalog',
                'is_active' => true,
                'is_migration_quarantine' => false,
                'updated_at' => now(),
            ]);

            return (int) $existing->id;
        }

        return (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => 'b2b',
            'code' => 'legacy-foodex',
            'name' => 'Legacy Foodex Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function importB2bPrices(int $storeId, string $runId): array
    {
        $tiers = DB::connection('foodex_legacy')
            ->table('wh_PriceTypes')
            ->orderBy('ID')
            ->get();

        $items = DB::connection('foodex_legacy')
            ->table('wh_Items')
            ->orderBy('ID')
            ->get();

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($tiers as $tier) {
            $sourceTierId = (int) $tier->ID;
            $targetTierId = $this->mappedTargetId(
                'wh_PriceTypes',
                (string) $sourceTierId,
                'b2b_price_tiers',
            );

            if ($targetTierId === null) {
                continue;
            }

            foreach ($items as $item) {
                $productId = $this->mappedTargetId('wh_Items', (string) $item->ID, 'products');
                $unitPrice = $this->legacyPackagePrice($item, 1, $sourceTierId);

                if ($productId === null || $unitPrice === null || $unitPrice <= 0) {
                    $skipped++;
                    continue;
                }

                $existing = DB::table('b2b_price_rules')
                    ->where('price_tier_id', $targetTierId)
                    ->where('store_id', $storeId)
                    ->where('product_id', $productId)
                    ->first(['id']);

                $caseSize = isset($item->Pkg2Qty) && (float) $item->Pkg2Qty > 1
                    ? round((float) $item->Pkg2Qty, 3)
                    : null;
                $packLabel = trim((string) ($item->Pkg2Name ?? ''));

                $payload = [
                    'unit_price' => $unitPrice,
                    'minimum_quantity' => 1,
                    'ordering_increment' => 1,
                    'pack_size' => 1,
                    'case_size' => $caseSize,
                    'pack_label' => $packLabel === '' ? null : mb_substr($packLabel, 0, 255),
                    'retail_reference_price' => $this->legacyPackagePrice($item, 1, 5),
                    'is_active' => ! (bool) ($item->Inactive ?? false) && (int) ($item->Status ?? 1) !== 0,
                    'updated_at' => now(),
                ];

                if ($existing === null) {
                    $payload += [
                        'price_tier_id' => $targetTierId,
                        'store_id' => $storeId,
                        'product_id' => $productId,
                        'created_at' => now(),
                    ];
                    $ruleId = (int) DB::table('b2b_price_rules')->insertGetId($payload);
                    $created++;
                } else {
                    $ruleId = (int) $existing->id;
                    DB::table('b2b_price_rules')->where('id', $ruleId)->update($payload);
                    $updated++;
                }

                $this->syncMapping(
                    'wh_Items.PriceTier',
                    (int) $item->ID.':'.$sourceTierId,
                    'b2b_price_rules',
                    $ruleId,
                    $item,
                    $runId,
                    [
                        'legacy_price_id' => $sourceTierId,
                        'legacy_unit_price' => $unitPrice,
                    ],
                );
            }
        }

        // Keep canonical tier mode disabled until unresolved legacy PriceID=0 customers
        // and package-price consistency diagnostics have been explicitly cleared.
        DB::table('settings')->updateOrInsert(
            ['store_id' => $storeId, 'key' => B2bPricingMode::SETTING_KEY],
            [
                'value' => $this->json(false),
                'is_secret' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $unresolvedCustomerPriceIds = (int) DB::connection('foodex_legacy')
            ->table('wh_Customers')
            ->where(function ($query): void {
                $query->whereNull('PriceID')->orWhere('PriceID', '<=', 0);
            })
            ->where('Inactive', 0)
            ->where('Status', '<>', 0)
            ->count();

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'tiers_enabled' => false,
            'unresolved_active_customer_price_ids' => $unresolvedCustomerPriceIds,
        ];
    }

    private function legacyPackagePrice(object $row, int $package, int $tier): ?float
    {
        $field = 'Pkg'.$package.'Price'.$tier;
        if (! property_exists($row, $field)) {
            return null;
        }

        $value = (float) ($row->{$field} ?? 0);

        return $value > 0 ? round($value, 3) : null;
    }

    private function firstPositivePackagePrice(object $row, int $package): ?float
    {
        foreach ([5, 4, 3, 2, 1] as $tier) {
            $price = $this->legacyPackagePrice($row, $package, $tier);
            if ($price !== null) {
                return $price;
            }
        }

        return null;
    }

    private function importPriceTiers(string $runId): array
    {
        $rows = DB::connection('foodex_legacy')->table('wh_PriceTypes')->orderBy('ID')->get();
        $created = 0;
        $updated = 0;

        foreach ($rows as $row) {
            $sourceId = (string) $row->ID;
            $targetId = $this->mappedTargetId('wh_PriceTypes', $sourceId, 'b2b_price_tiers');
            $payload = [
                'code' => 'FDX-P'.(int) $row->ID,
                'name' => $this->name($row->Name_AR ?? null, $row->Name_En ?? null, 'Legacy price '.(int) $row->ID),
                'priority' => (int) $row->ID,
                'updated_at' => now(),
            ];

            if ($targetId !== null && DB::table('b2b_price_tiers')->where('id', $targetId)->exists()) {
                DB::table('b2b_price_tiers')->where('id', $targetId)->update($payload);
                $updated++;
            } else {
                $payload['created_at'] = now();
                $targetId = (int) DB::table('b2b_price_tiers')->insertGetId($payload);
                $created++;
            }

            $this->syncMapping(
                'wh_PriceTypes',
                $sourceId,
                'b2b_price_tiers',
                $targetId,
                $row,
                $runId,
            );
        }

        return compact('created', 'updated');
    }

    private function importWarehousesAndVans(int $storeId, string $runId): array
    {
        $created = 0;
        $updated = 0;

        foreach ([
            ['table' => 'wh_Stores', 'prefix' => 'FDX-STORE-', 'label' => 'Legacy Store'],
            ['table' => 'wh_Cars', 'prefix' => 'FDX-VAN-', 'label' => 'Legacy Van'],
        ] as $definition) {
            $rows = DB::connection('foodex_legacy')->table($definition['table'])->orderBy('ID')->get();

            foreach ($rows as $row) {
                $sourceId = (string) $row->ID;
                $targetId = $this->mappedTargetId($definition['table'], $sourceId, 'warehouses');
                $payload = [
                    'store_id' => $storeId,
                    'code' => $definition['prefix'].(int) $row->ID,
                    'name' => $this->name($row->Name_Ar ?? null, $row->Name_En ?? null, $definition['label'].' '.(int) $row->ID),
                    'is_active' => (int) ($row->Status ?? 1) !== 0,
                    'updated_at' => now(),
                ];

                if ($targetId !== null && DB::table('warehouses')->where('id', $targetId)->exists()) {
                    DB::table('warehouses')->where('id', $targetId)->update($payload);
                    $updated++;
                } else {
                    $payload['created_at'] = now();
                    $targetId = (int) DB::table('warehouses')->insertGetId($payload);
                    $created++;
                }

                $this->syncMapping(
                    $definition['table'],
                    $sourceId,
                    'warehouses',
                    $targetId,
                    $row,
                    $runId,
                );
            }
        }

        return compact('created', 'updated');
    }

    private function importCategories(int $catalogId, string $runId): array
    {
        $created = 0;
        $updated = 0;

        $groups = DB::connection('foodex_legacy')->table('wh_ItemGroups')->orderBy('ID')->get();
        foreach ($groups as $row) {
            [$wasCreated] = $this->upsertCategory(
                'wh_ItemGroups',
                (string) $row->ID,
                $catalogId,
                null,
                'legacy-foodex-group-'.(int) $row->ID,
                $this->name($row->Name_AR ?? null, $row->Name_En ?? null, 'Legacy group '.(int) $row->ID),
                (int) ($row->Status ?? 1) !== 0,
                $row,
                $runId,
            );
            $wasCreated ? $created++ : $updated++;
        }

        $subgroups = DB::connection('foodex_legacy')->table('wh_ItemSubGroups')->orderBy('ID')->get();
        foreach ($subgroups as $row) {
            $parentId = $row->ParentID === null
                ? null
                : $this->mappedTargetId('wh_ItemGroups', (string) $row->ParentID, 'categories');

            [$wasCreated] = $this->upsertCategory(
                'wh_ItemSubGroups',
                (string) $row->ID,
                $catalogId,
                $parentId,
                'legacy-foodex-subgroup-'.(int) $row->ID,
                $this->name($row->Name_AR ?? null, $row->Name_En ?? null, 'Legacy subgroup '.(int) $row->ID),
                (int) ($row->Status ?? 1) !== 0,
                $row,
                $runId,
            );
            $wasCreated ? $created++ : $updated++;
        }

        return compact('created', 'updated');
    }

    private function upsertCategory(
        string $sourceTable,
        string $sourceId,
        int $catalogId,
        ?int $parentId,
        string $slug,
        string $name,
        bool $active,
        object $source,
        string $runId,
    ): array {
        $targetId = $this->mappedTargetId($sourceTable, $sourceId, 'categories');
        $payload = [
            'catalog_id' => $catalogId,
            'parent_id' => $parentId,
            'name' => mb_substr($name, 0, 255),
            'slug' => $slug,
            'is_active' => $active,
            'updated_at' => now(),
        ];

        $created = false;
        if ($targetId !== null && DB::table('categories')->where('id', $targetId)->exists()) {
            DB::table('categories')->where('id', $targetId)->update($payload);
        } else {
            $payload['created_at'] = now();
            $targetId = (int) DB::table('categories')->insertGetId($payload);
            $created = true;
        }

        $this->syncMapping($sourceTable, $sourceId, 'categories', $targetId, $source, $runId);

        return [$created, $targetId];
    }

    private function ensureLegacyBaseUnit(): int
    {
        $unit = DB::table('units')->where('code', 'FDX-BASE')->first(['id']);
        if ($unit !== null) {
            return (int) $unit->id;
        }

        return (int) DB::table('units')->insertGetId([
            'code' => 'FDX-BASE',
            'name' => 'Legacy base unit',
            'decimal_places' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function importProducts(int $storeId, int $catalogId, int $unitId, string $runId): array
    {
        $barcodes = [];
        foreach (DB::connection('foodex_legacy')->table('wh_ItemBarCodes')->orderBy('ID')->get() as $barcode) {
            $key = (int) $barcode->ItemId.':'.(int) $barcode->Package;
            $value = trim((string) $barcode->ItemBarCode);
            if ($value !== '' && ! isset($barcodes[$key])) {
                $barcodes[$key] = $value;
            }
        }

        $created = 0;
        $updated = 0;
        $sellingUnits = 0;

        $rows = DB::connection('foodex_legacy')->table('wh_Items')->orderBy('ID')->get();
        foreach ($rows as $row) {
            $sourceId = (string) $row->ID;
            $targetId = $this->mappedTargetId('wh_Items', $sourceId, 'products');

            $categoryId = null;
            if ((int) ($row->ItemSubGroupId ?? 0) > 0) {
                $categoryId = $this->mappedTargetId('wh_ItemSubGroups', (string) $row->ItemSubGroupId, 'categories');
            }
            if ($categoryId === null && (int) ($row->ItemGroupId ?? 0) > 0) {
                $categoryId = $this->mappedTargetId('wh_ItemGroups', (string) $row->ItemGroupId, 'categories');
            }

            $legacyCode = trim((string) ($row->Code ?? ''));
            $notes = trim((string) ($row->Notes ?? ''));
            $descriptionParts = [];
            if ($legacyCode !== '') {
                $descriptionParts[] = 'Legacy Foodex code: '.$legacyCode;
            }
            if ($notes !== '' && $notes !== '0') {
                $descriptionParts[] = $notes;
            }

            $payload = [
                'catalog_id' => $catalogId,
                'category_id' => $categoryId,
                'brand_id' => null,
                'unit_id' => $unitId,
                'sku' => 'FDX-'.(int) $row->ID,
                'name' => mb_substr($this->name($row->Name_Ar ?? null, $row->Name_En ?? null, 'Legacy item '.(int) $row->ID), 0, 255),
                'description' => $descriptionParts === [] ? null : implode("\n", $descriptionParts),
                'is_active' => ! (bool) ($row->Inactive ?? false) && (int) ($row->Status ?? 1) !== 0,
                'updated_at' => now(),
            ];

            if ($targetId !== null && DB::table('products')->where('id', $targetId)->exists()) {
                DB::table('products')->where('id', $targetId)->update($payload);
                $updated++;
            } else {
                $payload['created_at'] = now();
                $targetId = (int) DB::table('products')->insertGetId($payload);
                $created++;
            }

            $defaultPrice = $this->legacyPackagePrice($row, 1, 5)
                ?? $this->firstPositivePackagePrice($row, 1);

            DB::table('store_products')->updateOrInsert(
                ['store_id' => $storeId, 'product_id' => $targetId],
                [
                    'price' => $defaultPrice,
                    'is_active' => $payload['is_active'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );

            $packageMetadata = [];
            foreach ([1, 2, 3, 4, 5] as $package) {
                $nameField = 'Pkg'.$package.'Name';
                $qtyField = 'Pkg'.$package.'Qty';
                $packageName = trim((string) ($row->{$nameField} ?? ''));
                $conversion = isset($row->{$qtyField}) && (float) $row->{$qtyField} > 0
                    ? (float) $row->{$qtyField}
                    : 1.0;

                $prices = [];
                foreach ([1, 2, 3, 4, 5] as $priceLevel) {
                    $priceField = 'Pkg'.$package.'Price'.$priceLevel;
                    if (property_exists($row, $priceField)) {
                        $prices[(string) $priceLevel] = (float) ($row->{$priceField} ?? 0);
                    }
                }

                $barcode = $barcodes[(int) $row->ID.':'.$package] ?? null;
                $hasPositivePrice = collect($prices)->contains(static fn (float $price): bool => $price > 0);

                if (
                    $package !== 1
                    && $packageName === ''
                    && $conversion === 1.0
                    && ! $hasPositivePrice
                    && $barcode === null
                ) {
                    continue;
                }

                if ($packageName === '') {
                    $packageName = $package === 1 ? 'Base' : 'Package '.$package;
                }

                DB::table('product_selling_units')->updateOrInsert(
                    ['product_id' => $targetId, 'code' => 'PKG'.$package],
                    [
                        'unit_id' => $package === 1 ? $unitId : null,
                        'name' => mb_substr($packageName, 0, 255),
                        'conversion_factor' => round($conversion, 3),
                        'price' => $this->legacyPackagePrice($row, $package, 5)
                            ?? $this->firstPositivePackagePrice($row, $package),
                        'sku' => $legacyCode === '' ? null : mb_substr($legacyCode.'-P'.$package, 0, 255),
                        'barcode' => $barcode === null ? null : mb_substr($barcode, 0, 255),
                        'is_base' => $package === 1,
                        'is_active' => $payload['is_active'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );

                $sellingUnitId = (int) DB::table('product_selling_units')
                    ->where('product_id', $targetId)
                    ->where('code', 'PKG'.$package)
                    ->value('id');

                foreach ([1, 2, 3, 4, 5] as $legacyPriceId) {
                    $exactPackagePrice = $this->legacyPackagePrice($row, $package, $legacyPriceId);
                    if ($exactPackagePrice === null) {
                        continue;
                    }

                    $targetTierId = $this->mappedTargetId(
                        'wh_PriceTypes',
                        (string) $legacyPriceId,
                        'b2b_price_tiers',
                    );

                    DB::table('product_selling_unit_tier_prices')->updateOrInsert(
                        [
                            'store_id' => $storeId,
                            'product_selling_unit_id' => $sellingUnitId,
                            'legacy_price_id' => $legacyPriceId,
                        ],
                        [
                            'price_tier_id' => $targetTierId,
                            'price' => $exactPackagePrice,
                            'is_active' => $payload['is_active'],
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                    );
                }

                $sellingUnits++;
                $packageMetadata['PKG'.$package] = [
                    'name' => $packageName,
                    'conversion_factor' => round($conversion, 3),
                    'prices' => $prices,
                    'barcode' => $barcode,
                ];
            }

            $this->syncMapping(
                'wh_Items',
                $sourceId,
                'products',
                $targetId,
                $row,
                $runId,
                [
                    'legacy_code' => $legacyCode,
                    'packages' => $packageMetadata,
                ],
            );
        }

        return compact('created', 'updated', 'sellingUnits');
    }

    private function importCustomers(string $runId, int $storeId): array
    {
        $created = 0;
        $updated = 0;
        $platformCreated = 0;
        $platformUpdated = 0;
        $rows = DB::connection('foodex_legacy')->table('wh_Customers')->orderBy('ID')->get();

        foreach ($rows as $row) {
            $sourceId = (string) $row->ID;
            $customerId = $this->mappedTargetId('wh_Customers', $sourceId, 'customers');

            $name = $this->name($row->Name_Ar ?? null, $row->Name_En ?? null, 'Legacy customer '.(int) $row->ID);
            $phone = trim((string) ($row->Mobile ?? ''));
            if ($phone === '') {
                $phone = trim((string) ($row->Phone1 ?? ''));
            }
            $email = trim((string) ($row->email ?? ''));
            $email = $email === '' ? null : mb_substr($email, 0, 255);

            $customerPayload = [
                'user_id' => null,
                'type' => 'b2b',
                'name' => mb_substr($name, 0, 255),
                'phone' => $phone === '' ? null : mb_substr($phone, 0, 255),
                'email' => $email,
                'updated_at' => now(),
            ];

            if ($customerId !== null && DB::table('customers')->where('id', $customerId)->exists()) {
                DB::table('customers')->where('id', $customerId)->update($customerPayload);
                $updated++;
            } else {
                $customerPayload['created_at'] = now();
                $customerId = (int) DB::table('customers')->insertGetId($customerPayload);
                $created++;
            }

            $this->syncMapping('wh_Customers', $sourceId, 'customers', $customerId, $row, $runId);

            $b2bCustomer = DB::table('b2b_customers')
                ->where('legacy_customer_id', $customerId)
                ->first(['id']);

            $b2bPayload = [
                'legacy_customer_id' => $customerId,
                'user_id' => null,
                'name' => mb_substr($name, 0, 255),
                'phone' => $phone === '' ? null : mb_substr($phone, 0, 255),
                'email' => $email,
                'updated_at' => now(),
            ];

            if ($b2bCustomer === null) {
                $b2bPayload['created_at'] = now();
                $b2bCustomerId = (int) DB::table('b2b_customers')->insertGetId($b2bPayload);
            } else {
                $b2bCustomerId = (int) $b2bCustomer->id;
                DB::table('b2b_customers')->where('id', $b2bCustomerId)->update($b2bPayload);
            }

            $this->syncMapping('wh_Customers', $sourceId, 'b2b_customers', $b2bCustomerId, $row, $runId);

            $priceTierId = (int) ($row->PriceID ?? 0) > 0
                ? $this->mappedTargetId('wh_PriceTypes', (string) $row->PriceID, 'b2b_price_tiers')
                : null;

            $active = ! (bool) ($row->Inactive ?? false) && (int) ($row->Status ?? 1) !== 0;
            DB::table('b2b_accounts')->updateOrInsert(
                ['customer_id' => $customerId],
                [
                    'b2b_customer_id' => $b2bCustomerId,
                    'price_tier_id' => $priceTierId,
                    'company_name' => mb_substr($name, 0, 255),
                    'status' => $active ? 'active' : 'inactive',
                    'tax_number' => null,
                    'credit_limit' => round((float) ($row->CreditLimit ?? 0), 3),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );

            $accountId = (int) DB::table('b2b_accounts')->where('customer_id', $customerId)->value('id');
            $this->syncMapping(
                'wh_Customers',
                $sourceId,
                'b2b_accounts',
                $accountId,
                $row,
                $runId,
                [
                    'legacy_customer_group_id' => (int) ($row->CustomerGroupID ?? 0),
                    'legacy_customer_category_id' => (int) ($row->CustomerCategoryId ?? 0),
                    'legacy_sales_rep_id' => (int) ($row->SalesRepId ?? 0),
                    'legacy_credit_period' => (int) ($row->CreditPeriod ?? 0),
                    'legacy_address' => trim((string) ($row->Address ?? '')),
                    'legacy_address_2' => trim((string) ($row->Address2 ?? '')),
                    'legacy_region_id' => (int) ($row->RegionID ?? 0),
                    'legacy_city_id' => (int) ($row->CityID ?? 0),
                    'legacy_district_id' => (int) ($row->DistrictID ?? 0),
                    'latitude' => $row->Latitude ?? null,
                    'longitude' => $row->Longitude ?? null,
                ],
            );

            $platformCustomer = DB::table('platform_customers')
                ->where('legacy_customer_id', $customerId)
                ->first(['id']);

            $platformPayload = [
                'name' => mb_substr($name, 0, 255),
                'phone' => $phone === '' ? null : mb_substr($phone, 0, 255),
                'email' => $email,
                'is_active' => $active,
                'updated_at' => now(),
            ];

            if ($platformCustomer === null) {
                $platformCustomerId = (int) DB::table('platform_customers')->insertGetId([
                    'user_id' => null,
                    'legacy_customer_id' => $customerId,
                    ...$platformPayload,
                    'origin_channel' => 'b2b',
                    'origin_store_id' => $storeId,
                    'registration_source' => 'import',
                    'registered_at' => null,
                    'created_at' => now(),
                ]);
                $platformCreated++;
            } else {
                $platformCustomerId = (int) $platformCustomer->id;
                DB::table('platform_customers')
                    ->where('id', $platformCustomerId)
                    ->update($platformPayload);
                $platformUpdated++;
            }

            $this->syncMapping(
                'wh_Customers',
                $sourceId,
                'platform_customers',
                $platformCustomerId,
                $row,
                $runId,
                [
                    'registration_source' => 'import',
                    'origin_channel' => 'b2b',
                    'origin_store_id' => $storeId,
                    'login_identity' => 'not_materialized',
                ],
            );
        }

        return compact('created', 'updated', 'platformCreated', 'platformUpdated');
    }

    private function applyStock(string $runId): array
    {
        $created = 0;
        $updated = 0;
        $quarantined = 0;

        $rows = DB::connection('foodex_legacy')->table('wh_ItemStockOnHand')
            ->orderBy('StoreID')
            ->orderBy('ItemID')
            ->get();

        foreach ($rows as $row) {
            $sourceKey = implode(':', [
                (int) $row->ItemID,
                (int) $row->StoreID,
                (int) $row->BranchID,
                (int) $row->StockDate,
            ]);

            $productId = $this->mappedTargetId('wh_Items', (string) $row->ItemID, 'products');
            $warehouseId = $this->mappedTargetId('wh_Stores', (string) $row->StoreID, 'warehouses')
                ?? $this->mappedTargetId('wh_Cars', (string) $row->StoreID, 'warehouses');

            if ($productId === null || $warehouseId === null) {
                $this->quarantine(
                    'wh_ItemStockOnHand',
                    $sourceKey,
                    $productId === null ? 'unmapped_product' : 'unmapped_inventory_location',
                    $row,
                    $runId,
                );
                $quarantined++;
                continue;
            }

            $inventory = DB::table('inventories')
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->first(['id']);

            $quantity = round((float) $row->CurrentStock, 3);
            if ($inventory === null) {
                $inventoryId = (int) DB::table('inventories')->insertGetId([
                    'warehouse_id' => $warehouseId,
                    'product_id' => $productId,
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
                    'updated_at' => now(),
                ]);
                $updated++;
            }

            $movementId = $this->mappedTargetId('wh_ItemStockOnHand', $sourceKey, 'stock_movements');
            $movementPayload = [
                'inventory_id' => $inventoryId,
                'user_id' => null,
                'type' => 'legacy_opening_balance',
                'quantity' => $quantity,
                'reference_type' => 'foodex_stock_snapshot',
                'reference_id' => null,
                'reason' => 'Legacy Foodex stock snapshot date '.(int) $row->StockDate,
                'updated_at' => now(),
            ];

            if ($movementId !== null && DB::table('stock_movements')->where('id', $movementId)->exists()) {
                DB::table('stock_movements')->where('id', $movementId)->update($movementPayload);
            } else {
                $movementPayload['created_at'] = now();
                $movementId = (int) DB::table('stock_movements')->insertGetId($movementPayload);
            }

            $this->syncMapping(
                'wh_ItemStockOnHand',
                $sourceKey,
                'stock_movements',
                $movementId,
                $row,
                $runId,
            );
        }

        return compact('created', 'updated', 'quarantined');
    }

    private function mappedTargetId(string $sourceTable, string $sourceKey, string $targetTable): ?int
    {
        if (! Schema::hasTable('legacy_import_mappings')) {
            return null;
        }

        $id = DB::table('legacy_import_mappings')
            ->where('source_system', self::SOURCE_SYSTEM)
            ->where('source_table', $sourceTable)
            ->where('source_key', $sourceKey)
            ->where('target_table', $targetTable)
            ->value('target_id');

        return $id === null ? null : (int) $id;
    }

    private function syncMapping(
        string $sourceTable,
        string $sourceKey,
        string $targetTable,
        int $targetId,
        object $source,
        string $runId,
        array $metadata = [],
    ): void {
        $sourceArray = (array) $source;
        $sourceJson = $this->json($sourceArray);

        DB::table('legacy_import_mappings')->updateOrInsert(
            [
                'source_system' => self::SOURCE_SYSTEM,
                'source_table' => $sourceTable,
                'source_key' => $sourceKey,
                'target_table' => $targetTable,
            ],
            [
                'target_id' => $targetId,
                'source_hash' => hash('sha256', $sourceJson),
                'metadata' => $this->json($metadata === [] ? $sourceArray : array_merge($sourceArray, ['_migration' => $metadata])),
                'last_run_id' => $runId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function quarantine(
        string $sourceTable,
        string $sourceKey,
        string $reason,
        object $source,
        string $runId,
    ): void {
        DB::table('legacy_import_quarantine')->updateOrInsert(
            [
                'source_system' => self::SOURCE_SYSTEM,
                'source_table' => $sourceTable,
                'source_key' => $sourceKey,
                'reason' => $reason,
            ],
            [
                'payload' => $this->json((array) $source),
                'run_id' => $runId,
                'resolved_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function name(mixed $arabic, mixed $english, string $fallback): string
    {
        $arabic = trim((string) $arabic);
        if ($arabic !== '' && $arabic !== '0') {
            return $arabic;
        }

        $english = trim((string) $english);
        if ($english !== '' && $english !== '0') {
            return $english;
        }

        return $fallback;
    }

    private function legacyMastersRerunReady(array $sourceCounts): bool
    {
        if (
            ! Schema::hasTable('legacy_import_runs')
            || ! Schema::hasTable('legacy_import_mappings')
            || ! DB::table('legacy_import_runs')
                ->where('source_system', self::SOURCE_SYSTEM)
                ->where('phase', 'masters')
                ->where('status', 'completed')
                ->exists()
        ) {
            return false;
        }

        $ownedTargets = [
            'warehouses' => [
                'sources' => ['wh_Stores', 'wh_Cars'],
                'expected' => (int) $sourceCounts['wh_Stores'] + (int) $sourceCounts['wh_Cars'],
            ],
            'categories' => [
                'sources' => ['wh_ItemGroups', 'wh_ItemSubGroups'],
                'expected' => (int) $sourceCounts['wh_ItemGroups'] + (int) $sourceCounts['wh_ItemSubGroups'],
            ],
            'products' => [
                'sources' => ['wh_Items'],
                'expected' => (int) $sourceCounts['wh_Items'],
            ],
            'customers' => [
                'sources' => ['wh_Customers'],
                'expected' => (int) $sourceCounts['wh_Customers'],
            ],
            'b2b_customers' => [
                'sources' => ['wh_Customers'],
                'expected' => (int) $sourceCounts['wh_Customers'],
            ],
            'b2b_accounts' => [
                'sources' => ['wh_Customers'],
                'expected' => (int) $sourceCounts['wh_Customers'],
            ],
        ];

        foreach ($ownedTargets as $targetTable => $ownership) {
            if (! Schema::hasTable($targetTable)) {
                return false;
            }

            $mappedCount = (int) DB::table('legacy_import_mappings')
                ->where('source_system', self::SOURCE_SYSTEM)
                ->where('target_table', $targetTable)
                ->whereIn('source_table', $ownership['sources'])
                ->distinct()
                ->count('target_id');

            if (
                $mappedCount !== $ownership['expected']
                || (int) DB::table($targetTable)->count() !== $mappedCount
            ) {
                return false;
            }
        }

        if (! Schema::hasTable('platform_customers')) {
            return false;
        }

        $platformCustomerCount = (int) DB::table('platform_customers')->count();
        $mappedPlatformCustomerCount = (int) DB::table('legacy_import_mappings')
            ->where('source_system', self::SOURCE_SYSTEM)
            ->where('source_table', 'wh_Customers')
            ->where('target_table', 'platform_customers')
            ->distinct()
            ->count('target_id');

        $platformCustomerBackfillPending = $platformCustomerCount === 0
            && $mappedPlatformCustomerCount === 0;
        $platformCustomerFullyOwned = $mappedPlatformCustomerCount === (int) $sourceCounts['wh_Customers']
            && $platformCustomerCount === $mappedPlatformCustomerCount;

        if (! $platformCustomerBackfillPending && ! $platformCustomerFullyOwned) {
            return false;
        }

        $mappedPriceRules = (int) DB::table('legacy_import_mappings')
            ->where('source_system', self::SOURCE_SYSTEM)
            ->where('source_table', 'wh_Items.PriceTier')
            ->where('target_table', 'b2b_price_rules')
            ->distinct()
            ->count('target_id');

        if ((int) DB::table('b2b_price_rules')->count() !== $mappedPriceRules) {
            return false;
        }

        $mappedTierCount = (int) DB::table('legacy_import_mappings')
            ->where('source_system', self::SOURCE_SYSTEM)
            ->where('source_table', 'wh_PriceTypes')
            ->where('target_table', 'b2b_price_tiers')
            ->distinct()
            ->count('target_id');

        if ($mappedTierCount !== (int) $sourceCounts['wh_PriceTypes']) {
            return false;
        }

        if (
            DB::table('units')
                ->whereNotIn('code', ['PC', 'KG', 'G', 'L', 'ML', 'PACK', 'BOX', 'FDX-BASE'])
                ->exists()
        ) {
            return false;
        }

        $legacyProductIds = DB::table('legacy_import_mappings')
            ->select('target_id')
            ->where('source_system', self::SOURCE_SYSTEM)
            ->where('source_table', 'wh_Items')
            ->where('target_table', 'products');

        foreach (['product_selling_units', 'store_products'] as $table) {
            if (
                Schema::hasTable($table)
                && DB::table($table)->whereNotIn('product_id', clone $legacyProductIds)->exists()
            ) {
                return false;
            }
        }

        if (
            Schema::hasTable('product_selling_unit_tier_prices')
            && DB::table('product_selling_unit_tier_prices')
                ->whereNotIn(
                    'product_selling_unit_id',
                    DB::table('product_selling_units')
                        ->select('id')
                        ->whereIn('product_id', clone $legacyProductIds)
                )
                ->exists()
        ) {
            return false;
        }

        foreach ([
            'b2c_customers',
            'addresses',
            'brands',
            'inventories',
            'stock_movements',
            'orders',
            'order_items',
            'invoices',
            'invoice_items',
            'payments',
            'enterprise_suppliers',
        ] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                return false;
            }
        }

        if (
            (int) DB::table('stores')->count() !== 1
            || ! DB::table('stores')->where('code', WholesalePrincipal::STORE_CODE)->exists()
            || (int) DB::table('catalogs')->count() !== 1
        ) {
            return false;
        }

        return true;
    }

    private function targetBusinessCounts(): array
    {
        $tables = [
            'customers',
            'platform_customers',
            'b2b_customers',
            'b2c_customers',
            'b2b_accounts',
            'addresses',
            'warehouses',
            'vans',
            'categories',
            'brands',
            'products',
            'product_selling_units',
            'product_selling_unit_tier_prices',
            'store_products',
            'b2b_price_rules',
            'inventories',
            'stock_movements',
            'orders',
            'order_items',
            'invoices',
            'invoice_items',
            'payments',
            'enterprise_suppliers',
        ];

        $counts = [];
        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                $counts[$table] = (int) DB::table($table)->count();
            }
        }

        // Fresh FOODEX intentionally seeds seven canonical measurement units and
        // three canonical B2B price tiers. They are platform reference data, not
        // business/demo records, so they must not block a clean legacy cutover.
        if (Schema::hasTable('units')) {
            $counts['units_non_reference'] = (int) DB::table('units')
                ->whereNotIn('code', ['PC', 'KG', 'G', 'L', 'ML', 'PACK', 'BOX'])
                ->count();
        }

        if (Schema::hasTable('b2b_price_tiers')) {
            $counts['b2b_price_tiers_non_reference'] = (int) DB::table('b2b_price_tiers')
                ->whereNotIn('code', ['STANDARD', 'WHOLESALE', 'VIP'])
                ->count();
        }

        return $counts;
    }

    private function assertTrackingTables(): void
    {
        foreach (['legacy_import_runs', 'legacy_import_mappings', 'legacy_import_quarantine'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Missing {$table}. Run php artisan migrate first.");
            }
        }
    }

    private function json(mixed $value): string
    {
        return (string) json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }
}
