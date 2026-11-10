<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\B2bCustomerService;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** V2 T087R03: orders.view/create/dispatch/approve are not interchangeable grants. */
final class V2CustomerServiceOrderActionBoundariesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_service_roles_have_distinct_order_abilities_and_localized_read_only_http_access(): void
    {
        $manager = $this->globalUser('CUSTOMER_SERVICE_MANAGER');
        $supervisor = $this->globalUser('CUSTOMER_SERVICE_SUPERVISOR');
        $agent = $this->globalUser('CUSTOMER_SERVICE_AGENT');

        foreach ([$manager, $supervisor, $agent] as $actor) {
            foreach (['orders.view', 'orders.create', 'orders.dispatch'] as $ability) {
                $this->assertTrue($actor->hasPermission($ability), $ability);
            }
        }

        $this->assertTrue($manager->hasPermission('orders.approve'));
        $this->assertTrue($manager->hasPermission('orders.manage'));
        $this->assertFalse($supervisor->hasPermission('orders.approve'));
        $this->assertFalse($agent->hasPermission('orders.approve'));
        $this->assertFalse($agent->hasPermission('orders.manage'));

        app(WholesalePrincipal::class)->storeId();

        foreach ([$manager, $supervisor, $agent] as $actor) {
            foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
                app()->setLocale($locale);
                $actor->forceFill(['locale' => $locale])->save();

                $this->actingAs($actor)
                    ->get(route('admin.operations.orders.index', ['channel' => 'b2b']))
                    ->assertOk()
                    ->assertSee('dir="'.$direction.'"', false);
            }
        }
    }

    public function test_agent_can_reach_wholesale_new_order_quote_validation_but_cannot_select_retail_channel(): void
    {
        $wholesaleId = app(WholesalePrincipal::class)->storeId();
        $retailId = $this->retailStore('V2-2823-RETAIL-QUOTE');
        $agent = $this->globalUser('CUSTOMER_SERVICE_AGENT');

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $agent->forceFill(['locale' => $locale])->save();

            $this->actingAs($agent)
                ->postJson(route('admin.operations.orders.quote'), [
                    'channel' => 'b2b',
                    'store_id' => $wholesaleId,
                ])
                ->assertStatus(422);

            $this->actingAs($agent)
                ->postJson(route('admin.operations.orders.quote'), [
                    'channel' => 'b2c',
                    'store_id' => $retailId,
                ])
                ->assertNotFound();
        }

        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame(0, DB::table('order_customer_service_assignments')->count());
    }

    public function test_agent_cannot_manage_approve_or_assign_orders_even_when_view_create_dispatch_are_granted(): void
    {
        $orderId = $this->wholesaleOrder('V2-2823-ORD-1');
        $manager = $this->globalUser('CUSTOMER_SERVICE_MANAGER');
        $agent = $this->globalUser('CUSTOMER_SERVICE_AGENT');

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $agent->forceFill(['locale' => $locale])->save();

            // managedOrder() deliberately conceals records outside the
            // actor's orders.manage scope as 404; it is not a role grant.
            $this->actingAs($agent)
                ->patch(route('admin.operations.orders.update', ['order' => $orderId]), [
                    'status' => 'delivered',
                ])
                ->assertNotFound();

            $this->actingAs($agent)
                ->post(route('admin.operations.orders.transition', ['order' => $orderId]), [
                    'status' => 'cancelled',
                ])
                ->assertNotFound();

            $this->actingAs($agent)
                ->patch(route('admin.operations.orders.customer-service-owner', ['order' => $orderId]), [
                    'assigned_user_id' => $agent->id,
                ])
                ->assertForbidden();
        }

        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'pending']);
        $this->assertSame(0, DB::table('order_customer_service_assignments')->where('order_id', $orderId)->count());

        $this->actingAs($manager)
            ->patch(route('admin.operations.orders.customer-service-owner', ['order' => $orderId]), [
                'assigned_user_id' => $agent->id,
                'reason' => 'Authorized follow-up assignment',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('order_customer_service_assignments', [
            'order_id' => $orderId,
            'assigned_user_id' => $agent->id,
            'assigned_by_user_id' => $manager->id,
            'unassigned_at' => null,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'order.customer_service_owner.changed',
            'user_id' => $manager->id,
        ]);
    }

    public function test_retail_support_cannot_read_wholesale_or_foreign_store_orders_and_roleless_actor_cannot_enter(): void
    {
        $wholesaleOrderId = $this->wholesaleOrder('V2-2823-ORD-2');
        $ownStoreId = $this->retailStore('V2-2823-RETAIL-OWN');
        $foreignStoreId = $this->retailStore('V2-2823-RETAIL-FOREIGN');
        $retail = $this->storeUser('RETAIL_CUSTOMER_SUPPORT', $ownStoreId);
        $roleless = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $retail->forceFill(['locale' => $locale])->save();
            $roleless->forceFill(['locale' => $locale])->save();

            $this->actingAs($retail)
                ->get(route('admin.operations.orders.index', [
                    'channel' => 'b2c',
                    'store_id' => $ownStoreId,
                ]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            $this->actingAs($retail)
                ->get(route('admin.operations.orders.index', [
                    'channel' => 'b2c',
                    'store_id' => $foreignStoreId,
                ]))
                ->assertNotFound();

            $this->actingAs($retail)
                ->get(route('admin.operations.orders.index', ['channel' => 'b2b']))
                ->assertForbidden();

            $this->actingAs($retail)
                ->patch(route('admin.operations.orders.customer-service-owner', ['order' => $wholesaleOrderId]), [
                    'assigned_user_id' => $retail->id,
                ])
                ->assertForbidden();

            $this->actingAs($roleless)
                ->get(route('admin.operations.orders.index', ['channel' => 'b2b']))
                ->assertForbidden();
        }

        $this->assertDatabaseHas('orders', ['id' => $wholesaleOrderId, 'status' => 'pending']);
        $this->assertSame(0, DB::table('order_customer_service_assignments')->where('order_id', $wholesaleOrderId)->count());
    }

    private function wholesaleOrder(string $number): int
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
        $customer = app(B2bCustomerService::class)->create(['name' => 'V2 2823 Wholesale Customer']);

        return (int) DB::table('orders')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => (int) $customer->legacy_customer_id,
            'b2b_customer_id' => (int) $customer->getKey(),
            'order_number' => $number,
            'channel' => 'b2b',
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

    private function globalUser(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user->fresh();
    }

    private function storeUser(string $roleCode, int $storeId): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('code', $roleCode)->firstOrFail()->id,
            'store_id' => $storeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user->fresh();
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
}
