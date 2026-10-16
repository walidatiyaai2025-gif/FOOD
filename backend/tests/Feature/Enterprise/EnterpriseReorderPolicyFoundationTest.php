<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\Wms\ReorderPolicyService;
use App\Models\Role;
use App\Models\User;
use App\Models\UserStoreRole;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Feature\Enterprise\Concerns\EnablesEnterpriseWms;
use Tests\TestCase;

final class EnterpriseReorderPolicyFoundationTest extends TestCase
{
    use EnablesEnterpriseWms;
    use RefreshDatabase;

    public function test_policy_upsert_is_idempotent_and_low_stock_uses_authoritative_available_quantity(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $fixture = $this->fixture();
        $user = $this->inventoryUser($fixture['store_id']);
        $service = app(ReorderPolicyService::class);

        $policy = $service->upsert(
            $user,
            $fixture['warehouse_id'],
            $fixture['product_id'],
            [
                'minimum_quantity' => 8,
                'reorder_quantity' => 12,
                'maximum_quantity' => 20,
            ],
        );
        $retry = $service->upsert(
            $user,
            $fixture['warehouse_id'],
            $fixture['product_id'],
            [
                'minimum_quantity' => 8,
                'reorder_quantity' => 12,
                'maximum_quantity' => 20,
            ],
        );

        $this->assertSame($policy->id, $retry->id);
        $this->assertDatabaseCount('enterprise_reorder_policies', 1);

        $lowStock = $service->lowStock(
            $user,
            $fixture['store_id'],
            $fixture['warehouse_id'],
        );

        $this->assertCount(1, $lowStock);
        $this->assertSame(7.0, (float) $lowStock->firstOrFail()->getAttribute('available_quantity'));

        $updated = $service->upsert(
            $user,
            $fixture['warehouse_id'],
            $fixture['product_id'],
            [
                'minimum_quantity' => 6,
                'reorder_quantity' => 12,
                'maximum_quantity' => 20,
            ],
        );

        $this->assertSame($policy->id, $updated->id);
        $this->assertCount(
            0,
            $service->lowStock($user, $fixture['store_id'], $fixture['warehouse_id']),
        );
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.wms.reorder_policy.created',
            'auditable_id' => $policy->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.wms.reorder_policy.updated',
            'auditable_id' => $policy->id,
        ]);

        $disabled = $service->disable($user, $policy->id);
        $disabledRetry = $service->disable($user, $policy->id);
        $this->assertFalse($disabled->is_active);
        $this->assertFalse($disabledRetry->is_active);
    }

    public function test_explicit_warehouse_scope_denies_policy_mutation_outside_assignment(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $fixture = $this->fixture();
        $user = $this->inventoryUser($fixture['store_id']);

        DB::table('enterprise_warehouse_user_scopes')->insert([
            'user_id' => $user->id,
            'warehouse_id' => $fixture['warehouse_id'],
            'can_manage' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(NotFoundHttpException::class);

        app(ReorderPolicyService::class)->upsert(
            $user,
            $fixture['other_warehouse_id'],
            $fixture['product_id'],
            [
                'minimum_quantity' => 2,
                'reorder_quantity' => 5,
            ],
        );
    }

    public function test_policy_validation_rejects_products_not_stocked_in_warehouse_and_invalid_maximum(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $fixture = $this->fixture();
        $user = $this->inventoryUser($fixture['store_id']);
        $service = app(ReorderPolicyService::class);

        try {
            $service->upsert(
                $user,
                $fixture['warehouse_id'],
                $fixture['other_product_id'],
                [
                    'minimum_quantity' => 2,
                    'reorder_quantity' => 5,
                ],
            );
            $this->fail('Expected an unstocked product to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('product_id', $exception->errors());
        }

        $this->expectException(ValidationException::class);
        $service->upsert(
            $user,
            $fixture['warehouse_id'],
            $fixture['product_id'],
            [
                'minimum_quantity' => 10,
                'reorder_quantity' => 5,
                'maximum_quantity' => 9,
            ],
        );
    }

    public function test_low_stock_query_is_bounded_to_one_hundred_rows(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $fixture = $this->fixture();
        $user = $this->inventoryUser($fixture['store_id']);
        $now = now();

        $productRows = [];
        for ($index = 1; $index <= 105; $index++) {
            $productRows[] = [
                'catalog_id' => $fixture['catalog_id'],
                'unit_id' => $fixture['unit_id'],
                'sku' => 'REORDER-BULK-'.$index,
                'name' => 'Reorder Product '.$index,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('products')->insert($productRows);

        $products = DB::table('products')
            ->where('catalog_id', $fixture['catalog_id'])
            ->where('sku', 'like', 'REORDER-BULK-%')
            ->orderBy('id')
            ->get(['id']);

        $inventories = [];
        $policies = [];
        foreach ($products as $product) {
            $inventories[] = [
                'warehouse_id' => $fixture['warehouse_id'],
                'product_id' => (int) $product->id,
                'quantity' => 4,
                'reserved_quantity' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $policies[] = [
                'store_id' => $fixture['store_id'],
                'warehouse_id' => $fixture['warehouse_id'],
                'product_id' => (int) $product->id,
                'minimum_quantity' => 5,
                'reorder_quantity' => 10,
                'maximum_quantity' => 20,
                'is_active' => true,
                'created_by' => $user->id,
                'updated_by' => $user->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('inventories')->upsert(
            $inventories,
            ['warehouse_id', 'product_id'],
            ['quantity', 'reserved_quantity', 'updated_at'],
        );
        DB::table('enterprise_reorder_policies')->insert($policies);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows = app(ReorderPolicyService::class)->lowStock(
            $user,
            $fixture['store_id'],
            $fixture['warehouse_id'],
            500,
        );
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(100, $rows);
        $this->assertLessThanOrEqual(12, $queryCount);
    }

    /**
     * @return array{
     *   store_id:int,
     *   warehouse_id:int,
     *   other_warehouse_id:int,
     *   product_id:int,
     *   other_product_id:int,
     *   catalog_id:int,
     *   unit_id:int
     * }
     */
    private function fixture(): array
    {
        $storeTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => 'REORDER-STORE',
            'name' => 'Reorder Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'EA-REORDER',
            'name' => 'Each',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => 'b2c',
            'code' => 'default',
            'name' => 'Reorder Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => 'REORDER-MAIN',
            'name' => 'Main Reorder Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherProductId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => 'REORDER-OTHER',
            'name' => 'Other Reorder Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'REORDER-WH',
            'name' => 'Reorder Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherWarehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'REORDER-WH-2',
            'name' => 'Other Reorder Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('inventories')->insert([
            [
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'quantity' => 10,
                'reserved_quantity' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'warehouse_id' => $otherWarehouseId,
                'product_id' => $productId,
                'quantity' => 5,
                'reserved_quantity' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        return [
            'store_id' => $storeId,
            'warehouse_id' => $warehouseId,
            'other_warehouse_id' => $otherWarehouseId,
            'product_id' => $productId,
            'other_product_id' => $otherProductId,
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
        ];
    }

    private function inventoryUser(int $storeId): User
    {
        $this->enableEnterpriseWmsForStore($storeId);
        $user = User::query()->create([
            'name' => 'Reorder Inventory',
            'email' => 'reorder-inventory@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail();
        UserStoreRole::query()->create([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
        ]);

        return $user;
    }
}
