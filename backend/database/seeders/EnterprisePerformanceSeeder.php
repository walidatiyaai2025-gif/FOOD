<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class EnterprisePerformanceSeeder extends Seeder
{
    public const LOAD_USERS = 50;

    public const FIELD_USERS = 25;

    private const PREFIX = 'PERF-';

    /**
     * @var array<string, array{
     *     tenants:int,
     *     stores:int,
     *     warehouses:int,
     *     products:int,
     *     customers:int,
     *     orders:int,
     *     order_items:int,
     *     stock_movements:int,
     *     finance_records:int,
     *     audit_events_messages:int
     * }>
     */
    private const PROFILES = [
        'smoke' => [
            'tenants' => 3,
            'stores' => 3,
            'warehouses' => 6,
            'products' => 60,
            'customers' => 60,
            'orders' => 200,
            'order_items' => 1000,
            'stock_movements' => 500,
            'finance_records' => 200,
            'audit_events_messages' => 500,
        ],
        'standard' => [
            'tenants' => 3,
            'stores' => 6,
            'warehouses' => 12,
            'products' => 600,
            'customers' => 2000,
            'orders' => 10000,
            'order_items' => 50000,
            'stock_movements' => 25000,
            'finance_records' => 10000,
            'audit_events_messages' => 25000,
        ],
        'legacy1x' => [
            'tenants' => 3,
            'stores' => 6,
            'warehouses' => 12,
            'products' => 600,
            'customers' => 3600,
            'orders' => 12500,
            'order_items' => 187500,
            'stock_movements' => 62500,
            'finance_records' => 25000,
            'audit_events_messages' => 62500,
        ],
        'legacy4x' => [
            'tenants' => 3,
            'stores' => 6,
            'warehouses' => 12,
            'products' => 2400,
            'customers' => 14400,
            'orders' => 50000,
            'order_items' => 750000,
            'stock_movements' => 250000,
            'finance_records' => 100000,
            'audit_events_messages' => 250000,
        ],
        'heavy' => [
            'tenants' => 3,
            'stores' => 6,
            'warehouses' => 12,
            'products' => 1200,
            'customers' => 10000,
            'orders' => 100000,
            'order_items' => 500000,
            'stock_movements' => 250000,
            'finance_records' => 100000,
            'audit_events_messages' => 250000,
        ],
    ];

    private CarbonImmutable $anchor;

    public function __construct()
    {
        $this->anchor = CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC');
    }

    /**
     * @return array<string, array<string, int>>
     */
    public static function profiles(): array
    {
        return self::PROFILES;
    }

    public function run(): void
    {
        $this->guardEnvironment();

        $profileName = strtolower((string) (getenv('FOODEX_PERF_PROFILE') ?: 'smoke'));
        $profile = self::PROFILES[$profileName] ?? null;

        if ($profile === null) {
            throw new RuntimeException('Unsupported FOODEX_PERF_PROFILE: '.$profileName);
        }

        $this->call(CoreReferenceSeeder::class);
        $this->cleanupFixture();

        $stores = $this->seedStores($profileName, $profile);
        $productsByStore = $this->seedProducts($profileName, $profile, $stores);
        $customers = $this->seedCustomers($profileName, $profile, array_keys($stores));
        $this->seedInventories($profileName, $profile, $stores, $productsByStore);
        $this->seedOrders($profileName, $profile, $customers, $productsByStore);
        $this->seedStockMovements($profileName, $profile);
        $this->seedFinance($profileName, $profile);
        $this->seedAudit($profileName, $profile, array_keys($stores));

        if (str_starts_with($profileName, 'legacy')) {
            $this->seedLoadUsers($stores);
        }

        $manifest = $this->manifest($profileName, $profile);
        $this->assertManifest($profile, $manifest['actual']);
        $this->writeManifest($manifest);
    }

    private function guardEnvironment(): void
    {
        if (app()->environment(['local', 'testing'])) {
            return;
        }

        if ((string) getenv('FOODEX_PERF_ALLOW_SEED') === '1') {
            return;
        }

        throw new RuntimeException(
            'Enterprise performance fixture is blocked outside local/testing. '
            .'Set FOODEX_PERF_ALLOW_SEED=1 only on an isolated benchmark database.'
        );
    }

    private function cleanupFixture(): void
    {
        $this->cleanupLoadUsers();

        DB::table('payments')->where('provider_reference', 'like', self::PREFIX.'%')->delete();
        DB::table('invoices')->where('invoice_number', 'like', self::PREFIX.'%')->delete();
        DB::table('orders')->where('order_number', 'like', self::PREFIX.'%')->delete();
        DB::table('audit_logs')->where('event', 'like', 'perf.fixture.%')->delete();

        DB::table('b2c_customers')
            ->whereIn('legacy_customer_id', function ($query): void {
                $query->select('id')
                    ->from('customers')
                    ->where('email', 'like', 'perf-c-%@example.test');
            })
            ->delete();

        DB::table('customers')->where('email', 'like', 'perf-c-%@example.test')->delete();

        $warehouseIds = DB::table('warehouses')
            ->where('code', 'like', self::PREFIX.'%')
            ->pluck('id');

        if ($warehouseIds->isNotEmpty()) {
            DB::table('warehouses')->whereIn('id', $warehouseIds)->delete();
        }

        DB::table('products')->where('sku', 'like', self::PREFIX.'%')->delete();
        DB::table('catalogs')->where('code', 'like', 'perf-%')->delete();
        DB::table('stores')->where('code', 'like', self::PREFIX.'%')->delete();
    }

    private function cleanupLoadUsers(): void
    {
        $userIds = DB::table('users')
            ->where(function ($query): void {
                $query->where('email', 'like', 'perf-driver-%@example.test')
                    ->orWhere('email', 'like', 'perf-office-%@example.test');
            })
            ->pluck('id');

        if ($userIds->isEmpty()) {
            return;
        }

        $driverIds = DB::table('drivers')
            ->whereIn('user_id', $userIds)
            ->pluck('id');

        if ($driverIds->isNotEmpty()) {
            if (Schema::hasTable('driver_current_locations')) {
                DB::table('driver_current_locations')->whereIn('driver_id', $driverIds)->delete();
            }

            if (Schema::hasTable('fleet_current_locations')) {
                DB::table('fleet_current_locations')
                    ->where('actor_type', 'driver')
                    ->whereIn('actor_id', $driverIds)
                    ->delete();
            }

            DB::table('drivers')->whereIn('id', $driverIds)->delete();
        }

        if (Schema::hasTable('personal_access_tokens')) {
            DB::table('personal_access_tokens')
                ->where('tokenable_type', 'App\\Models\\User')
                ->whereIn('tokenable_id', $userIds)
                ->delete();
        }

        DB::table('role_user')->whereIn('user_id', $userIds)->delete();

        if (Schema::hasTable('user_store_roles')) {
            DB::table('user_store_roles')->whereIn('user_id', $userIds)->delete();
        }

        DB::table('users')->whereIn('id', $userIds)->delete();
    }

    /**
     * Provision the deterministic 50-user workload required by #2274:
     * 25 active field/Driver users and 25 office/SUPER_ADMIN users.
     *
     * @param array<int, int> $stores
     */
    private function seedLoadUsers(array $stores): void
    {
        $driverRoleId = (int) DB::table('roles')->where('code', 'B2C_DRIVER')->value('id');
        $officeRoleId = (int) DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id');

        if ($driverRoleId <= 0 || $officeRoleId <= 0) {
            throw new RuntimeException('Performance load users require B2C_DRIVER and SUPER_ADMIN roles.');
        }

        $storeIds = array_keys($stores);
        if ($storeIds === []) {
            throw new RuntimeException('Performance load users require at least one seeded store.');
        }

        $password = Hash::make('performance-password');
        $now = $this->anchor;

        for ($index = 1; $index <= self::FIELD_USERS; $index++) {
            $userId = (int) DB::table('users')->insertGetId([
                'name' => sprintf('Performance Driver %02d', $index),
                'username' => sprintf('perf_driver_%03d', $index),
                'email' => sprintf('perf-driver-%03d@example.test', $index),
                'password' => $password,
                'locale' => 'en',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('role_user')->insert([
                'role_id' => $driverRoleId,
                'user_id' => $userId,
            ]);

            DB::table('drivers')->insert([
                'user_id' => $userId,
                'store_id' => $storeIds[($index - 1) % count($storeIds)],
                'driver_type' => 'b2c',
                'is_available' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        for ($index = 1; $index <= self::LOAD_USERS - self::FIELD_USERS; $index++) {
            $userId = (int) DB::table('users')->insertGetId([
                'name' => sprintf('Performance Office %02d', $index),
                'username' => sprintf('perf_office_%03d', $index),
                'email' => sprintf('perf-office-%03d@example.test', $index),
                'password' => $password,
                'locale' => 'en',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('role_user')->insert([
                'role_id' => $officeRoleId,
                'user_id' => $userId,
            ]);
        }
    }

    /**
     * @param array<string, int> $profile
     * @return array<int, int> store_id => catalog_id
     */
    private function seedStores(string $profileName, array $profile): array
    {
        $storeTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        if ($storeTypeId <= 0) {
            throw new RuntimeException('B2C store type is required for the Enterprise performance fixture.');
        }

        $stores = [];
        $warehousesPerStore = intdiv($profile['warehouses'], $profile['stores']);

        for ($index = 0; $index < $profile['stores']; $index++) {
            $tenant = ($index % $profile['tenants']) + 1;
            $storeNumber = $index + 1;
            $storeCode = sprintf('%sT%02d-S%02d', self::PREFIX, $tenant, $storeNumber);
            $now = $this->anchor->addMinutes($storeNumber);

            $storeId = (int) DB::table('stores')->insertGetId([
                'store_type_id' => $storeTypeId,
                'code' => $storeCode,
                'name' => sprintf('Performance Tenant %02d Store %02d', $tenant, $storeNumber),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $catalogId = (int) DB::table('catalogs')->insertGetId([
                'store_id' => $storeId,
                'channel' => 'b2c',
                'code' => 'perf-'.$profileName,
                'name' => 'Performance '.$profileName.' catalog',
                'is_active' => true,
                'is_migration_quarantine' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $stores[$storeId] = $catalogId;

            for ($warehouse = 1; $warehouse <= $warehousesPerStore; $warehouse++) {
                DB::table('warehouses')->insert([
                    'store_id' => $storeId,
                    'code' => sprintf('%sW-%02d-%02d', self::PREFIX, $storeNumber, $warehouse),
                    'name' => sprintf('Performance Warehouse %02d-%02d', $storeNumber, $warehouse),
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        return $stores;
    }

    /**
     * @param array<string, int> $profile
     * @param array<int, int> $stores
     * @return array<int, array<int, int>>
     */
    private function seedProducts(string $profileName, array $profile, array $stores): array
    {
        DB::table('units')->updateOrInsert(
            ['scope_key' => 'global', 'code' => 'PERF_EA'],
            [
                'store_id' => null,
                'scope' => 'global',
                'name' => 'Performance Each',
                'name_ar' => 'وحدة أداء',
                'name_en' => 'Performance Each',
                'decimal_places' => 0,
                'is_active' => true,
                'created_at' => $this->anchor,
                'updated_at' => $this->anchor,
            ],
        );

        $unitId = (int) DB::table('units')
            ->where('scope_key', 'global')
            ->where('code', 'PERF_EA')
            ->value('id');

        $storeIds = array_keys($stores);
        $rows = [];

        for ($index = 1; $index <= $profile['products']; $index++) {
            $storeId = $storeIds[($index - 1) % count($storeIds)];
            $rows[] = [
                'catalog_id' => $stores[$storeId],
                'category_id' => null,
                'brand_id' => null,
                'unit_id' => $unitId,
                'sku' => sprintf('%s%s-P-%06d', self::PREFIX, strtoupper($profileName), $index),
                'name' => 'Performance Product '.$index,
                'description' => 'Deterministic Enterprise performance fixture product.',
                'is_active' => true,
                'created_at' => $this->anchor,
                'updated_at' => $this->anchor,
            ];
        }

        $this->insertRows('products', $rows);

        $productsByStore = [];

        foreach (DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->where('products.sku', 'like', self::PREFIX.'%')
            ->orderBy('products.id')
            ->get(['products.id', 'catalogs.store_id']) as $product) {
            $storeId = (int) $product->store_id;
            $productsByStore[$storeId] ??= [];
            $productsByStore[$storeId][] = (int) $product->id;
        }

        return $productsByStore;
    }

    /**
     * @param array<string, int> $profile
     * @param array<int, int> $storeIds
     * @return array<int, object>
     */
    private function seedCustomers(string $profileName, array $profile, array $storeIds): array
    {
        $legacyRows = [];

        for ($index = 1; $index <= $profile['customers']; $index++) {
            $legacyRows[] = [
                'user_id' => null,
                'type' => 'b2c',
                'name' => 'Performance Customer '.$index,
                'phone' => sprintf('+965%08d', $index),
                'email' => sprintf('perf-c-%s-%06d@example.test', $profileName, $index),
                'created_at' => $this->anchor,
                'updated_at' => $this->anchor,
            ];
        }

        $this->insertRows('customers', $legacyRows);

        $legacyCustomers = DB::table('customers')
            ->where('email', 'like', 'perf-c-'.$profileName.'-%@example.test')
            ->orderBy('id')
            ->get(['id', 'name', 'phone', 'email', 'created_at', 'updated_at']);

        $domainRows = [];

        foreach ($legacyCustomers as $offset => $customer) {
            $storeId = $storeIds[$offset % count($storeIds)];
            $domainRows[] = [
                'legacy_customer_id' => (int) $customer->id,
                'store_id' => $storeId,
                'user_id' => null,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'created_at' => $customer->created_at,
                'updated_at' => $customer->updated_at,
            ];
        }

        $this->insertRows('b2c_customers', $domainRows);

        return DB::table('b2c_customers')
            ->join('customers', 'customers.id', '=', 'b2c_customers.legacy_customer_id')
            ->where('customers.email', 'like', 'perf-c-'.$profileName.'-%@example.test')
            ->orderBy('b2c_customers.id')
            ->get([
                'b2c_customers.id',
                'b2c_customers.legacy_customer_id',
                'b2c_customers.store_id',
            ])
            ->all();
    }

    /**
     * @param array<string, int> $profile
     * @param array<int, int> $stores
     * @param array<int, array<int, int>> $productsByStore
     */
    private function seedInventories(
        string $profileName,
        array $profile,
        array $stores,
        array $productsByStore,
    ): void {
        $rows = [];

        foreach (DB::table('warehouses')
            ->where('code', 'like', self::PREFIX.'%')
            ->orderBy('id')
            ->get(['id', 'store_id']) as $warehouse) {
            $productIds = $productsByStore[(int) $warehouse->store_id] ?? [];

            foreach ($productIds as $productId) {
                $rows[] = [
                    'warehouse_id' => (int) $warehouse->id,
                    'product_id' => $productId,
                    'quantity' => 1000,
                    'reserved_quantity' => 0,
                    'created_at' => $this->anchor,
                    'updated_at' => $this->anchor,
                ];
            }
        }

        $this->insertRows('inventories', $rows);
    }

    /**
     * @param array<string, int> $profile
     * @param array<int, object> $customers
     * @param array<int, array<int, int>> $productsByStore
     */
    private function seedOrders(
        string $profileName,
        array $profile,
        array $customers,
        array $productsByStore,
    ): void {
        $this->insertGenerated(
            'orders',
            $profile['orders'],
            function (int $index) use ($customers, $profileName): array {
                $customer = $customers[($index - 1) % count($customers)];
                $createdAt = $this->anchor
                    ->addDays(($index - 1) % 365)
                    ->addSeconds(($index * 37) % 86400);
                $subtotal = 25 + (($index % 200) / 10);

                return [
                    'store_id' => (int) $customer->store_id,
                    'customer_id' => (int) $customer->legacy_customer_id,
                    'b2b_customer_id' => null,
                    'b2c_customer_id' => (int) $customer->id,
                    'address_id' => null,
                    'order_number' => sprintf(
                        '%s%s-O-%08d',
                        self::PREFIX,
                        strtoupper($profileName),
                        $index
                    ),
                    'channel' => 'b2c',
                    'status' => match ($index % 4) {
                        0 => 'pending',
                        1 => 'confirmed',
                        2 => 'delivered',
                        default => 'cancelled',
                    },
                    'currency' => 'EGP',
                    'subtotal' => $subtotal,
                    'discount_total' => 0,
                    'delivery_total' => 5,
                    'grand_total' => $subtotal + 5,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];
            },
        );

        $itemsPerOrder = intdiv($profile['order_items'], $profile['orders']);

        DB::table('orders')
            ->where('order_number', 'like', self::PREFIX.strtoupper($profileName).'-%')
            ->orderBy('id')
            ->chunkById($this->queryChunkSize(), function ($orders) use ($itemsPerOrder, $productsByStore): void {
                $rows = [];

                foreach ($orders as $order) {
                    $productIds = $productsByStore[(int) $order->store_id] ?? [];
                    $productCount = count($productIds);

                    if ($productCount === 0) {
                        throw new RuntimeException('Performance fixture store has no products.');
                    }

                    for ($item = 0; $item < $itemsPerOrder; $item++) {
                        $productId = $productIds[((int) $order->id + $item) % $productCount];
                        $unitPrice = 5 + (($productId % 100) / 10);

                        $rows[] = [
                            'order_id' => (int) $order->id,
                            'product_id' => $productId,
                            'sku_snapshot' => 'PERF-SKU-'.$productId,
                            'name_snapshot' => 'Performance Product '.$productId,
                            'quantity' => 1,
                            'unit_price' => $unitPrice,
                            'line_total' => $unitPrice,
                            'created_at' => $order->created_at,
                            'updated_at' => $order->updated_at,
                        ];
                    }
                }

                $this->insertRows('order_items', $rows);
            });
    }

    /**
     * @param array<string, int> $profile
     */
    private function seedStockMovements(string $profileName, array $profile): void
    {
        $inventoryIds = DB::table('inventories')
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->where('warehouses.code', 'like', self::PREFIX.'%')
            ->orderBy('inventories.id')
            ->pluck('inventories.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($inventoryIds === []) {
            throw new RuntimeException('Performance fixture inventories are missing.');
        }

        $this->insertGenerated(
            'stock_movements',
            $profile['stock_movements'],
            function (int $index) use ($inventoryIds, $profileName): array {
                $createdAt = $this->anchor->addSeconds($index);

                return [
                    'inventory_id' => $inventoryIds[($index - 1) % count($inventoryIds)],
                    'user_id' => null,
                    'type' => $index % 2 === 0 ? 'in' : 'out',
                    'quantity' => $index % 2 === 0 ? 5 : -2,
                    'reference_type' => 'performance_fixture',
                    'reference_id' => $index,
                    'reason' => 'PERF-'.$profileName,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];
            },
        );
    }

    /**
     * @param array<string, int> $profile
     */
    private function seedFinance(string $profileName, array $profile): void
    {
        $invoiceTarget = intdiv($profile['finance_records'], 2);
        $paymentTarget = $profile['finance_records'] - $invoiceTarget;
        $invoiceIndex = 0;

        $this->forEachOrderChunk($profileName, $invoiceTarget, function ($orders) use ($profileName, &$invoiceIndex): void {
            $rows = [];

            foreach ($orders as $order) {
                $invoiceIndex++;
                $rows[] = [
                    'order_id' => (int) $order->id,
                    'store_id' => (int) $order->store_id,
                    'customer_id' => (int) $order->customer_id,
                    'b2b_customer_id' => null,
                    'b2c_customer_id' => (int) $order->b2c_customer_id,
                    'invoice_number' => sprintf(
                        '%s%s-I-%08d',
                        self::PREFIX,
                        strtoupper($profileName),
                        $invoiceIndex
                    ),
                    'status' => 'issued',
                    'currency' => 'EGP',
                    'total' => $order->grand_total,
                    'issued_at' => $order->created_at,
                    'due_at' => null,
                    'channel' => 'b2c',
                    'order_number_snapshot' => $order->order_number,
                    'created_at' => $order->created_at,
                    'updated_at' => $order->updated_at,
                ];
            }

            $this->insertRows('invoices', $rows);
        });

        $paymentIndex = 0;
        $lastId = 0;
        $remaining = $paymentTarget;

        while ($remaining > 0) {
            $take = min($this->queryChunkSize(), $remaining);
            $invoices = DB::table('invoices')
                ->where('id', '>', $lastId)
                ->where('invoice_number', 'like', self::PREFIX.strtoupper($profileName).'-%')
                ->orderBy('id')
                ->limit($take)
                ->get(['id', 'order_id', 'total', 'created_at', 'updated_at']);

            if ($invoices->isEmpty()) {
                break;
            }

            $rows = [];

            foreach ($invoices as $invoice) {
                $paymentIndex++;
                $lastId = (int) $invoice->id;
                $rows[] = [
                    'order_id' => $invoice->order_id,
                    'invoice_id' => (int) $invoice->id,
                    'provider' => 'performance_fixture',
                    'provider_reference' => sprintf(
                        '%s%s-PAY-%08d',
                        self::PREFIX,
                        strtoupper($profileName),
                        $paymentIndex
                    ),
                    'status' => 'paid',
                    'amount' => $invoice->total,
                    'currency' => 'EGP',
                    'metadata' => json_encode(['fixture' => true], JSON_THROW_ON_ERROR),
                    'created_at' => $invoice->created_at,
                    'updated_at' => $invoice->updated_at,
                ];
            }

            $this->insertRows('payments', $rows);
            $remaining -= count($rows);
        }
    }

    /**
     * @param array<string, int> $profile
     * @param array<int, int> $storeIds
     */
    private function seedAudit(string $profileName, array $profile, array $storeIds): void
    {
        $hasStoreId = Schema::hasColumn('audit_logs', 'store_id');

        $this->insertGenerated(
            'audit_logs',
            $profile['audit_events_messages'],
            function (int $index) use ($hasStoreId, $profileName, $storeIds): array {
                $createdAt = $this->anchor->addSeconds($index);
                $row = [
                    'user_id' => null,
                    'event' => 'perf.fixture.'.$profileName,
                    'auditable_type' => 'performance_fixture',
                    'auditable_id' => $index,
                    'before' => null,
                    'after' => json_encode(['sequence' => $index], JSON_THROW_ON_ERROR),
                    'ip_address' => null,
                    'user_agent' => 'FOODEX Enterprise Performance Harness',
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];

                if ($hasStoreId) {
                    $row['store_id'] = $storeIds[($index - 1) % count($storeIds)];
                }

                return $row;
            },
        );
    }

    /**
     * @param array<string, int> $profile
     * @return array<string, mixed>
     */
    private function manifest(string $profileName, array $profile): array
    {
        $orderPattern = self::PREFIX.strtoupper($profileName).'-%';
        $customerPattern = 'perf-c-'.$profileName.'-%@example.test';

        return [
            'schema_version' => 1,
            'profile' => $profileName,
            'generated_at' => now()->toIso8601String(),
            'anchor_utc' => $this->anchor->toIso8601String(),
            'tenant_scope' => [
                'mode' => 'logical_store_groups_pre_wave0',
                'logical_tenants' => $profile['tenants'],
                'physical_tenant_table_available' => Schema::hasTable('tenants'),
                'wave0_dependency' => 1487,
            ],
            'targets' => $profile,
            'actual' => [
                'tenants' => $profile['tenants'],
                'stores' => DB::table('stores')->where('code', 'like', self::PREFIX.'%')->count(),
                'warehouses' => DB::table('warehouses')->where('code', 'like', self::PREFIX.'%')->count(),
                'products' => DB::table('products')->where('sku', 'like', self::PREFIX.'%')->count(),
                'customers' => DB::table('customers')->where('email', 'like', $customerPattern)->count(),
                'orders' => DB::table('orders')->where('order_number', 'like', $orderPattern)->count(),
                'order_items' => DB::table('order_items')
                    ->join('orders', 'orders.id', '=', 'order_items.order_id')
                    ->where('orders.order_number', 'like', $orderPattern)
                    ->count(),
                'stock_movements' => DB::table('stock_movements')
                    ->where('reason', 'PERF-'.$profileName)
                    ->count(),
                'finance_records' => DB::table('invoices')
                    ->where('invoice_number', 'like', $orderPattern)
                    ->count()
                    + DB::table('payments')
                        ->where('provider_reference', 'like', self::PREFIX.strtoupper($profileName).'-%')
                        ->count(),
                'audit_events_messages' => DB::table('audit_logs')
                    ->where('event', 'perf.fixture.'.$profileName)
                    ->count(),
            ],
        ];
    }

    /**
     * @param array<string, int> $targets
     * @param array<string, int> $actual
     */
    private function assertManifest(array $targets, array $actual): void
    {
        foreach ($targets as $key => $expected) {
            if (($actual[$key] ?? null) !== $expected) {
                throw new RuntimeException(sprintf(
                    'Performance fixture count mismatch for %s: expected %d, got %s.',
                    $key,
                    $expected,
                    var_export($actual[$key] ?? null, true),
                ));
            }
        }
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function writeManifest(array $manifest): void
    {
        $path = storage_path('app/private/enterprise-performance/fixture-manifest.json');
        File::ensureDirectoryExists(dirname($path));
        File::put(
            $path,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
        );
    }

    /**
     * @param callable(int): array<string, mixed> $factory
     */
    private function insertGenerated(string $table, int $count, callable $factory): void
    {
        $rows = [];
        $chunkSize = $this->insertChunkSize();

        for ($index = 1; $index <= $count; $index++) {
            $rows[] = $factory($index);

            if (count($rows) >= $chunkSize) {
                DB::table($table)->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table($table)->insert($rows);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function insertRows(string $table, array $rows): void
    {
        foreach (array_chunk($rows, $this->insertChunkSize()) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }

    /**
     * @param callable(iterable<object>): void $callback
     */
    private function forEachOrderChunk(string $profileName, int $limit, callable $callback): void
    {
        $lastId = 0;
        $remaining = $limit;

        while ($remaining > 0) {
            $take = min($this->queryChunkSize(), $remaining);
            $orders = DB::table('orders')
                ->where('id', '>', $lastId)
                ->where('order_number', 'like', self::PREFIX.strtoupper($profileName).'-%')
                ->orderBy('id')
                ->limit($take)
                ->get([
                    'id',
                    'store_id',
                    'customer_id',
                    'b2c_customer_id',
                    'order_number',
                    'grand_total',
                    'created_at',
                    'updated_at',
                ]);

            if ($orders->isEmpty()) {
                break;
            }

            $lastId = (int) $orders->last()->id;
            $callback($orders);
            $remaining -= $orders->count();
        }
    }

    private function insertChunkSize(): int
    {
        return DB::getDriverName() === 'sqlite' ? 40 : 500;
    }

    private function queryChunkSize(): int
    {
        return DB::getDriverName() === 'sqlite' ? 40 : 500;
    }
}
