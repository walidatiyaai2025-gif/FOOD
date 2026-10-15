<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\B2cCustomerService;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UnifiedOrderOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_legacy_order_pages_are_canonical_aliases_of_operations_page(): void
    {
        $super = $this->superAdmin('unified-routes@example.test');

        app(WholesalePrincipal::class)->storeId();
        $this->retailStore('UNIFIED-ROUTE-RETAIL');

        $this->actingAs($super)
            ->get('/admin/b2b/orders')
            ->assertOk()
            ->assertViewIs('admin.order-operations');

        $this->actingAs($super)
            ->get('/admin/b2c/orders')
            ->assertOk()
            ->assertViewIs('admin.order-operations');
    }

    public function test_retail_admin_cannot_open_wholesale_or_all_channel_scope(): void
    {
        $store = $this->retailStore('UNIFIED-RETAIL');
        $admin = $this->retailAdmin($store, 'unified-retail@example.test');

        $this->actingAs($admin)
            ->get('/admin/operations/orders?channel=b2c')
            ->assertOk()
            ->assertDontSee('data-order-channel-tabs', false)
            ->assertDontSee('B2B · Wholesale');

        $this->actingAs($admin)
            ->get('/admin/operations/orders?channel=b2b')
            ->assertForbidden();

        $this->actingAs($admin)
            ->get('/admin/operations/orders?channel=all')
            ->assertForbidden();
    }

    public function test_wholesale_admin_cannot_open_retail_or_all_channel_scope(): void
    {
        $admin = User::query()->create([
            'name' => 'Unified Wholesale Admin',
            'email' => 'unified-wholesale@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());

        $this->actingAs($admin)
            ->get('/admin/operations/orders?channel=b2b')
            ->assertOk()
            ->assertDontSee('data-order-channel-tabs', false)
            ->assertDontSee('B2C · Retail');

        $this->actingAs($admin)
            ->get('/admin/operations/orders?channel=b2c')
            ->assertForbidden();

        $this->actingAs($admin)
            ->get('/admin/operations/orders?channel=all')
            ->assertForbidden();
    }

    public function test_multi_channel_admin_gets_channel_switcher_and_scoped_all_view(): void
    {
        $super = $this->superAdmin('unified-super@example.test');
        app(WholesalePrincipal::class)->storeId();
        $this->retailStore('UNIFIED-SUPER-RETAIL');

        $this->actingAs($super)
            ->get('/admin/operations/orders?channel=all')
            ->assertOk()
            ->assertSee('data-order-channel-tabs', false)
            ->assertSee('data-order-channel-tab="b2b"', false)
            ->assertSee('data-order-channel-tab="b2c"', false)
            ->assertSee('data-order-channel-tab="all"', false);
    }

    public function test_pending_order_actions_are_compact_and_edit_is_inside_ellipsis_menu(): void
    {
        $store = $this->retailStore('UNIFIED-ACTIONS');
        $admin = $this->retailAdmin($store, 'unified-actions@example.test');
        $customer = app(B2cCustomerService::class)->create($store, ['name' => 'Unified Buyer']);

        $order = (int) DB::table('orders')->insertGetId([
            'store_id' => $store,
            'customer_id' => (int) $customer->legacy_customer_id,
            'b2c_customer_id' => (int) $customer->id,
            'order_number' => 'UNIFIED-ORDER-1001',
            'channel' => 'b2c',
            'status' => 'pending',
            'currency' => 'KWD',
            'subtotal' => 10,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get('/admin/operations/orders?channel=b2c')
            ->assertOk()
            ->assertSee('#'.$order)
            ->assertSee('data-order-row-actions', false)
            ->assertSee('data-order-edit', false);
    }

    public function test_order_list_query_growth_is_bounded_across_supported_page_sizes(): void
    {
        $store = $this->retailStore('UNIFIED-PERF');
        $admin = $this->retailAdmin($store, 'unified-perf@example.test');
        $customer = app(B2cCustomerService::class)->create($store, ['name' => 'Performance Buyer']);

        $now = now();
        $orders = [];
        for ($index = 1; $index <= 100; $index++) {
            $orders[] = [
                'store_id' => $store,
                'customer_id' => (int) $customer->legacy_customer_id,
                'b2c_customer_id' => (int) $customer->id,
                'order_number' => sprintf('PERF-ORDER-%03d', $index),
                'channel' => 'b2c',
                'status' => 'pending',
                'currency' => 'KWD',
                'subtotal' => 10,
                'discount_total' => 0,
                'delivery_total' => 0,
                'grand_total' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('orders')->insert($orders);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->actingAs($admin)
            ->get('/admin/operations/orders?channel=b2c&per_page=25')
            ->assertOk()
            ->assertViewHas('orders', static fn ($paginator): bool => $paginator->perPage() === 25);
        $page25Queries = count(DB::getQueryLog());

        DB::flushQueryLog();

        $this->actingAs($admin)
            ->get('/admin/operations/orders?channel=b2c&per_page=100')
            ->assertOk()
            ->assertViewHas('orders', static fn ($paginator): bool => $paginator->perPage() === 100);
        $page100Queries = count(DB::getQueryLog());

        $this->assertLessThanOrEqual(
            $page25Queries + 12,
            $page100Queries,
            'Order Operations queries must stay bounded instead of growing per rendered row.',
        );
        $this->assertLessThan(
            300,
            $page100Queries,
            'The optimized 100-row page must remain materially below the 458-query baseline.',
        );

        $this->actingAs($admin)
            ->get('/admin/operations/orders?channel=b2c&per_page=101')
            ->assertSessionHasErrors('per_page');
    }

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function retailAdmin(int $storeId, string $email): User
    {
        $user = User::query()->create([
            'name' => 'Unified Retail Admin',
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function superAdmin(string $email): User
    {
        $user = User::query()->create([
            'name' => 'Unified Super Admin',
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }
}
