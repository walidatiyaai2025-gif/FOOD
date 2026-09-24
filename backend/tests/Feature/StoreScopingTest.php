<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Store;
use App\Models\StoreProduct;
use App\Models\User;
use App\Models\Warehouse;
use App\Policies\StorePolicy;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StoreScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_b2c_store_admin_sees_only_explicitly_assigned_store_and_owned_rows(): void
    {
        [$storeA, $storeB] = $this->createB2cStores();
        $admin = $this->storeAdmin($storeA->id);
        [$warehouseA, $warehouseB] = $this->createStoreOwnedRows($storeA, $storeB);

        $this->assertSame(
            [$storeA->id],
            Store::query()->accessibleTo($admin)->orderBy('id')->pluck('id')->all(),
        );

        foreach ([
            Warehouse::class,
            StoreProduct::class,
            Cart::class,
            Order::class,
            Promotion::class,
            Banner::class,
            Setting::class,
        ] as $modelClass) {
            $this->assertSame(
                [$storeA->id],
                $modelClass::query()
                    ->accessibleTo($admin)
                    ->orderBy('store_id')
                    ->pluck('store_id')
                    ->unique()
                    ->values()
                    ->all(),
                $modelClass.' must remain inside the assigned store boundary.',
            );
        }

        $this->assertSame(
            [$warehouseA->id],
            Inventory::query()
                ->accessibleTo($admin)
                ->orderBy('warehouse_id')
                ->pluck('warehouse_id')
                ->all(),
        );

        $this->assertNotSame($warehouseA->id, $warehouseB->id);
        $this->assertTrue(app(StorePolicy::class)->view($admin, $storeA));
        $this->assertFalse(app(StorePolicy::class)->view($admin, $storeB));
    }

    public function test_unassigned_user_has_no_store_scope_access(): void
    {
        [$storeA] = $this->createB2cStores();
        $user = User::query()->create([
            'name' => 'Unassigned',
            'email' => 'unassigned-store@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);

        $this->assertFalse(app(StorePolicy::class)->view($user, $storeA));
        $this->assertSame([], Store::query()->accessibleTo($user)->pluck('id')->all());
    }

    public function test_super_admin_can_cross_store_boundary(): void
    {
        [$storeA, $storeB] = $this->createB2cStores();
        $superAdmin = User::query()->create([
            'name' => 'Super Admin',
            'email' => 'super-store@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $superAdmin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $this->assertSame(
            [$storeA->id, $storeB->id],
            Store::query()->accessibleTo($superAdmin)->orderBy('id')->pluck('id')->all(),
        );
        $this->assertTrue(app(StorePolicy::class)->view($superAdmin, $storeA));
        $this->assertTrue(app(StorePolicy::class)->view($superAdmin, $storeB));
    }

    /**
     * @return array{0: Store, 1: Store}
     */
    private function createB2cStores(): array
    {
        $storeTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        $storeA = Store::query()->create([
            'store_type_id' => $storeTypeId,
            'code' => 'B2C-SCOPE-A',
            'name' => 'Scope Store A',
            'is_active' => true,
        ]);

        $storeB = Store::query()->create([
            'store_type_id' => $storeTypeId,
            'code' => 'B2C-SCOPE-B',
            'name' => 'Scope Store B',
            'is_active' => true,
        ]);

        return [$storeA, $storeB];
    }

    private function storeAdmin(int $storeId): User
    {
        $user = User::query()->create([
            'name' => 'Store Admin',
            'email' => 'scoped-admin@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    /**
     * @return array{0: Warehouse, 1: Warehouse}
     */
    private function createStoreOwnedRows(Store $storeA, Store $storeB): array
    {
        $warehouseA = Warehouse::query()->create([
            'store_id' => $storeA->id,
            'code' => 'WH-SCOPE-A',
            'name' => 'Warehouse A',
            'is_active' => true,
        ]);
        $warehouseB = Warehouse::query()->create([
            'store_id' => $storeB->id,
            'code' => 'WH-SCOPE-B',
            'name' => 'Warehouse B',
            'is_active' => true,
        ]);

        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'SCOPE-EA',
            'name' => 'Each',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $product = Product::query()->create([
            'unit_id' => $unitId,
            'sku' => 'SCOPE-PRODUCT',
            'name' => 'Scoped Product',
            'is_active' => true,
        ]);

        foreach ([$storeA, $storeB] as $store) {
            StoreProduct::query()->create([
                'store_id' => $store->id,
                'product_id' => $product->id,
                'price' => 1,
                'is_active' => true,
            ]);
            Cart::query()->create([
                'store_id' => $store->id,
                'guest_token' => 'scope-token-'.$store->id,
                'channel' => 'b2c',
            ]);
            Promotion::query()->create([
                'store_id' => $store->id,
                'name' => 'Promotion '.$store->id,
                'type' => 'fixed',
                'value' => 1,
                'is_active' => true,
            ]);
            Banner::query()->create([
                'store_id' => $store->id,
                'title' => 'Banner '.$store->id,
                'image_path' => 'banner-'.$store->id.'.png',
                'is_active' => true,
            ]);
            Setting::query()->create([
                'store_id' => $store->id,
                'key' => 'scope.key.'.$store->id,
                'value' => ['enabled' => true],
            ]);
        }

        $customer = Customer::query()->create([
            'type' => 'b2c',
            'name' => 'Scoped Customer',
        ]);

        foreach ([$storeA, $storeB] as $store) {
            Order::query()->create([
                'store_id' => $store->id,
                'customer_id' => $customer->id,
                'order_number' => 'SCOPE-ORDER-'.$store->id,
                'channel' => 'b2c',
                'status' => 'pending',
                'currency' => 'KWD',
            ]);
        }

        Inventory::query()->create([
            'warehouse_id' => $warehouseA->id,
            'product_id' => $product->id,
            'quantity' => 10,
        ]);
        Inventory::query()->create([
            'warehouse_id' => $warehouseB->id,
            'product_id' => $product->id,
            'quantity' => 20,
        ]);

        return [$warehouseA, $warehouseB];
    }
}
