<?php

namespace Database\Seeders;

use App\Services\DemoDataManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class ProductDemoSeeder extends Seeder
{
    private const STORE_COUNT = 10;
    private const CUSTOMERS_PER_STORE = 5;
    private const PRODUCTS_COUNT = 20;
    private const ORDERS_PER_STORE = 40;

    /** @var list<string> */
    private array $areas = ['السالمية', 'حولي', 'الفروانية', 'الجهراء', 'الفحيحيل', 'مشرف', 'بيان', 'صباح السالم', 'الرقعي', 'الشويخ'];

    /** @var list<array{name:string,price:float,stock:int}> */
    private array $products = [
        ['name' => 'زيت زيتون بكر ممتاز', 'price' => 4.950, 'stock' => 38],
        ['name' => 'أرز بسمتي فاخر 5 كجم', 'price' => 3.750, 'stock' => 64],
        ['name' => 'قهوة عربية محمصة', 'price' => 2.850, 'stock' => 45],
        ['name' => 'تمر خلاص فاخر', 'price' => 2.250, 'stock' => 52],
        ['name' => 'مكرونة إيطالية', 'price' => 0.650, 'stock' => 90],
        ['name' => 'صلصة طماطم طبيعية', 'price' => 0.550, 'stock' => 75],
        ['name' => 'مياه معدنية 12 عبوة', 'price' => 1.350, 'stock' => 120],
        ['name' => 'عصير برتقال طبيعي', 'price' => 1.100, 'stock' => 49],
        ['name' => 'حليب كامل الدسم', 'price' => 0.650, 'stock' => 68],
        ['name' => 'لبن زبادي يوناني', 'price' => 0.850, 'stock' => 36],
        ['name' => 'بيض طازج 30 حبة', 'price' => 1.650, 'stock' => 44],
        ['name' => 'خبز عربي طازج', 'price' => 0.250, 'stock' => 80],
        ['name' => 'تونة قطع خفيفة', 'price' => 0.790, 'stock' => 57],
        ['name' => 'سكر أبيض 2 كجم', 'price' => 0.890, 'stock' => 61],
        ['name' => 'شاي أسود فاخر', 'price' => 1.450, 'stock' => 42],
        ['name' => 'مناديل مطبخ', 'price' => 1.250, 'stock' => 55],
        ['name' => 'سائل غسيل الأطباق', 'price' => 1.100, 'stock' => 47],
        ['name' => 'مسحوق غسيل مركز', 'price' => 3.250, 'stock' => 33],
        ['name' => 'شوكولاتة بالحليب', 'price' => 0.600, 'stock' => 72],
        ['name' => 'بسكويت شوفان', 'price' => 0.750, 'stock' => 66],
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('ProductDemoSeeder is forbidden in production.');
        }

        $this->call(CoreReferenceSeeder::class);
        app(DemoDataManager::class)->clear();

        DB::transaction(function (): void {
            $now = CarbonImmutable::now('Asia/Kuwait')->startOfMinute()->utc();
            $timestamps = ['created_at' => $now, 'updated_at' => $now];
            $storeTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
            $storeAdminRoleId = (int) DB::table('roles')->where('code', 'B2C_STORE_ADMIN')->value('id');
            $driverRoleId = (int) DB::table('roles')->where('code', 'B2C_DRIVER')->value('id');
            $unitId = (int) DB::table('units')->insertGetId([
                'code' => DemoDataManager::UNIT_CODE,
                'name' => 'Piece',
                'decimal_places' => 0,
                ...$timestamps,
            ]);

            $categoryIds = $this->seedCategories($timestamps);
            $productIds = $this->seedProducts($categoryIds, $unitId, $timestamps);

            for ($storeIndex = 1; $storeIndex <= self::STORE_COUNT; $storeIndex++) {
                $storeCode = DemoDataManager::STORE_PREFIX.str_pad((string) $storeIndex, 2, '0', STR_PAD_LEFT);
                $storeId = (int) DB::table('stores')->insertGetId([
                    'store_type_id' => $storeTypeId,
                    'code' => $storeCode,
                    'name' => 'FOODEX '.$this->areas[$storeIndex - 1].' Demo Market',
                    'is_active' => true,
                    ...$timestamps,
                ]);
                $warehouseId = (int) DB::table('warehouses')->insertGetId([
                    'store_id' => $storeId,
                    'code' => 'FOODEX-DEMO-WH-'.str_pad((string) $storeIndex, 2, '0', STR_PAD_LEFT),
                    'name' => 'FOODEX Demo Warehouse '.$storeIndex,
                    'is_active' => true,
                    ...$timestamps,
                ]);

                $adminId = $this->seedStoreAdmin($storeIndex, $storeId, $storeAdminRoleId, $now);
                $driverId = $this->seedDriver($storeIndex, $driverRoleId, $now);
                $customerData = $this->seedCustomers($storeIndex, $now);
                $priceMap = $this->seedStoreCatalog($storeIndex, $storeId, $warehouseId, $productIds, $now);
                $assignedOrderId = $this->seedOrders($storeIndex, $storeId, $customerData, $productIds, $priceMap, $now);

                DB::table('driver_assignments')->insert([
                    'driver_id' => $driverId,
                    'order_id' => $assignedOrderId,
                    'assignment_type' => 'b2c',
                    'status' => 'assigned',
                    'assigned_at' => $now->subMinutes($storeIndex * 3),
                    'completed_at' => null,
                    ...$timestamps,
                ]);

                $promotionId = (int) DB::table('promotions')->insertGetId([
                    'store_id' => $storeId,
                    'name' => 'FOODEX Demo Promotion '.str_pad((string) $storeIndex, 2, '0', STR_PAD_LEFT),
                    'type' => 'percentage',
                    'value' => 5 + $storeIndex,
                    'starts_at' => $now->subDays(3),
                    'ends_at' => $now->addDays(30),
                    'is_active' => true,
                    ...$timestamps,
                ]);
                DB::table('coupons')->insert([
                    'promotion_id' => $promotionId,
                    'code' => 'DEMO'.str_pad((string) $storeIndex, 2, '0', STR_PAD_LEFT),
                    'usage_limit' => 100,
                    'used_count' => $storeIndex,
                    ...$timestamps,
                ]);
                DB::table('banners')->insert([
                    'store_id' => $storeId,
                    'title' => 'FOODEX Demo Banner '.str_pad((string) $storeIndex, 2, '0', STR_PAD_LEFT),
                    'image_path' => '/demo/banners/store-'.$storeIndex.'.svg',
                    'target_url' => '/offers',
                    'sort_order' => $storeIndex,
                    'is_active' => true,
                    ...$timestamps,
                ]);
                DB::table('notifications')->insert([
                    'user_id' => $adminId,
                    'channel' => 'in_app',
                    'type' => 'demo_seed',
                    'title' => 'FOODEX Demo Store '.$storeIndex,
                    'body' => 'Populated demo data is ready for products, customers, orders, promotions and delivery review.',
                    'data' => json_encode(['demo' => true, 'store_code' => $storeCode], JSON_THROW_ON_ERROR),
                    'read_at' => null,
                    ...$timestamps,
                ]);
            }
        });
    }

    /** @param array<string, mixed> $timestamps
     *  @return list<int>
     */
    private function seedCategories(array $timestamps): array
    {
        $ids = [];
        foreach (['بقالة', 'مشروبات', 'منتجات طازجة', 'منزل وعناية'] as $index => $name) {
            $ids[] = (int) DB::table('categories')->insertGetId([
                'name' => $name,
                'slug' => DemoDataManager::CATEGORY_PREFIX.($index + 1),
                'is_active' => true,
                ...$timestamps,
            ]);
        }

        return $ids;
    }

    /** @param list<int> $categoryIds
     *  @param array<string, mixed> $timestamps
     *  @return list<int>
     */
    private function seedProducts(array $categoryIds, int $unitId, array $timestamps): array
    {
        $ids = [];
        foreach ($this->products as $index => $product) {
            $number = $index + 1;
            $productId = (int) DB::table('products')->insertGetId([
                'category_id' => $categoryIds[$index % count($categoryIds)],
                'unit_id' => $unitId,
                'sku' => DemoDataManager::PRODUCT_PREFIX.str_pad((string) $number, 3, '0', STR_PAD_LEFT),
                'name' => $product['name'],
                'description' => 'FOODEX populated B2C demo product '.$number.'.',
                'is_active' => true,
                ...$timestamps,
            ]);
            $ids[] = $productId;
            DB::table('product_images')->insert([
                'product_id' => $productId,
                'path' => '/demo/products/product-'.$number.'.svg',
                'sort_order' => 0,
                'is_primary' => true,
                ...$timestamps,
            ]);
        }

        return $ids;
    }

    private function seedStoreAdmin(int $storeIndex, int $storeId, int $roleId, CarbonImmutable $now): int
    {
        $userId = (int) DB::table('users')->insertGetId([
            'name' => 'FOODEX Demo Admin '.$storeIndex,
            'email' => 'store'.str_pad((string) $storeIndex, 2, '0', STR_PAD_LEFT).'.admin'.DemoDataManager::CUSTOMER_EMAIL_SUFFIX,
            'email_verified_at' => $now,
            'password' => Hash::make('Demo123!'),
            'locale' => $storeIndex % 2 === 0 ? 'en' : 'ar',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('role_user')->insert(['role_id' => $roleId, 'user_id' => $userId]);
        DB::table('user_store_roles')->insert([
            'user_id' => $userId,
            'store_id' => $storeId,
            'role_id' => $roleId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $userId;
    }

    private function seedDriver(int $storeIndex, int $roleId, CarbonImmutable $now): int
    {
        $userId = (int) DB::table('users')->insertGetId([
            'name' => 'FOODEX Demo Driver '.$storeIndex,
            'email' => 'driver'.str_pad((string) $storeIndex, 2, '0', STR_PAD_LEFT).DemoDataManager::CUSTOMER_EMAIL_SUFFIX,
            'email_verified_at' => $now,
            'password' => Hash::make('Demo123!'),
            'locale' => $storeIndex % 2 === 0 ? 'en' : 'ar',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('role_user')->insert(['role_id' => $roleId, 'user_id' => $userId]);

        return (int) DB::table('drivers')->insertGetId([
            'user_id' => $userId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @return list<array{id:int,address_id:int}> */
    private function seedCustomers(int $storeIndex, CarbonImmutable $now): array
    {
        $rows = [];
        for ($customerIndex = 1; $customerIndex <= self::CUSTOMERS_PER_STORE; $customerIndex++) {
            $customerId = (int) DB::table('customers')->insertGetId([
                'type' => 'b2c',
                'name' => 'عميل تجريبي '.$storeIndex.'-'.$customerIndex,
                'phone' => '+96555'.str_pad((string) (($storeIndex * 1000) + $customerIndex), 6, '0', STR_PAD_LEFT),
                'email' => 'customer'.str_pad((string) $storeIndex, 2, '0', STR_PAD_LEFT).'-'.$customerIndex.DemoDataManager::CUSTOMER_EMAIL_SUFFIX,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $addressId = (int) DB::table('addresses')->insertGetId([
                'customer_id' => $customerId,
                'label' => 'المنزل',
                'line1' => 'قطعة '.$customerIndex.', شارع '.($storeIndex + 10),
                'line2' => null,
                'city' => 'Kuwait City',
                'area' => $this->areas[$storeIndex - 1],
                'country_code' => 'KW',
                'latitude' => 29.30 + ($storeIndex / 1000),
                'longitude' => 47.90 + ($customerIndex / 1000),
                'is_default' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $rows[] = ['id' => $customerId, 'address_id' => $addressId];
        }

        return $rows;
    }

    /** @param list<int> $productIds
     *  @return array<int, float>
     */
    private function seedStoreCatalog(int $storeIndex, int $storeId, int $warehouseId, array $productIds, CarbonImmutable $now): array
    {
        $prices = [];
        foreach ($productIds as $index => $productId) {
            $price = round($this->products[$index]['price'] + (($storeIndex - 1) * 0.010), 3);
            $prices[$productId] = $price;
            DB::table('store_products')->insert([
                'store_id' => $storeId,
                'product_id' => $productId,
                'price' => $price,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $inventoryId = (int) DB::table('inventories')->insertGetId([
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'quantity' => $this->products[$index]['stock'] + ($storeIndex * 3),
                'reserved_quantity' => $index % 5,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('stock_movements')->insert([
                'inventory_id' => $inventoryId,
                'user_id' => null,
                'type' => 'seed_opening_balance',
                'quantity' => $this->products[$index]['stock'] + ($storeIndex * 3),
                'reference_type' => 'demo_seed',
                'reference_id' => $storeId,
                'reason' => 'FOODEX populated demo opening balance',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $prices;
    }

    /** @param list<array{id:int,address_id:int}> $customers
     *  @param list<int> $productIds
     *  @param array<int, float> $prices
     */
    private function seedOrders(int $storeIndex, int $storeId, array $customers, array $productIds, array $prices, CarbonImmutable $now): int
    {
        $statuses = ['processing', 'confirmed', 'out_for_delivery', 'delivered', 'cancelled'];
        $assignedOrderId = 0;

        for ($orderIndex = 1; $orderIndex <= self::ORDERS_PER_STORE; $orderIndex++) {
            $customer = $customers[($orderIndex - 1) % count($customers)];
            $status = $statuses[($orderIndex - 1) % count($statuses)];
            $productA = $productIds[($orderIndex - 1) % count($productIds)];
            $productB = $productIds[($orderIndex + 6) % count($productIds)];
            $qtyA = 1 + ($orderIndex % 3);
            $qtyB = 1 + ($orderIndex % 2);
            $lineA = round($prices[$productA] * $qtyA, 3);
            $lineB = round($prices[$productB] * $qtyB, 3);
            $subtotal = round($lineA + $lineB, 3);
            $delivery = $subtotal >= 10 ? 0.000 : 1.000;
            $grandTotal = round($subtotal + $delivery, 3);
            $createdAt = $now->subDays($orderIndex % 8)->subMinutes(($storeIndex * 17) + $orderIndex);
            $orderNumber = DemoDataManager::ORDER_PREFIX.str_pad((string) $storeIndex, 2, '0', STR_PAD_LEFT).'-'.str_pad((string) $orderIndex, 3, '0', STR_PAD_LEFT);

            $orderId = (int) DB::table('orders')->insertGetId([
                'store_id' => $storeId,
                'customer_id' => $customer['id'],
                'address_id' => $customer['address_id'],
                'order_number' => $orderNumber,
                'channel' => 'b2c',
                'status' => $status,
                'currency' => 'KWD',
                'subtotal' => $subtotal,
                'discount_total' => 0,
                'delivery_total' => $delivery,
                'grand_total' => $grandTotal,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            foreach ([[$productA, $qtyA, $lineA], [$productB, $qtyB, $lineB]] as [$productId, $quantity, $lineTotal]) {
                $productPosition = array_search($productId, $productIds, true);
                DB::table('order_items')->insert([
                    'order_id' => $orderId,
                    'product_id' => $productId,
                    'sku_snapshot' => DemoDataManager::PRODUCT_PREFIX.str_pad((string) ($productPosition + 1), 3, '0', STR_PAD_LEFT),
                    'name_snapshot' => $this->products[$productPosition]['name'],
                    'quantity' => $quantity,
                    'unit_price' => $prices[$productId],
                    'line_total' => $lineTotal,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);
            }

            DB::table('order_status_history')->insert([
                'order_id' => $orderId,
                'user_id' => null,
                'from_status' => $status === 'processing' ? null : 'processing',
                'to_status' => $status,
                'note' => 'FOODEX demo seed status history.',
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            if ($status !== 'cancelled') {
                DB::table('payments')->insert([
                    'order_id' => $orderId,
                    'invoice_id' => null,
                    'provider' => $orderIndex % 3 === 0 ? 'Apple Pay' : 'KNET',
                    'provider_reference' => 'DEMO-PAY-'.$orderNumber,
                    'status' => 'paid',
                    'amount' => $grandTotal,
                    'currency' => 'KWD',
                    'metadata' => json_encode(['demo' => true, 'store' => $storeIndex], JSON_THROW_ON_ERROR),
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);
            }

            if ($assignedOrderId === 0 && $status === 'out_for_delivery') {
                $assignedOrderId = $orderId;
            }
        }

        if ($assignedOrderId === 0) {
            throw new RuntimeException('Demo order generation did not create an assignable delivery order.');
        }

        return $assignedOrderId;
    }
}
