<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class ScreenshotEvidenceSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('ScreenshotEvidenceSeeder is forbidden in production.');
        }

        $this->call(DashboardDemoSeeder::class);

        $now = now();

        $superAdminId = (int) DB::table('users')->insertGetId([
            'name' => 'FOODEX Screenshot Admin',
            'email' => 'screenshots@foodex.test',
            'email_verified_at' => $now,
            'password' => Hash::make('Evidence123!'),
            'locale' => 'ar',
            'is_active' => true,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $superRoleId = (int) DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id');
        DB::table('role_user')->insert(['role_id' => $superRoleId, 'user_id' => $superAdminId]);

        $englishAdminId = (int) DB::table('users')->insertGetId([
            'name' => 'FOODEX Screenshot Admin EN',
            'email' => 'screenshots.en@foodex.test',
            'email_verified_at' => $now,
            'password' => Hash::make('Evidence123!'),
            'locale' => 'en',
            'is_active' => true,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('role_user')->insert(['role_id' => $superRoleId, 'user_id' => $englishAdminId]);

        $b2bType = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $b2bType,
            'code' => 'FOODEX-EVIDENCE-B2B',
            'name' => 'FOODEX Wholesale Evidence Branch',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'FOODEX-EVIDENCE-B2B-WH',
            'name' => 'FOODEX Wholesale Warehouse',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $categoryId = (int) DB::table('categories')->insertGetId([
            'name' => 'FOODEX Wholesale',
            'slug' => 'foodex-evidence-wholesale',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'EVID-CS',
            'name' => 'Case',
            'decimal_places' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'category_id' => $categoryId,
            'unit_id' => $unitId,
            'sku' => 'FOODEX-B2B-001',
            'name' => 'FOODEX Wholesale Tomato Case',
            'description' => 'Deterministic screenshot evidence product.',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('store_products')->insert([
            'store_id' => $storeId,
            'product_id' => $productId,
            'price' => 7.250,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('inventories')->insert([
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => 240,
            'reserved_quantity' => 20,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $tierId = (int) DB::table('b2b_price_tiers')->insertGetId([
            'code' => 'GOLD-EVIDENCE',
            'name' => 'Gold Evidence',
            'priority' => 100,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $customerId = (int) DB::table('customers')->insertGetId([
            'type' => 'b2b',
            'name' => 'FOODEX Business Customer',
            'phone' => '+96555500001',
            'email' => 'buyer@foodex.test',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('b2b_accounts')->insert([
            'customer_id' => $customerId,
            'price_tier_id' => $tierId,
            'company_name' => 'FOODEX Business Demo',
            'status' => 'active',
            'tax_number' => 'TX-EVID-001',
            'credit_limit' => 5000,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('b2b_price_rules')->insert([
            'price_tier_id' => $tierId,
            'store_id' => $storeId,
            'product_id' => $productId,
            'unit_price' => 6.950,
            'minimum_quantity' => 5,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $orderId = (int) DB::table('orders')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => $customerId,
            'order_number' => 'FOODEX-B2B-EVID-1001',
            'channel' => 'b2b',
            'status' => 'processing',
            'currency' => 'KWD',
            'subtotal' => 69.500,
            'discount_total' => 0,
            'delivery_total' => 5,
            'grand_total' => 74.500,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId,
            'product_id' => $productId,
            'sku_snapshot' => 'FOODEX-B2B-001',
            'name_snapshot' => 'FOODEX Wholesale Tomato Case',
            'quantity' => 10,
            'unit_price' => 6.950,
            'line_total' => 69.500,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $driverUserId = (int) DB::table('users')->insertGetId([
            'name' => 'سالم · سائق FOODEX أعمال',
            'email' => 'driver.b2b@foodex.test',
            'email_verified_at' => $now,
            'password' => Hash::make('Evidence123!'),
            'locale' => 'ar',
            'is_active' => true,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $driverRoleId = (int) DB::table('roles')->where('code', 'B2B_DRIVER')->value('id');
        DB::table('role_user')->insert(['role_id' => $driverRoleId, 'user_id' => $driverUserId]);
        $driverId = (int) DB::table('drivers')->insertGetId([
            'user_id' => $driverUserId,
            'driver_type' => 'b2b',
            'is_available' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('driver_assignments')->insert([
            'driver_id' => $driverId,
            'order_id' => $orderId,
            'assignment_type' => 'b2b',
            'status' => 'assigned',
            'assigned_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
