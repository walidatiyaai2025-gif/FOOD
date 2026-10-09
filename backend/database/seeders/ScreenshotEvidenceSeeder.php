<?php

namespace Database\Seeders;

use App\Services\PlatformCustomerService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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

        DB::table('notifications')->insert([
            'user_id' => null,
            'created_by' => $superAdminId,
            'channel' => 'both',
            'type' => 'evidence',
            'audience' => 'van',
            'app' => 'van',
            'target_channel' => 'all',
            'status' => 'draft',
            'title' => 'إشعار فان تجريبي',
            'title_ar' => 'إشعار فان تجريبي',
            'title_en' => 'Van evidence notification',
            'body' => 'إشعار مخصص لإثبات واجهة الإدارة.',
            'body_ar' => 'إشعار مخصص لإثبات واجهة الإدارة.',
            'body_en' => 'Dashboard runtime evidence notification for Van.',
            'data' => json_encode(['evidence' => true], JSON_THROW_ON_ERROR),
            'published_at' => null,
            'read_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('notification_campaigns')->insert([
            'name' => 'Van Runtime Evidence Campaign',
            'type' => 'promotion',
            'title_ar' => 'حملة فان تجريبية',
            'title_en' => 'Van runtime evidence campaign',
            'body_ar' => 'حملة مخصصة لإثبات إجراءات السجل في لوحة الإدارة.',
            'body_en' => 'Campaign used to prove Dashboard record actions for Van.',
            'audience' => 'van',
            'app' => 'van',
            'target_channel' => 'all',
            'delivery_channel' => 'both',
            'popup_frequency' => 'once_per_session',
            'popup_cta_label_ar' => 'فتح',
            'popup_cta_label_en' => 'Open',
            'popup_cta_target' => '/orders',
            'user_id' => null,
            'store_id' => null,
            'created_by' => $superAdminId,
            'schedule_kind' => 'once',
            'timezone' => 'Asia/Kuwait',
            'starts_at' => $now->addHour(),
            'interval_value' => null,
            'interval_unit' => null,
            'ends_at' => null,
            'max_runs' => 1,
            'run_count' => 0,
            'next_run_at' => $now->addHour(),
            'last_run_at' => null,
            'last_notification_id' => null,
            'status' => 'draft',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

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

        $retailStoreId = (int) DB::table('stores')
            ->where('code', 'FOODEX-DEMO-B2C')
            ->value('id');

        $platformUser = app(PlatformCustomerService::class)->register([
            'name' => 'FOODEX Address Evidence Customer',
            'email' => 'evidence.address@foodex.test',
            'phone' => '+96555509999',
            'password' => 'Evidence123!',
            'locale' => 'ar',
            'store_id' => $retailStoreId,
        ], 'dashboard');

        $platformCustomer = DB::table('platform_customers')
            ->where('user_id', $platformUser->id)
            ->first();

        if ($platformCustomer === null) {
            throw new RuntimeException('Platform Customer screenshot fixture was not created.');
        }

        $addressId = (int) DB::table('addresses')->insertGetId([
            'customer_id' => (int) $platformCustomer->legacy_customer_id,
            'platform_customer_id' => (int) $platformCustomer->id,
            'b2b_customer_id' => null,
            'b2c_customer_id' => null,
            'label' => 'Home',
            'recipient_name' => 'FOODEX Address Evidence Customer',
            'delivery_phone' => '+96555509999',
            'line1' => 'Block 1, Street 5, Building 12',
            'line2' => null,
            'city' => 'Kuwait City',
            'area' => 'Bayan',
            'country_code' => 'KW',
            'country' => 'Kuwait',
            'governorate' => 'Hawalli',
            'block' => '1',
            'street' => 'Street 5',
            'avenue' => null,
            'building' => '12',
            'floor' => '2',
            'apartment' => '7',
            'landmark' => 'Near Bayan Co-op',
            'delivery_notes' => 'Call on arrival',
            'latitude' => 29.3031000,
            'longitude' => 48.0489000,
            'location_accuracy_meters' => 6.0,
            'location_source' => 'map_pin',
            'is_default' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('address_quality_reviews')->insert([
            'public_id' => (string) Str::uuid(),
            'subject_type' => 'platform_customer_address',
            'subject_id' => $addressId,
            'status' => 'unmapped',
            'quality_class' => 'review_required',
            'confidence' => 0.7200,
            'territory_key' => null,
            'resolution_source' => null,
            'reason' => 'Deterministic Field Operations address-quality evidence.',
            'resolved_by' => null,
            'resolved_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $b2cCustomerId = DB::table('b2c_customers')
            ->where('user_id', $platformUser->id)
            ->where('store_id', $retailStoreId)
            ->value('id');

        $deliverySnapshot = [
            'version' => 1,
            'address_id' => $addressId,
            'label' => 'Home',
            'recipient_name' => 'FOODEX Address Evidence Customer',
            'delivery_phone' => '+96555509999',
            'line1' => 'Block 1, Street 5, Building 12',
            'line2' => null,
            'city' => 'Kuwait City',
            'area' => 'Bayan',
            'country_code' => 'KW',
            'country' => 'Kuwait',
            'governorate' => 'Hawalli',
            'block' => '1',
            'street' => 'Street 5',
            'avenue' => null,
            'building' => '12',
            'floor' => '2',
            'apartment' => '7',
            'landmark' => 'Near Bayan Co-op',
            'delivery_notes' => 'Call on arrival',
            'latitude' => 29.3031,
            'longitude' => 48.0489,
            'location_accuracy_meters' => 6.0,
            'location_source' => 'map_pin',
        ];

        DB::table('orders')->insert([
            'store_id' => $retailStoreId,
            'customer_id' => (int) $platformCustomer->legacy_customer_id,
            'b2c_customer_id' => $b2cCustomerId === null ? null : (int) $b2cCustomerId,
            'address_id' => $addressId,
            'delivery_address_snapshot' => json_encode($deliverySnapshot, JSON_THROW_ON_ERROR),
            'delivery_latitude' => 29.3031000,
            'delivery_longitude' => 48.0489000,
            'order_number' => 'FOODEX-EVID-LOC-1',
            'channel' => 'b2c',
            'status' => 'confirmed',
            'currency' => 'EGP',
            'subtotal' => 12.500,
            'discount_total' => 0,
            'delivery_total' => 1.000,
            'grand_total' => 13.500,
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
            'currency' => 'EGP',
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

        $driverStaleAt = $now->copy()->subSeconds(55);
        DB::table('driver_current_locations')->insert([
            'driver_id' => $driverId,
            'store_id' => $storeId,
            'channel' => 'b2b',
            'latitude' => 29.3768000,
            'longitude' => 47.9822000,
            'accuracy' => 8.0,
            'speed' => 0.0,
            'heading' => 90.0,
            'captured_at' => $driverStaleAt,
            'received_at' => $driverStaleAt,
            'app_version' => '1.0.60',
            'is_mocked' => false,
            'active_assignment_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $vanOperatorId = (int) DB::table('users')->insertGetId([
            'name' => 'نورة · مشغلة فان FOODEX',
            'email' => 'van.operator@foodex.test',
            'email_verified_at' => $now,
            'password' => Hash::make('Evidence123!'),
            'locale' => 'ar',
            'is_active' => true,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $vanId = (int) DB::table('vans')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'code' => 'VAN-EVID-01',
            'plate_number' => 'FOODEX-801',
            'vehicle_type' => 'sales_van',
            'status' => 'active',
            'capacity_units' => 120,
            'capacity_weight' => 1500,
            'home_warehouse_id' => $warehouseId,
            'notes' => 'Deterministic mixed live-tracking visual evidence.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $vanAssignmentId = (int) DB::table('van_assignments')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'van_id' => $vanId,
            'driver_id' => null,
            'representative_user_id' => $vanOperatorId,
            'warehouse_id' => $warehouseId,
            'territory_key' => 'KW-EVIDENCE',
            'van_pool_key' => 'EVIDENCE',
            'assignment_type' => 'primary',
            'status' => 'active',
            'effective_from' => $now->copy()->subHour(),
            'effective_until' => null,
            'loaded_work_count' => 12,
            'transferred_to_van_id' => null,
            'transfer_reason' => null,
            'created_by' => $superAdminId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('fleet_current_locations')->insert([
            'actor_type' => 'van',
            'actor_id' => $vanId,
            'vehicle_id' => $vanId,
            'assignment_id' => $vanAssignmentId,
            'route_key' => 'ROUTE-EVID-01',
            'store_id' => $storeId,
            'channel' => 'b2b',
            'latitude' => 29.3826000,
            'longitude' => 47.9894000,
            'accuracy' => 5.0,
            'speed' => 3.2,
            'heading' => 180.0,
            'captured_at' => $now,
            'received_at' => $now,
            'source_app' => 'van',
            'app_version' => '1.0.60',
            'is_mocked' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $collectionAccountId = (int) DB::table('collection_accounts')->insertGetId([
            'actor_type' => 'van',
            'actor_id' => $vanId,
            'store_id' => $storeId,
            'currency' => 'EGP',
            'status' => 'active',
            'custody_limit' => 500,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $collectionTransactionId = (int) DB::table('collection_transactions')->insertGetId([
            'collection_account_id' => $collectionAccountId,
            'payment_id' => null,
            'idempotency_key' => 'EVIDENCE-VAN-COLLECTION-001',
            'type' => 'collection',
            'status' => 'posted',
            'amount' => 87.500,
            'currency' => 'EGP',
            'source' => 'cash_on_delivery',
            'created_by' => $superAdminId,
            'created_at' => $now->copy()->subMinutes(18),
            'updated_at' => $now->copy()->subMinutes(18),
        ]);

        DB::table('custody_ledger_entries')->insert([
            'collection_account_id' => $collectionAccountId,
            'entry_type' => 'collection',
            'amount' => 87.500,
            'currency' => 'EGP',
            'reference_type' => 'collection_transaction',
            'reference_id' => $collectionTransactionId,
            'created_by' => $superAdminId,
            'created_at' => $now->copy()->subMinutes(18),
            'updated_at' => $now->copy()->subMinutes(18),
        ]);

        DB::table('remittances')->insert([
            'collection_account_id' => $collectionAccountId,
            'idempotency_key' => 'EVIDENCE-VAN-REMIT-001',
            'amount' => 25.000,
            'currency' => 'EGP',
            'method' => 'bank_transfer',
            'reference' => 'VAN-REMIT-EVID-001',
            'status' => 'pending',
            'note' => 'Deterministic Van finance support runtime evidence.',
            'submitted_by' => $vanOperatorId,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'created_at' => $now->copy()->subMinutes(8),
            'updated_at' => $now->copy()->subMinutes(8),
        ]);
    }
}
