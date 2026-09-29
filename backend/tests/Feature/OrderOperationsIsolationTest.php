<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\B2cCustomerService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderOperationsIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_retail_operations_page_lists_only_assigned_store_orders(): void
    {
        $mine = $this->store('OPS-MINE');
        $foreign = $this->store('OPS-FOREIGN');
        $admin = $this->storeAdmin($mine, 'ops-mine@example.test');

        $mineCustomer = app(B2cCustomerService::class)->create($mine, ['name' => 'Mine Buyer']);
        $foreignCustomer = app(B2cCustomerService::class)->create($foreign, ['name' => 'Foreign Buyer']);

        $mineOrder = $this->order($mine, (int) $mineCustomer->legacy_customer_id, (int) $mineCustomer->id, 'OPS-MINE-1001');
        $foreignOrder = $this->order($foreign, (int) $foreignCustomer->legacy_customer_id, (int) $foreignCustomer->id, 'OPS-FOREIGN-2001');

        $this->actingAs($admin)
            ->get('/admin/operations/orders')
            ->assertOk()
            ->assertSee('OPS-MINE-1001')
            ->assertDontSee('OPS-FOREIGN-2001');

        $this->actingAs($admin)->get('/admin/operations/orders?store_id='.$foreign)->assertNotFound();
        $this->actingAs($admin)->get('/admin/operations/orders?order='.$foreignOrder)->assertNotFound();

        $this->actingAs($admin)
            ->get('/admin/operations/orders?order='.$mineOrder)
            ->assertOk()
            ->assertSee('Order status timeline');
    }

    public function test_retail_admin_cannot_operate_foreign_order_but_can_transition_own_order(): void
    {
        $mine = $this->store('OPS-ACTION-MINE');
        $foreign = $this->store('OPS-ACTION-FOREIGN');
        $admin = $this->storeAdmin($mine, 'ops-actions@example.test');

        $mineCustomer = app(B2cCustomerService::class)->create($mine, ['name' => 'Mine Buyer']);
        $foreignCustomer = app(B2cCustomerService::class)->create($foreign, ['name' => 'Foreign Buyer']);

        $mineOrder = $this->order($mine, (int) $mineCustomer->legacy_customer_id, (int) $mineCustomer->id, 'OPS-ACTION-1001');
        $foreignOrder = $this->order($foreign, (int) $foreignCustomer->legacy_customer_id, (int) $foreignCustomer->id, 'OPS-ACTION-2001');

        $this->actingAs($admin)
            ->post("/admin/operations/orders/{$foreignOrder}/status", ['status' => 'confirmed'])
            ->assertNotFound();

        $this->assertDatabaseHas('orders', ['id' => $foreignOrder, 'status' => 'pending']);

        $this->actingAs($admin)
            ->post("/admin/operations/orders/{$mineOrder}/status", [
                'status' => 'confirmed',
                'note' => 'Confirmed by operations',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('orders', ['id' => $mineOrder, 'status' => 'confirmed']);
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $mineOrder,
            'to_status' => 'confirmed',
        ]);
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

    private function order(int $storeId, int $legacyCustomerId, int $customerId, string $number): int
    {
        return (int) DB::table('orders')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => $legacyCustomerId,
            'b2c_customer_id' => $customerId,
            'order_number' => $number,
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
    }

    private function storeAdmin(int $storeId, string $email): User
    {
        $user = User::query()->create([
            'name' => 'Retail Operations Admin',
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
}
