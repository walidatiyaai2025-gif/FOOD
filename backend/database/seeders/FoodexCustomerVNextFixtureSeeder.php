<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class FoodexCustomerVNextFixtureSeeder extends Seeder
{
    public const ORDER_PREFIX = 'FDX-QA-ORDER-';

    public const PRODUCT_PREFIX = 'FDX-QA-P';

    public const STORE_PREFIX = 'FDX-QA-';

    public const TRANSLATION_PREFIX = 'qa.customer-vnext.';

    public const USER_EMAIL_PATTERN = 'foodex.qa.user.%@example.test';

    public const SOURCE_SHA256 = 'dd8ad844f963ffe0317a4ba4346d61fae7ce0d02909b3d05394d9497ea2b9023';

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('The sanitized Customer VNext fixture is test/staging-only.');
        }

        $this->call(CoreReferenceSeeder::class);
        $fixture = $this->fixture();
        $this->assertFixture($fixture);

        DB::transaction(function () use ($fixture): void {
            $this->cleanup();
            $stores = $this->seedStores($fixture);
            $catalogs = $this->seedCatalogs($fixture, $stores);
            $units = $this->seedUnits();
            $categories = $this->seedCategories($fixture, $catalogs);
            $products = $this->seedProducts($fixture, $stores, $catalogs, $categories, $units);
            $identities = $this->seedIdentities($stores);
            $this->seedOrders($fixture, $stores, $products, $identities);
            $this->seedTranslations((int) $fixture['source']['counts']['translations']);
        });
    }

    public function fingerprint(): string
    {
        $fixture = $this->fixture();

        return hash('sha256', json_encode([
            'schema_version' => $fixture['schema_version'],
            'source_sha256' => $fixture['source']['sha256'],
            'counts' => $fixture['source']['counts'],
            'stores' => $fixture['stores'],
            'categories' => $fixture['categories'],
            'products' => $fixture['products'],
            'order_distribution' => $fixture['order_distribution'],
            'payment_distribution' => $fixture['payment_distribution'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string,mixed> */
    private function fixture(): array
    {
        $path = database_path('seeders/fixtures/foodex-customer-vnext-baseline.json');
        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException('FOODEX Customer VNext fixture is invalid.');
        }

        return $decoded;
    }

    /** @param array<string,mixed> $fixture */
    private function assertFixture(array $fixture): void
    {
        $counts = (array) ($fixture['source']['counts'] ?? []);

        if (($fixture['source']['sha256'] ?? null) !== self::SOURCE_SHA256) {
            throw new RuntimeException('FOODEX Customer VNext source fingerprint does not match the approved Oct-12 intake.');
        }

        $expected = [
            'products' => 39,
            'product_images' => 39,
            'product_selling_units' => 36,
            'categories' => 17,
            'stores' => 3,
            'store_products' => 39,
            'orders' => 82,
            'order_items' => 150,
            'payments' => 93,
            'translations' => 566,
            'users' => 10,
            'customers' => 5,
        ];

        if ($counts !== $expected
            || count((array) ($fixture['products'] ?? [])) !== 39
            || count((array) ($fixture['categories'] ?? [])) !== 17
            || count((array) ($fixture['stores'] ?? [])) !== 3) {
            throw new RuntimeException('FOODEX Customer VNext fixture shape has drifted from the verified source manifest.');
        }
    }

    private function cleanup(): void
    {
        $orderIds = DB::table('orders')
            ->where('order_number', 'like', self::ORDER_PREFIX.'%')
            ->pluck('id');

        if ($orderIds->isNotEmpty()) {
            DB::table('payments')->whereIn('order_id', $orderIds)->delete();
            DB::table('order_status_history')->whereIn('order_id', $orderIds)->delete();
            DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
            DB::table('orders')->whereIn('id', $orderIds)->delete();
        }

        $productIds = DB::table('products')
            ->where('sku', 'like', self::PRODUCT_PREFIX.'%')
            ->pluck('id');

        if ($productIds->isNotEmpty()) {
            if (Schema::hasTable('product_selling_units')) {
                DB::table('product_selling_units')->whereIn('product_id', $productIds)->delete();
            }
            DB::table('store_products')->whereIn('product_id', $productIds)->delete();
            DB::table('product_images')->whereIn('product_id', $productIds)->delete();
            DB::table('products')->whereIn('id', $productIds)->delete();
        }

        DB::table('translations')->where('key', 'like', self::TRANSLATION_PREFIX.'%')->delete();
        DB::table('categories')->where('slug', 'like', 'fdx-qa-c%')->delete();

        $userIds = DB::table('users')
            ->where('email', 'like', self::USER_EMAIL_PATTERN)
            ->pluck('id');
        $customerIds = DB::table('customers')
            ->whereIn('user_id', $userIds)
            ->pluck('id');

        if ($customerIds->isNotEmpty()) {
            DB::table('addresses')->whereIn('customer_id', $customerIds)->delete();
            if (Schema::hasTable('b2b_accounts')) {
                DB::table('b2b_accounts')->whereIn('customer_id', $customerIds)->delete();
            }
            if (Schema::hasTable('platform_customers')) {
                DB::table('platform_customers')->whereIn('legacy_customer_id', $customerIds)->delete();
            }
            if (Schema::hasTable('b2c_customers')) {
                DB::table('b2c_customers')->whereIn('legacy_customer_id', $customerIds)->delete();
            }
            if (Schema::hasTable('b2b_customers')) {
                DB::table('b2b_customers')->whereIn('legacy_customer_id', $customerIds)->delete();
            }
            DB::table('customers')->whereIn('id', $customerIds)->delete();
        }

        if ($userIds->isNotEmpty()) {
            DB::table('users')->whereIn('id', $userIds)->delete();
        }

        $storeIds = DB::table('stores')
            ->where('code', 'like', self::STORE_PREFIX.'%')
            ->pluck('id');

        if ($storeIds->isNotEmpty()) {
            DB::table('catalogs')->whereIn('store_id', $storeIds)->delete();
            DB::table('stores')->whereIn('id', $storeIds)->delete();
        }
    }

    /**
     * @param array<string,mixed> $fixture
     * @return array<int,int>
     */
    private function seedStores(array $fixture): array
    {
        $typeIds = DB::table('store_types')->pluck('id', 'code');
        $map = [];
        $now = CarbonImmutable::parse('2026-10-12T13:06:00Z');

        foreach ($fixture['stores'] as $store) {
            $channel = strtoupper((string) $store['channel']);
            $typeId = $typeIds->get($channel);
            if ($typeId === null) {
                throw new RuntimeException('Missing store type '.$channel.' for FOODEX QA fixture.');
            }

            $id = (int) DB::table('stores')->insertGetId([
                'store_type_id' => $typeId,
                'code' => $store['code'],
                'name' => $store['name'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $map[(int) $store['source_id']] = $id;
        }

        return $map;
    }

    /**
     * @param array<string,mixed> $fixture
     * @param array<int,int> $stores
     * @return array<int,int>
     */
    private function seedCatalogs(array $fixture, array $stores): array
    {
        $map = [];
        $now = CarbonImmutable::parse('2026-10-12T13:06:00Z');

        foreach ($fixture['stores'] as $store) {
            if (! ($store['has_catalog'] ?? false)) {
                continue;
            }

            $sourceId = (int) $store['source_id'];
            $id = (int) DB::table('catalogs')->insertGetId([
                'store_id' => $stores[$sourceId],
                'channel' => $store['channel'],
                'code' => 'qa-source-default',
                'name' => $store['name'].' QA Catalog',
                'is_active' => true,
                'is_migration_quarantine' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $map[$sourceId] = $id;
        }

        return $map;
    }

    /** @return array<string,int> */
    private function seedUnits(): array
    {
        $definitions = [
            'PC' => ['Piece', 'قطعة', 0],
            'L' => ['Liter', 'لتر', 3],
            'PACK' => ['Pack', 'عبوة', 0],
            'BOX' => ['Box', 'علبة', 0],
        ];
        $map = [];

        foreach ($definitions as $code => [$nameEn, $nameAr, $decimals]) {
            DB::table('units')->updateOrInsert(
                ['scope_key' => 'global', 'code' => $code],
                [
                    'store_id' => null,
                    'scope' => 'global',
                    'name' => $nameEn,
                    'name_ar' => $nameAr,
                    'name_en' => $nameEn,
                    'is_active' => true,
                    'decimal_places' => $decimals,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
            $map[$code] = (int) DB::table('units')
                ->where('scope_key', 'global')
                ->where('code', $code)
                ->value('id');
        }

        return $map;
    }

    /**
     * @param array<string,mixed> $fixture
     * @param array<int,int> $catalogs
     * @return array<int,int>
     */
    private function seedCategories(array $fixture, array $catalogs): array
    {
        $map = [];
        $now = CarbonImmutable::parse('2026-10-12T13:06:00Z');

        foreach ($fixture['categories'] as $index => $category) {
            $sourceId = (int) $category['source_id'];
            $storeSourceId = (int) $category['store_source_id'];
            $map[$sourceId] = (int) DB::table('categories')->insertGetId([
                'catalog_id' => $catalogs[$storeSourceId],
                'parent_id' => null,
                'name' => $category['name'],
                'slug' => 'fdx-qa-c'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                'image_path' => '/demo/products/generic.svg',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $map;
    }

    /**
     * @param array<string,mixed> $fixture
     * @param array<int,int> $stores
     * @param array<int,int> $catalogs
     * @param array<int,int> $categories
     * @param array<string,int> $units
     * @return array{by_store:array<int,list<int>>,prices:array<int,float>,selling_units:array<int,string>}
     */
    private function seedProducts(
        array $fixture,
        array $stores,
        array $catalogs,
        array $categories,
        array $units,
    ): array {
        $byStore = [];
        $prices = [];
        $sellingUnits = [];
        $now = CarbonImmutable::parse('2026-10-12T13:06:00Z');

        foreach ($fixture['products'] as $index => $product) {
            $storeSourceId = (int) $product['store_source_id'];
            $unitCode = (string) $product['unit_code'];
            $productId = (int) DB::table('products')->insertGetId([
                'catalog_id' => $catalogs[$storeSourceId],
                'category_id' => $product['category_source_id'] === null
                    ? null
                    : $categories[(int) $product['category_source_id']],
                'brand_id' => null,
                'unit_id' => $units[$unitCode],
                'sku' => $product['qa_sku'],
                'name' => $product['name'],
                'description' => 'Sanitized QA derivative of approved 2026-10-12 FOODEX business data.',
                'low_stock_threshold' => 0,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('store_products')->insert([
                'store_id' => $stores[$storeSourceId],
                'product_id' => $productId,
                'price' => $product['price'],
                'cost_price' => $product['cost_price'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('product_images')->insert([
                'product_id' => $productId,
                'path' => '/demo/products/generic.svg',
                'sort_order' => 0,
                'is_primary' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($index < 36) {
                DB::table('product_selling_units')->insert([
                    'product_id' => $productId,
                    'unit_id' => $units[$unitCode],
                    'code' => $unitCode,
                    'name' => $unitCode,
                    'conversion_factor' => 1,
                    'price' => $product['price'],
                    'sku' => $product['qa_sku'],
                    'barcode' => null,
                    'is_base' => true,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $sellingUnits[$productId] = $unitCode;
            }

            $byStore[$storeSourceId] ??= [];
            $byStore[$storeSourceId][] = $productId;
            $prices[$productId] = (float) $product['price'];
        }

        return [
            'by_store' => $byStore,
            'prices' => $prices,
            'selling_units' => $sellingUnits,
        ];
    }

    /**
     * @param array<int,int> $stores
     * @return array{
     *     legacy:list<int>,
     *     b2b:list<int>,
     *     b2c:int,
     *     addresses:array<int,int>
     * }
     */
    private function seedIdentities(array $stores): array
    {
        $userIds = [];
        for ($i = 1; $i <= 10; $i++) {
            $email = sprintf('foodex.qa.user.%02d@example.test', $i);
            $userIds[] = (int) DB::table('users')->insertGetId([
                'name' => sprintf('FOODEX QA User %02d', $i),
                'email' => $email,
                'email_verified_at' => CarbonImmutable::parse('2026-10-12T13:06:00Z'),
                'password' => Hash::make('Foodex-QA-Only-2026!'),
                'locale' => $i % 2 === 0 ? 'en' : 'ar',
                'is_active' => true,
                'created_at' => CarbonImmutable::parse('2026-10-12T13:06:00Z'),
                'updated_at' => CarbonImmutable::parse('2026-10-12T13:06:00Z'),
            ]);
        }

        $legacy = [];
        $b2b = [];
        $b2c = 0;
        $addresses = [];
        $standardTier = $this->standardTierId();

        for ($i = 1; $i <= 5; $i++) {
            $channel = $i <= 4 ? 'b2b' : 'b2c';
            $email = sprintf('foodex.qa.user.%02d@example.test', $i);
            $phone = '+9650000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT);
            $legacyId = (int) DB::table('customers')->insertGetId([
                'user_id' => $userIds[$i - 1],
                'type' => $channel,
                'name' => sprintf('FOODEX QA Customer %02d', $i),
                'phone' => $phone,
                'email' => $email,
                'created_at' => CarbonImmutable::parse('2026-10-12T13:06:00Z'),
                'updated_at' => CarbonImmutable::parse('2026-10-12T13:06:00Z'),
            ]);
            $legacy[] = $legacyId;

            if ($channel === 'b2b') {
                $domainId = (int) DB::table('b2b_customers')->insertGetId([
                    'legacy_customer_id' => $legacyId,
                    'user_id' => $userIds[$i - 1],
                    'name' => sprintf('FOODEX QA Customer %02d', $i),
                    'phone' => $phone,
                    'email' => $email,
                    'created_at' => CarbonImmutable::parse('2026-10-12T13:06:00Z'),
                    'updated_at' => CarbonImmutable::parse('2026-10-12T13:06:00Z'),
                ]);
                $b2b[] = $domainId;

                DB::table('b2b_accounts')->insert([
                    'customer_id' => $legacyId,
                    'b2b_customer_id' => $domainId,
                    'price_tier_id' => $standardTier,
                    'company_name' => sprintf('FOODEX QA Account %02d', $i),
                    'status' => 'active',
                    'tax_number' => null,
                    'credit_limit' => 10000,
                    'created_at' => CarbonImmutable::parse('2026-10-12T13:06:00Z'),
                    'updated_at' => CarbonImmutable::parse('2026-10-12T13:06:00Z'),
                ]);
            } else {
                $b2c = (int) DB::table('b2c_customers')->insertGetId([
                    'legacy_customer_id' => $legacyId,
                    'store_id' => $stores[2],
                    'user_id' => $userIds[$i - 1],
                    'name' => sprintf('FOODEX QA Customer %02d', $i),
                    'phone' => $phone,
                    'email' => $email,
                    'created_at' => CarbonImmutable::parse('2026-10-12T13:06:00Z'),
                    'updated_at' => CarbonImmutable::parse('2026-10-12T13:06:00Z'),
                ]);
            }

            $platformId = null;
            if (Schema::hasTable('platform_customers')) {
                $platformId = (int) DB::table('platform_customers')->insertGetId([
                    'user_id' => $userIds[$i - 1],
                    'legacy_customer_id' => $legacyId,
                    'name' => sprintf('FOODEX QA Customer %02d', $i),
                    'phone' => $phone,
                    'email' => $email,
                    'origin_channel' => $channel,
                    'origin_store_id' => $channel === 'b2c' ? $stores[2] : $stores[4],
                    'registration_source' => 'import',
                    'registered_at' => CarbonImmutable::parse('2026-10-12T13:06:00Z'),
                    'is_active' => true,
                    'created_at' => CarbonImmutable::parse('2026-10-12T13:06:00Z'),
                    'updated_at' => CarbonImmutable::parse('2026-10-12T13:06:00Z'),
                ]);
            }

            $address = [
                'customer_id' => $legacyId,
                'label' => 'FOODEX QA Address '.$i,
                'line1' => 'QA Block '.$i.', Street '.$i,
                'line2' => null,
                'city' => 'Kuwait City',
                'area' => 'QA Area '.$i,
                'country_code' => 'KW',
                'latitude' => null,
                'longitude' => null,
                'is_default' => true,
                'created_at' => CarbonImmutable::parse('2026-10-12T13:06:00Z'),
                'updated_at' => CarbonImmutable::parse('2026-10-12T13:06:00Z'),
            ];

            if (Schema::hasColumn('addresses', 'platform_customer_id')) {
                $address['platform_customer_id'] = $platformId;
            }
            if (Schema::hasColumn('addresses', 'commerce_channel')) {
                $address['commerce_channel'] = $channel;
            }
            if (Schema::hasColumn('addresses', 'b2b_customer_id')) {
                $address['b2b_customer_id'] = $channel === 'b2b' ? $b2b[array_key_last($b2b)] : null;
            }
            if (Schema::hasColumn('addresses', 'b2c_customer_id')) {
                $address['b2c_customer_id'] = $channel === 'b2c' ? $b2c : null;
            }
            if (Schema::hasColumn('addresses', 'recipient_name')) {
                $address['recipient_name'] = sprintf('FOODEX QA Customer %02d', $i);
                $address['delivery_phone'] = $phone;
                $address['country'] = 'Kuwait';
                $address['governorate'] = 'QA Governorate';
                $address['street'] = 'QA Street '.$i;
                $address['location_source'] = 'manual';
            }

            $addresses[$legacyId] = (int) DB::table('addresses')->insertGetId($address);
        }

        return [
            'legacy' => $legacy,
            'b2b' => $b2b,
            'b2c' => $b2c,
            'addresses' => $addresses,
        ];
    }

    private function standardTierId(): int
    {
        DB::table('b2b_price_tiers')->updateOrInsert(
            ['code' => 'STANDARD'],
            [
                'name' => 'Standard',
                'priority' => 100,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        return (int) DB::table('b2b_price_tiers')->where('code', 'STANDARD')->value('id');
    }

    /**
     * @param array<string,mixed> $fixture
     * @param array<int,int> $stores
     * @param array{by_store:array<int,list<int>>,prices:array<int,float>,selling_units:array<int,string>} $products
     * @param array{legacy:list<int>,b2b:list<int>,b2c:int,addresses:array<int,int>} $identities
     */
    private function seedOrders(array $fixture, array $stores, array $products, array $identities): void
    {
        $statuses = [
            ...array_fill(0, 11, 'confirmed'),
            'cancelled',
            'failed',
            ...array_fill(0, 28, 'pending'),
            ...array_fill(0, 27, 'delivered'),
            ...array_fill(0, 5, 'out_for_delivery'),
            ...array_fill(0, 3, 'preparing'),
            ...array_fill(0, 2, 'assigned'),
            'accepted',
            ...array_fill(0, 3, 'pending'),
        ];

        $base = CarbonImmutable::parse('2026-09-28T08:45:20Z');
        $orders = [];
        $codOrderIds = [];
        $accountCreditOrderId = null;

        for ($index = 0; $index < 82; $index++) {
            $ordinal = $index + 1;
            $channel = $ordinal <= 79 ? 'b2b' : 'b2c';
            $storeSourceId = $channel === 'b2b' ? 4 : 2;
            $storeId = $stores[$storeSourceId];
            $availableProducts = $products['by_store'][$storeSourceId];

            if ($channel === 'b2b') {
                $customerOffset = $index % count($identities['b2b']);
                $legacyCustomerId = $identities['legacy'][$customerOffset];
                $domainId = $identities['b2b'][$customerOffset];
            } else {
                $legacyCustomerId = $identities['legacy'][4];
                $domainId = $identities['b2c'];
            }

            $itemCount = $ordinal <= 68 ? 2 : 1;
            $items = [];
            $subtotal = 0.0;

            for ($itemIndex = 0; $itemIndex < $itemCount; $itemIndex++) {
                $productId = $availableProducts[($index + $itemIndex) % count($availableProducts)];
                $quantity = (float) (($ordinal + $itemIndex) % 3 + 1);
                $unitPrice = $products['prices'][$productId];
                $lineTotal = round($quantity * $unitPrice, 3);
                $subtotal += $lineTotal;
                $items[] = [$productId, $quantity, $unitPrice, $lineTotal];
            }

            $paymentMethod = $ordinal === 79 ? 'account_credit' : 'cash_on_delivery';
            $createdAt = $base->addHours($index);
            $order = [
                'store_id' => $storeId,
                'customer_id' => $legacyCustomerId,
                'address_id' => $identities['addresses'][$legacyCustomerId],
                'order_number' => self::ORDER_PREFIX.str_pad((string) $ordinal, 3, '0', STR_PAD_LEFT),
                'channel' => $channel,
                'status' => $statuses[$index],
                'currency' => 'EGP',
                'subtotal' => $subtotal,
                'discount_total' => 0,
                'delivery_total' => 0,
                'grand_total' => $subtotal,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];

            if (Schema::hasColumn('orders', 'b2b_customer_id')) {
                $order['b2b_customer_id'] = $channel === 'b2b' ? $domainId : null;
                $order['b2c_customer_id'] = $channel === 'b2c' ? $domainId : null;
            }
            if (Schema::hasColumn('orders', 'payment_method')) {
                $order['payment_method'] = $paymentMethod;
            }
            if (Schema::hasColumn('orders', 'tax_total')) {
                $order['tax_total'] = 0;
            }

            $orderId = (int) DB::table('orders')->insertGetId($order);
            $orders[] = [$orderId, $subtotal, $createdAt, $paymentMethod];

            if ($paymentMethod === 'cash_on_delivery') {
                $codOrderIds[] = $orderId;
            } else {
                $accountCreditOrderId = $orderId;
            }

            foreach ($items as [$productId, $quantity, $unitPrice, $lineTotal]) {
                $item = [
                    'order_id' => $orderId,
                    'product_id' => $productId,
                    'sku_snapshot' => DB::table('products')->where('id', $productId)->value('sku'),
                    'name_snapshot' => DB::table('products')->where('id', $productId)->value('name'),
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];

                if (Schema::hasColumn('order_items', 'selling_unit_code_snapshot')) {
                    $unitCode = $products['selling_units'][$productId] ?? null;
                    $item['selling_unit_code_snapshot'] = $unitCode;
                    $item['selling_unit_name_snapshot'] = $unitCode;
                    $item['selling_unit_quantity'] = $unitCode === null ? null : $quantity;
                    $item['base_quantity'] = $unitCode === null ? null : $quantity;
                    $item['conversion_factor_snapshot'] = $unitCode === null ? null : 1;
                    $item['selling_unit_sku_snapshot'] = $unitCode === null
                        ? null
                        : DB::table('products')->where('id', $productId)->value('sku');
                    $item['selling_unit_barcode_snapshot'] = null;
                }

                DB::table('order_items')->insert($item);
            }

            DB::table('order_status_history')->insert([
                'order_id' => $orderId,
                'user_id' => null,
                'from_status' => null,
                'to_status' => $statuses[$index],
                'note' => 'Sanitized FOODEX Customer VNext QA baseline',
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }

        $paidCod = array_flip(array_slice($codOrderIds, 0, 18));
        foreach ($orders as [$orderId, $amount, $createdAt, $paymentMethod]) {
            $provider = $paymentMethod === 'account_credit' ? 'account_credit' : 'cash_on_delivery';
            $status = $provider === 'account_credit'
                ? 'pending'
                : (isset($paidCod[$orderId]) ? 'paid' : 'pending');

            DB::table('payments')->insert([
                'order_id' => $orderId,
                'invoice_id' => null,
                'provider' => $provider,
                'provider_reference' => null,
                'status' => $status,
                'amount' => $amount,
                'currency' => 'EGP',
                'metadata' => json_encode([
                    'fixture' => 'foodex-customer-vnext',
                    'sanitized' => true,
                ], JSON_THROW_ON_ERROR),
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }

        foreach (array_slice($codOrderIds, 0, 11) as $offset => $orderId) {
            $order = DB::table('orders')->where('id', $orderId)->first(['grand_total', 'created_at']);
            DB::table('payments')->insert([
                'order_id' => $orderId,
                'invoice_id' => null,
                'provider' => 'field_collection',
                'provider_reference' => null,
                'status' => 'paid',
                'amount' => $order->grand_total,
                'currency' => 'EGP',
                'metadata' => json_encode([
                    'fixture' => 'foodex-customer-vnext',
                    'sanitized' => true,
                    'collection_sequence' => $offset + 1,
                ], JSON_THROW_ON_ERROR),
                'created_at' => $order->created_at,
                'updated_at' => $order->created_at,
            ]);
        }
    }

    private function seedTranslations(int $count): void
    {
        $rows = [];
        $now = CarbonImmutable::parse('2026-10-12T13:06:00Z');

        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'key' => self::TRANSLATION_PREFIX.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'surface' => 'qa.fixture',
                'ar' => 'نص اختبار فوديكس '.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'en' => 'FOODEX QA fixture text '.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('translations')->insert($chunk);
        }
    }
}
