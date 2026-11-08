<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\B2bCustomerService;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderCustomerServiceDiscountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_discount_replaces_prior_concession_preserves_existing_offer_and_is_idempotent(): void
    {
        $manager = $this->userWithRole('CUSTOMER_SERVICE_MANAGER');
        $customerUser = User::factory()->create(['is_active' => true]);
        $id = $this->orderFor($customerUser);
        $firstKey = (string) Str::uuid();
        $route = route('admin.operations.orders.customer-service-discount', ['order' => $id]);

        $this->actingAs($manager)->post($route, [
            'percentage' => '10', 'reason' => 'Customer loyalty correction',
            'idempotency_key' => $firstKey,
        ])->assertRedirect();
        $this->assertDatabaseHas('orders', [
            'id' => $id, 'discount_total' => 1.900, 'grand_total' => 8.100,
        ]);
        $this->assertDatabaseHas('order_customer_service_discounts', [
            'order_id' => $id, 'revision' => 1,
            'customer_service_discount' => 0.900,
            'reason' => 'Customer loyalty correction',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'order.customer_service_discount.applied',
            'user_id' => $manager->id,
        ]);
        $this->assertSame(1, DB::table('notifications')
            ->where('user_id', $customerUser->id)->where('type', 'order.updated')->count());

        $this->actingAs($manager)->post($route, [
            'percentage' => '10', 'reason' => 'Customer loyalty correction',
            'idempotency_key' => $firstKey,
        ])->assertRedirect();
        $this->assertSame(1, DB::table('order_customer_service_discounts')
            ->where('order_id', $id)->count());

        // Re-entry into the pending CS stage allows a new reasoned revision;
        // percent is not applied on top of the earlier CS concession.
        DB::table('orders')->where('id', $id)->update(['status' => 'confirmed']);
        $this->actingAs($manager)->post($route, [
            'percentage' => '20', 'reason' => 'Not yet back to CS stage',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('percentage');
        DB::table('orders')->where('id', $id)->update(['status' => 'pending']);

        $this->actingAs($manager)->post($route, [
            'percentage' => '20', 'reason' => 'Service recovery adjustment',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();
        $this->assertDatabaseHas('orders', [
            'id' => $id, 'discount_total' => 2.800, 'grand_total' => 7.200,
        ]);
        $this->assertSame(2, DB::table('order_customer_service_discounts')
            ->where('order_id', $id)->count());
        $this->assertSame(2, DB::table('notifications')
            ->where('user_id', $customerUser->id)->where('type', 'order.updated')->count());
    }

    public function test_discount_requires_reason_and_rejects_final_payment(): void
    {
        $manager = $this->userWithRole('CUSTOMER_SERVICE_MANAGER');
        $id = $this->orderFor();
        $route = route('admin.operations.orders.customer-service-discount', ['order' => $id]);

        $this->actingAs($manager)->post($route, [
            'percentage' => 5, 'reason' => '',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('reason');

        DB::table('payments')->insert([
            'order_id' => $id, 'provider' => 'cash',
            'status' => 'paid', 'amount' => 9, 'currency' => 'KWD',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($manager)->post($route, [
            'percentage' => 5, 'reason' => 'After payment is prohibited',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('percentage');
        $this->assertSame(0, DB::table('order_customer_service_discounts')
            ->where('order_id', $id)->count());
    }

    public function test_agent_can_discount_only_an_order_actively_assigned_to_them(): void
    {
        $agent = $this->userWithRole('CUSTOMER_SERVICE_AGENT');
        $id = $this->orderFor();
        $route = route('admin.operations.orders.customer-service-discount', ['order' => $id]);

        $this->actingAs($agent)->post($route, [
            'percentage' => 5, 'reason' => 'Unassigned agent cannot change pricing',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertForbidden();

        DB::table('order_customer_service_assignments')->insert([
            'order_id' => $id, 'assigned_user_id' => $agent->id,
            'assigned_at' => now(), 'unassigned_at' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($agent)->post($route, [
            'percentage' => 5, 'reason' => 'Authorized customer care gesture',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();
        $this->assertDatabaseHas('order_customer_service_discounts', [
            'order_id' => $id, 'applied_by_user_id' => $agent->id,
        ]);
    }

    public function test_retail_support_discount_is_restricted_to_its_own_store(): void
    {
        $retailType = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $supportRole = (int) Role::query()->where('code', 'RETAIL_CUSTOMER_SUPPORT')->value('id');
        $support = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $orders = [];

        foreach ([1, 2] as $number) {
            $store = (int) DB::table('stores')->insertGetId([
                'store_type_id' => $retailType,
                'code' => 'CS-DISCOUNT-RETAIL-'.$number,
                'name' => 'Retail CS '.$number,
                'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($number === 1) {
                DB::table('user_store_roles')->insert([
                    'user_id' => $support->id, 'store_id' => $store,
                    'role_id' => $supportRole,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $legacyId = (int) DB::table('customers')->insertGetId([
                'type' => 'b2c', 'name' => 'Retail CS Customer '.$number,
                'email' => 'cs-discount-'.$number.'@example.test',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $retailId = (int) DB::table('b2c_customers')->insertGetId([
                'store_id' => $store, 'legacy_customer_id' => $legacyId,
                'name' => 'Retail CS Customer '.$number,
                'email' => 'cs-discount-'.$number.'@example.test',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $orders[] = (int) DB::table('orders')->insertGetId([
                'store_id' => $store, 'customer_id' => $legacyId,
                'b2c_customer_id' => $retailId,
                'order_number' => 'CS-RETAIL-ORDER-'.$number,
                'channel' => 'b2c', 'status' => 'pending',
                'currency' => 'EGP', 'subtotal' => 20,
                'discount_total' => 0, 'delivery_total' => 0,
                'tax_total' => 0, 'grand_total' => 20,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $payload = [
            'percentage' => '12.5',
            'reason' => 'Retail delivery experience recovery',
            'idempotency_key' => (string) Str::uuid(),
        ];
        $this->actingAs($support)
            ->post(route('admin.operations.orders.customer-service-discount', ['order' => $orders[0]]), $payload)
            ->assertRedirect();
        $this->assertDatabaseHas('orders', ['id' => $orders[0], 'grand_total' => 17.5]);
        $this->assertDatabaseHas('order_customer_service_discounts', [
            'order_id' => $orders[0], 'applied_by_user_id' => $support->id,
        ]);

        $payload['idempotency_key'] = (string) Str::uuid();
        $this->actingAs($support)
            ->post(route('admin.operations.orders.customer-service-discount', ['order' => $orders[1]]), $payload)
            ->assertNotFound();
        $this->assertDatabaseHas('orders', ['id' => $orders[1], 'grand_total' => 20]);
        $this->assertDatabaseMissing('order_customer_service_discounts', ['order_id' => $orders[1]]);
    }

    private function orderFor(?User $customerUser = null): int
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
        $customer = app(B2bCustomerService::class)->create(['name' => 'CS Discount Test']);
        if ($customerUser !== null) {
            DB::table('b2b_customers')->where('id', $customer->getKey())
                ->update(['user_id' => $customerUser->getKey()]);
        }

        return (int) DB::table('orders')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => (int) $customer->legacy_customer_id,
            'b2b_customer_id' => (int) $customer->getKey(),
            'order_number' => 'CS-DISCOUNT-'.Str::upper(Str::random(9)),
            'channel' => 'b2b',
            'status' => 'pending',
            'currency' => 'KWD',
            'subtotal' => 10,
            'discount_total' => 1,
            'delivery_total' => 0,
            'tax_total' => 0,
            'grand_total' => 9,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function userWithRole(string $code): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', $code)->firstOrFail());

        return $user->fresh();
    }
}
