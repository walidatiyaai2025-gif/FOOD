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

        $b2bStores = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('store_types.code', 'B2B')
            ->where('stores.is_active', true)
            ->orderBy('stores.id')
            ->get(['stores.id', 'stores.code', 'stores.name']);

        return [
            'source_connection' => 'foodex_legacy',
            'source_database' => (string) config('database.connections.foodex_legacy.database'),
            'source_counts' => $sourceCounts,
            'transaction_date_range' => [
                'first' => $dateRange?->first_date === null ? null : (int) $dateRange->first_date,
                'last' => $dateRange?->last_date === null ? null : (int) $dateRange->last_date,
            ],
            'target' => [
                'database_driver' => DB::connection()->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME),
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
                    'stock_snapshots' => $preflight['source_counts']['wh_ItemStockOnHand'],
                ],
                'preflight' => $preflight,
            ];
        }

        $this->assertTrackingTables();

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
            $storeId = app(WholesalePrincipal::class)->storeId();

            $summary = [
                'run_id' => $runId,
                'store_id' => $storeId,
                'price_tiers' => $this->importPriceTiers($runId),
                'warehouses' => $this->importWarehousesAndVans($storeId, $runId),
                'categories' => $this->importCategories($runId),
            ];

            $unitId = $this->ensureLegacyBaseUnit();
            $summary['products'] = $this->importProducts($storeId, $unitId, $runId);
            $summary['customers'] = $this->importCustomers($runId);
            $summary['stock'] = $this->importStock($runId);

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

    private function importCategories(string $runId): array
    {
        $created = 0;
        $updated = 0;

        $groups = DB::connection('foodex_legacy')->table('wh_ItemGroups')->orderBy('ID')->get();
        foreach ($groups as $row) {
            [$wasCreated] = $this->upsertCategory(
                'wh_ItemGroups',
                (string) $row->ID,
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
        ?int $parentId,
        string $slug,
        string $name,
        bool $active,
        object $source,
        string $runId,
    ): array {
        $targetId = $this->mappedTargetId($sourceTable, $sourceId, 'categories');
        $payload = [
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

    private function importProducts(int $storeId, int $unitId, string $runId): array
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

            DB::table('store_products')->updateOrInsert(
                ['store_id' => $storeId, 'product_id' => $targetId],
                [
                    'price' => null,
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

                if ($packageName === '' && $package !== 1 && $prices === []) {
                    continue;
                }

                if ($packageName === '') {
                    $packageName = $package === 1 ? 'Base' : 'Package '.$package;
                }

                $barcode = $barcodes[(int) $row->ID.':'.$package] ?? null;
                DB::table('product_selling_units')->updateOrInsert(
                    ['product_id' => $targetId, 'code' => 'PKG'.$package],
                    [
                        'unit_id' => $package === 1 ? $unitId : null,
                        'name' => mb_substr($packageName, 0, 255),
                        'conversion_factor' => round($conversion, 3),
                        'price' => null,
                        'sku' => $legacyCode === '' ? null : mb_substr($legacyCode.'-P'.$package, 0, 255),
                        'barcode' => $barcode === null ? null : mb_substr($barcode, 0, 255),
                        'is_base' => $package === 1,
                        'is_active' => $payload['is_active'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
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

    private function importCustomers(string $runId): array
    {
        $created = 0;
        $updated = 0;
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
        }

        return compact('created', 'updated');
    }

    private function importStock(string $runId): array
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
