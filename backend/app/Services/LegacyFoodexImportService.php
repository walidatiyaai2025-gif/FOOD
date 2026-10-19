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
