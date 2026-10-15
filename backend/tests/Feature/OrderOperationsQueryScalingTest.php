<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\B2cCustomerService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderOperationsQueryScalingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_paginated_order_operations_queries_do_not_scale_per_row(): void
    {
        $storeId = $this->store('OPS-QUERY-SCALE');
        $admin = $this->storeAdmin($storeId);
        $customer = app(B2cCustomerService::class)->create($storeId, ['name' => 'Query Scale Buyer']);

        $now = now();
        $orders = [];
        for ($index = 1; $index <= 100; $index++) {
            $orders[] = [
                'store_id' => $storeId,
                'customer_id' => (int) $customer->legacy_customer_id,
                'b2c_customer_id' => (int) $customer->id,
                'order_number' => sprintf('OPS-SCALE-%04d', $index),
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

        $this->actingAs($admin);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $small = $this->get('/admin/operations/orders?channel=b2c&per_page=25');
        $small->assertOk()->assertViewHas(
            'orders',
            static fn (LengthAwarePaginator $paginator): bool => $paginator->perPage() === 25
                && $paginator->count() === 25,
        );
        $smallQueryCount = count(DB::getQueryLog());

        DB::flushQueryLog();
        $large = $this->get('/admin/operations/orders?channel=b2c&per_page=100');
        $large->assertOk()->assertViewHas(
            'orders',
            static fn (LengthAwarePaginator $paginator): bool => $paginator->perPage() === 100
                && $paginator->count() === 100,
        );
        $largeQueryCount = count(DB::getQueryLog());

        $this->assertLessThanOrEqual(
            $smallQueryCount + 5,
            $largeQueryCount,
            "Order Operations query count grew with page rows: 25={$smallQueryCount}, 100={$largeQueryCount}",
        );
    }

    public function test_order_operations_defaults_to_25_and_caps_page_size_at_100(): void
    {
        $storeId = $this->store('OPS-PAGE-CONTRACT');
        $admin = $this->storeAdmin($storeId);

        $this->actingAs($admin)
            ->get('/admin/operations/orders?channel=b2c')
            ->assertOk()
            ->assertViewHas(
                'orders',
                static fn (LengthAwarePaginator $paginator): bool => $paginator->perPage() === 25,
            );

        $this->from('/admin/operations/orders?channel=b2c')
            ->get('/admin/operations/orders?channel=b2c&per_page=101')
            ->assertRedirect('/admin/operations/orders?channel=b2c')
            ->assertSessionHasErrors('per_page');
    }

    private function store(string $code): int
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

    private function storeAdmin(int $storeId): User
    {
        $user = User::query()->create([
            'name' => 'Query Scale Admin',
            'email' => 'query-scale-admin@example.test',
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
}
