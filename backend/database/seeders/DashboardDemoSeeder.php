<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class DashboardDemoSeeder extends Seeder
{
    private const STORE_CODE = 'FOODEX-DEMO-B2C';

    private const ORDER_PREFIX = 'FOODEX-DEMO-';

    private const TARGET_ACTIVE_USERS = 1248;

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('DashboardDemoSeeder is forbidden in production.');
        }

        $this->call(CoreReferenceSeeder::class);

        if (DB::table('stores')->where('code', self::STORE_CODE)->exists()) {
            $this->command?->warn('FOODEX dashboard demo data already exists; leaving the deterministic fixture unchanged.');

            return;
        }

        DB::transaction(function (): void {
            $anchor = CarbonImmutable::now('Asia/Kuwait')->startOfDay();
            $now = $anchor->setTime(15, 0)->utc();
            $timestamps = ['created_at' => $now, 'updated_at' => $now];

            $storeTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
            $storeId = (int) DB::table('stores')->insertGetId([
                'store_type_id' => $storeTypeId,
                'code' => self::STORE_CODE,
                'name' => 'FOODEX Premium Demo Store',
                'is_active' => true,
                ...$timestamps,
            ]);
            $warehouseId = (int) DB::table('warehouses')->insertGetId([
                'store_id' => $storeId,
                'code' => 'FOODEX-DEMO-WH',
                'name' => 'FOODEX Demo Warehouse',
                'is_active' => true,
                ...$timestamps,
            ]);

            $adminId = $this->seedAdmin($storeId, $now);
            $driverIds = $this->seedDrivers($now);
            $this->seedPresenceUsers($adminId, $driverIds, $now);

            $categoryId = (int) DB::table('categories')->insertGetId([
                'name' => 'FOODEX Demo Grocery',
                'slug' => 'foodex-demo-grocery',
                'is_active' => true,
                ...$timestamps,
            ]);
            $unitId = (int) DB::table('units')->insertGetId([
                'code' => 'DEMO-PC',
                'name' => 'Piece',
                'decimal_places' => 0,
                ...$timestamps,
            ]);

            $products = [
                ['sku' => 'DEMO-OLIVE', 'name' => 'زيت زيتون عضوي', 'price' => 18.500, 'stock' => 5, 'image' => '/demo/products/olive-oil.svg'],
                ['sku' => 'DEMO-RICE', 'name' => 'أرز بسمتي فاخر', 'price' => 7.250, 'stock' => 8, 'image' => '/demo/products/rice.svg'],
                ['sku' => 'DEMO-TOMATO', 'name' => 'صلصة الطماطم الطبيعية', 'price' => 2.750, 'stock' => 10, 'image' => '/demo/products/tomato.svg'],
                ['sku' => 'DEMO-PASTA', 'name' => 'مكرونة إيطالية', 'price' => 1.950, 'stock' => 12, 'image' => '/demo/products/pasta.svg'],
                ['sku' => 'DEMO-COFFEE', 'name' => 'قهوة عربية فاخرة', 'price' => 4.900, 'stock' => 120, 'image' => '/demo/products/generic.svg'],
                ['sku' => 'DEMO-DATES', 'name' => 'تمر كويتي ممتاز', 'price' => 6.100, 'stock' => 140, 'image' => '/demo/products/generic.svg'],
                ['sku' => 'DEMO-WATER', 'name' => 'مياه معدنية', 'price' => 1.200, 'stock' => 250, 'image' => '/demo/products/generic.svg'],
                ['sku' => 'DEMO-JUICE', 'name' => 'عصير برتقال طبيعي', 'price' => 3.300, 'stock' => 95, 'image' => '/demo/products/generic.svg'],
            ];

            $productIds = [];
            foreach ($products as $index => $product) {
                $productId = (int) DB::table('products')->insertGetId([
                    'category_id' => $categoryId,
                    'unit_id' => $unitId,
                    'sku' => $product['sku'],
                    'name' => $product['name'],
                    'description' => 'Deterministic PH-05 visual QA product.',
                    'is_active' => true,
                    ...$timestamps,
                ]);
                $productIds[] = $productId;

                DB::table('product_images')->insert([
                    'product_id' => $productId,
                    'path' => $product['image'],
                    'sort_order' => 0,
                    'is_primary' => true,
                    ...$timestamps,
                ]);
                DB::table('store_products')->insert([
                    'store_id' => $storeId,
                    'product_id' => $productId,
                    'price' => $product['price'],
                    'is_active' => true,
                    ...$timestamps,
                ]);
                DB::table('inventories')->insert([
                    'warehouse_id' => $warehouseId,
                    'product_id' => $productId,
                    'quantity' => $product['stock'],
                    'reserved_quantity' => 0,
                    ...$timestamps,
                ]);
            }

            $customers = $this->seedCustomers($now);
            $todayOrders = $this->todayOrders($anchor, $storeId, $customers);
            $historyOrders = $this->historyOrders($anchor, $storeId, $customers);

            $allOrders = [...$todayOrders, ...$historyOrders];
            $dbOrders = array_map(function (array $order): array {
                unset($order['_quantity']);

                return $order;
            }, $allOrders);
            foreach (array_chunk($dbOrders, 200) as $chunk) {
                DB::table('orders')->insert($chunk);
            }

            $orderIds = DB::table('orders')
                ->where('order_number', 'like', self::ORDER_PREFIX.'%')
                ->pluck('id', 'order_number');

            $items = [];
            $payments = [];
            foreach ($allOrders as $index => $order) {
                $orderId = (int) $orderIds->get($order['order_number']);
                $productId = $productIds[$index % count($productIds)];
                $quantity = (float) ($order['_quantity'] ?? 1);
                $unitPrice = $quantity > 0 ? round(((float) $order['grand_total']) / $quantity, 3) : 0;
                $items[] = [
                    'order_id' => $orderId,
                    'product_id' => $productId,
                    'sku_snapshot' => $products[$index % count($products)]['sku'],
                    'name_snapshot' => $products[$index % count($products)]['name'],
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => (float) $order['grand_total'],
                    'created_at' => $order['created_at'],
                    'updated_at' => $order['updated_at'],
                ];

                if (! in_array($order['status'], ['cancelled', 'refunded'], true)) {
                    $payments[] = [
                        'order_id' => $orderId,
                        'invoice_id' => null,
                        'provider' => $index % 4 === 0 ? 'Apple Pay' : 'KNET',
                        'provider_reference' => 'DEMO-PAY-'.$order['order_number'],
                        'status' => 'paid',
                        'amount' => (float) $order['grand_total'],
                        'currency' => 'KWD',
                        'metadata' => json_encode(['demo' => true], JSON_THROW_ON_ERROR),
                        'created_at' => $order['created_at'],
                        'updated_at' => $order['updated_at'],
                    ];
                }
            }

            foreach (array_chunk($items, 250) as $chunk) {
                DB::table('order_items')->insert($chunk);
            }
            foreach (array_chunk($payments, 250) as $chunk) {
                DB::table('payments')->insert($chunk);
            }

            $this->seedDeliveryAssignments($orderIds, $driverIds, $now);
            $this->seedNotifications($adminId, $now);
            $this->seedMobileSettings($now);
        });
    }

    private function seedAdmin(int $storeId, CarbonImmutable $now): int
    {
        $presence = Schema::hasColumn('users', 'last_seen_at') ? ['last_seen_at' => $now] : [];
        $adminId = (int) DB::table('users')->insertGetId([
            'name' => 'أحمد السعيد',
            'email' => 'admin.demo@foodex.test',
            'email_verified_at' => $now,
            'password' => Hash::make('Demo123!'),
            'locale' => 'ar',
            'is_active' => true,
            ...$presence,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $roleId = (int) DB::table('roles')->where('code', 'B2C_STORE_ADMIN')->value('id');
        DB::table('role_user')->insert(['role_id' => $roleId, 'user_id' => $adminId]);
        DB::table('user_store_roles')->insert([
            'user_id' => $adminId,
            'store_id' => $storeId,
            'role_id' => $roleId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $adminId;
    }

    /** @return list<int> */
    private function seedDrivers(CarbonImmutable $now): array
    {
        $roleId = (int) DB::table('roles')->where('code', 'B2C_DRIVER')->value('id');
        $presence = Schema::hasColumn('users', 'last_seen_at') ? ['last_seen_at' => $now] : [];
        $ids = [];

        foreach (['ناصر', 'فهد', 'سالم'] as $index => $name) {
            $userId = (int) DB::table('users')->insertGetId([
                'name' => $name.' · سائق FOODEX',
                'email' => 'driver'.($index + 1).'.demo@foodex.test',
                'email_verified_at' => $now,
                'password' => Hash::make('Demo123!'),
                'locale' => 'ar',
                'is_active' => true,
                ...$presence,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('role_user')->insert(['role_id' => $roleId, 'user_id' => $userId]);
            $ids[] = (int) DB::table('drivers')->insertGetId([
                'user_id' => $userId,
                'driver_type' => 'b2c',
                'is_available' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $ids;
    }

    /** @param list<int> $driverIds */
    private function seedPresenceUsers(int $adminId, array $driverIds, CarbonImmutable $now): void
    {
        $existing = 1 + count($driverIds);
        $fillers = max(0, self::TARGET_ACTIVE_USERS - $existing);
        $presence = Schema::hasColumn('users', 'last_seen_at') ? ['last_seen_at' => $now] : [];
        $password = Hash::make('Demo123!');
        $rows = [];

        for ($i = 1; $i <= $fillers; $i++) {
            $rows[] = [
                'name' => 'Demo Active User '.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'email' => 'active'.str_pad((string) $i, 4, '0', STR_PAD_LEFT).'@foodex.test',
                'email_verified_at' => $now,
                'password' => $password,
                'locale' => $i % 2 === 0 ? 'en' : 'ar',
                'is_active' => true,
                ...$presence,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) === 250) {
                DB::table('users')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('users')->insert($rows);
        }
    }

    /** @return list<int> */
    private function seedCustomers(CarbonImmutable $now): array
    {
        $names = [
            'سارة محمد', 'خالد عبدالله', 'ريم الشمري', 'ماجد العتيبي', 'نورة أحمد',
            'يوسف خالد', 'فاطمة علي', 'عبدالله سالم', 'مريم حسن', 'أحمد فهد',
            'دانة يوسف', 'محمد ناصر', 'هدى إبراهيم', 'سلمان بدر', 'لولوة عمر',
            'علي جاسم', 'نور خالد', 'بدر سعود', 'شيخة راشد', 'حمد منصور',
        ];
        $ids = [];

        foreach ($names as $index => $name) {
            $ids[] = (int) DB::table('customers')->insertGetId([
                'type' => 'b2c',
                'name' => $name,
                'phone' => '+965555'.str_pad((string) $index, 5, '0', STR_PAD_LEFT),
                'email' => 'customer'.($index + 1).'@foodex.test',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $ids;
    }

    /**
     * @param list<int> $customers
     * @return list<array<string, mixed>>
     */
    private function todayOrders(CarbonImmutable $anchor, int $storeId, array $customers): array
    {
        $statuses = [
            ...array_fill(0, 149, 'processing'),
            ...array_fill(0, 223, 'out_for_delivery'),
            ...array_fill(0, 138, 'delivered'),
            ...array_fill(0, 22, 'cancelled'),
        ];

        $recent = [
            ['number' => 'FOODEX-DEMO-1245', 'customer' => 0, 'status' => 'delivered', 'amount' => 120.000, 'qty' => 3, 'minutes' => 5],
            ['number' => 'FOODEX-DEMO-1244', 'customer' => 1, 'status' => 'out_for_delivery', 'amount' => 250.000, 'qty' => 5, 'minutes' => 12],
            ['number' => 'FOODEX-DEMO-1243', 'customer' => 2, 'status' => 'processing', 'amount' => 85.000, 'qty' => 2, 'minutes' => 28],
            ['number' => 'FOODEX-DEMO-1242', 'customer' => 3, 'status' => 'delivered', 'amount' => 190.000, 'qty' => 4, 'minutes' => 60],
            ['number' => 'FOODEX-DEMO-1241', 'customer' => 4, 'status' => 'out_for_delivery', 'amount' => 75.000, 'qty' => 1, 'minutes' => 120],
        ];

        foreach ($recent as $row) {
            $position = array_search($row['status'], $statuses, true);
            if ($position !== false) {
                unset($statuses[$position]);
            }
        }
        $statuses = array_values($statuses);

        $orders = [];
        foreach ($recent as $row) {
            $created = $anchor->setTime(15, 0)->subMinutes($row['minutes'])->utc();
            $orders[] = $this->orderRow($storeId, $customers[$row['customer']], $row['number'], $row['status'], $row['amount'], $row['qty'], $created);
        }

        $recognizedRemaining = count(array_filter($statuses, fn (string $status): bool => $status !== 'cancelled'));
        $recognizedIndex = 0;
        $quantityExtra = 1892 - array_sum(array_column($recent, 'qty')) - ($recognizedRemaining * 3);
        $revenueRemaining = 48532 - array_sum(array_column($recent, 'amount')) - ($recognizedRemaining * 94);

        foreach ($statuses as $index => $status) {
            $recognized = $status !== 'cancelled';
            $quantity = $recognized ? 3 + ($recognizedIndex < $quantityExtra ? 1 : 0) : 1;
            $amount = $recognized ? 94.000 : 40.000;

            if ($recognized && $recognizedIndex === 0) {
                $amount += $revenueRemaining;
            }

            $created = $anchor->setTime(11, 30)->subSeconds($index * 20)->utc();
            $orders[] = $this->orderRow(
                $storeId,
                $customers[$index % count($customers)],
                self::ORDER_PREFIX.(2000 + $index),
                $status,
                $amount,
                $quantity,
                $created,
            );

            if ($recognized) {
                $recognizedIndex++;
            }
        }

        return $orders;
    }

    /**
     * @param list<int> $customers
     * @return list<array<string, mixed>>
     */
    private function historyOrders(CarbonImmutable $anchor, int $storeId, array $customers): array
    {
        $dailyCounts = [120, 180, 210, 170, 230, 280];
        $rows = [];
        $sequence = 10000;

        foreach ($dailyCounts as $index => $count) {
            $day = $anchor->subDays(6 - $index);
            for ($i = 0; $i < $count; $i++) {
                $amount = 55 + (($i * 7 + $index * 11) % 95);
                $rows[] = $this->orderRow(
                    $storeId,
                    $customers[($i + $index) % count($customers)],
                    self::ORDER_PREFIX.'H'.$sequence++,
                    $i % 9 === 0 ? 'out_for_delivery' : 'delivered',
                    (float) $amount,
                    (float) (1 + ($i % 5)),
                    $day->setTime(8 + ($i % 12), ($i * 7) % 60)->utc(),
                );
            }
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function orderRow(
        int $storeId,
        int $customerId,
        string $number,
        string $status,
        float $amount,
        float $quantity,
        CarbonImmutable $created,
    ): array {
        return [
            'store_id' => $storeId,
            'customer_id' => $customerId,
            'address_id' => null,
            'order_number' => $number,
            'channel' => 'b2c',
            'status' => $status,
            'currency' => 'KWD',
            'subtotal' => $amount,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => $amount,
            'created_at' => $created,
            'updated_at' => $created,
            '_quantity' => $quantity,
        ];
    }

    /**
     * @param \Illuminate\Support\Collection<string, int> $orderIds
     * @param list<int> $driverIds
     */
    private function seedDeliveryAssignments($orderIds, array $driverIds, CarbonImmutable $now): void
    {
        foreach (['FOODEX-DEMO-1244', 'FOODEX-DEMO-1241'] as $index => $number) {
            $orderId = $orderIds->get($number);
            if ($orderId === null) {
                continue;
            }

            DB::table('driver_assignments')->insert([
                'driver_id' => $driverIds[$index % count($driverIds)],
                'order_id' => $orderId,
                'assignment_type' => 'b2c',
                'status' => 'assigned',
                'assigned_at' => $now->subMinutes(20 + ($index * 30)),
                'completed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function seedNotifications(int $adminId, CarbonImmutable $now): void
    {
        foreach (['طلب جديد بانتظار المراجعة', 'مخزون منخفض لمنتج تجريبي', 'تمت مزامنة تطبيق FOODEX'] as $index => $title) {
            DB::table('notifications')->insert([
                'user_id' => $adminId,
                'channel' => 'in_app',
                'type' => 'demo',
                'title' => $title,
                'body' => 'بيانات PH-05 التجريبية للمعاينة البصرية.',
                'data' => json_encode(['demo' => true], JSON_THROW_ON_ERROR),
                'read_at' => null,
                'created_at' => $now->subMinutes($index * 4),
                'updated_at' => $now->subMinutes($index * 4),
            ]);
        }
    }

    private function seedMobileSettings(CarbonImmutable $now): void
    {
        foreach ([
            ['app' => 'customer', 'display_name' => 'FOODEX Customer', 'android' => 'https://play.google.com/store/apps/details?id=com.foodex.customer', 'ios' => 'https://apps.apple.com/app/id000000001'],
            ['app' => 'driver', 'display_name' => 'FOODEX Driver', 'android' => 'https://play.google.com/store/apps/details?id=com.foodex.driver', 'ios' => 'https://apps.apple.com/app/id000000002'],
        ] as $app) {
            DB::table('mobile_app_settings')->insert([
                'app' => $app['app'],
                'environment' => 'production',
                'display_name' => $app['display_name'],
                'android_package_id' => 'com.foodex.'.$app['app'],
                'ios_bundle_id' => 'com.foodex.'.$app['app'],
                'published_version' => '1.0.0',
                'published_build' => '100',
                'minimum_supported_version' => '1.0.0',
                'recommended_version' => '1.0.0',
                'force_update' => false,
                'maintenance_mode' => false,
                'google_play_url' => $app['android'],
                'app_store_url' => $app['ios'],
                'privacy_url' => 'https://foodex.test/privacy',
                'terms_url' => 'https://foodex.test/terms',
                'support_url' => 'https://foodex.test/support',
                'release_notes_ar' => 'نسخة PH-05 التجريبية',
                'release_notes_en' => 'PH-05 demo release',
                'deep_link_config' => json_encode(['scheme' => 'foodex'], JSON_THROW_ON_ERROR),
                'store_readiness' => json_encode(['demo' => true], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
