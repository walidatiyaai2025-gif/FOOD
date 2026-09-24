<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CoreSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_required_foundation_tables_exist(): void
    {
        $tables = [
            'users',
            'roles',
            'permissions',
            'permission_role',
            'role_user',
            'store_types',
            'stores',
            'user_store_roles',
            'warehouses',
            'categories',
            'brands',
            'units',
            'products',
            'product_images',
            'store_products',
            'inventories',
            'stock_movements',
            'customers',
            'b2b_price_tiers',
            'b2b_accounts',
            'addresses',
            'carts',
            'cart_items',
            'orders',
            'order_items',
            'order_status_history',
            'invoices',
            'invoice_items',
            'payments',
            'promotions',
            'coupons',
            'banners',
            'drivers',
            'driver_assignments',
            'delivery_proofs',
            'notifications',
            'app_versions',
            'system_versions',
            'update_history',
            'audit_logs',
            'settings',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing foundation table: {$table}");
        }
    }

    public function test_store_and_channel_scope_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('stores', ['store_type_id', 'is_active']));
        $this->assertTrue(Schema::hasColumns('user_store_roles', ['user_id', 'store_id', 'role_id']));
        $this->assertTrue(Schema::hasColumns('store_products', ['store_id', 'product_id', 'is_active']));
        $this->assertTrue(Schema::hasColumns('carts', ['store_id', 'customer_id', 'channel']));
        $this->assertTrue(Schema::hasColumns('orders', ['store_id', 'customer_id', 'channel', 'status']));
        $this->assertTrue(Schema::hasColumns('drivers', ['user_id', 'driver_type', 'is_active']));
        $this->assertTrue(Schema::hasColumns(
            'driver_assignments',
            ['driver_id', 'order_id', 'assignment_type', 'status'],
        ));
    }

    public function test_operational_indexes_exist(): void
    {
        $expected = [
            'stores' => ['stores_type_active_idx'],
            'user_store_roles' => ['user_store_roles_store_user_idx'],
            'warehouses' => ['warehouses_store_active_idx'],
            'products' => ['products_category_active_idx', 'products_brand_active_idx'],
            'store_products' => ['store_products_store_active_idx'],
            'inventories' => ['inventories_product_idx'],
            'stock_movements' => ['stock_movements_inventory_created_idx'],
            'carts' => ['carts_store_channel_idx', 'carts_customer_channel_idx'],
            'orders' => ['orders_store_channel_status_idx', 'orders_customer_created_idx'],
            'invoices' => ['invoices_customer_status_idx'],
            'promotions' => ['promotions_store_active_idx'],
            'drivers' => ['drivers_type_active_available_idx'],
            'driver_assignments' => ['driver_assignments_order_status_idx'],
            'notifications' => ['notifications_user_read_idx'],
            'audit_logs' => ['audit_logs_user_created_idx'],
        ];

        foreach ($expected as $table => $indexNames) {
            $actual = array_column(Schema::getIndexes($table), 'name');

            foreach ($indexNames as $indexName) {
                $this->assertContains($indexName, $actual, "Missing {$indexName} on {$table}");
            }
        }
    }

    public function test_store_role_assignment_is_unique(): void
    {
        $fixture = $this->createStoreRoleAssignmentFixture();

        DB::table('user_store_roles')->insert([
            'user_id' => $fixture['user_id'],
            'store_id' => $fixture['store_id'],
            'role_id' => $fixture['role_id'],
        ]);

        $this->expectException(QueryException::class);

        DB::table('user_store_roles')->insert([
            'user_id' => $fixture['user_id'],
            'store_id' => $fixture['store_id'],
            'role_id' => $fixture['role_id'],
        ]);
    }

    public function test_store_role_assignment_cascades_when_store_is_deleted(): void
    {
        $fixture = $this->createStoreRoleAssignmentFixture();

        DB::table('user_store_roles')->insert([
            'user_id' => $fixture['user_id'],
            'store_id' => $fixture['store_id'],
            'role_id' => $fixture['role_id'],
        ]);

        DB::table('stores')->where('id', $fixture['store_id'])->delete();

        $this->assertDatabaseMissing('user_store_roles', [
            'user_id' => $fixture['user_id'],
            'store_id' => $fixture['store_id'],
            'role_id' => $fixture['role_id'],
        ]);
    }

    public function test_store_requires_an_existing_store_type(): void
    {
        $this->expectException(QueryException::class);

        DB::table('stores')->insert([
            'store_type_id' => 999999,
            'code' => 'INVALID-FK',
            'name' => 'Invalid foreign key fixture',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createStoreRoleAssignmentFixture(): array
    {
        $now = now();

        $userId = DB::table('users')->insertGetId([
            'name' => 'Schema Test User',
            'email' => 'schema-test@example.test',
            'password' => 'not-used-by-schema-test',
            'locale' => 'ar',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $roleId = DB::table('roles')->insertGetId([
            'code' => 'SCHEMA_TEST_ROLE',
            'name' => 'Schema Test Role',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $storeTypeId = DB::table('store_types')->insertGetId([
            'code' => 'SCHEMA_TEST',
            'name' => 'Schema Test Store Type',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $storeId = DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => 'SCHEMA-TEST',
            'name' => 'Schema Test Store',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'user_id' => $userId,
            'role_id' => $roleId,
            'store_id' => $storeId,
        ];
    }
}
