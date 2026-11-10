<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\B2cCustomerService;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * V2 #2544 / P214: order dispatch is an existing authorized context action
 * inside Order Operations, not a second Sidebar route or Van staff assignment.
 */
final class V2OrderAssignmentsSidebarHttpContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_order_assignment_reuses_one_discoverable_scoped_operations_route_in_both_locales(): void
    {
        $store = $this->retailStore('V2-P214-DISCOVERY');
        $actor = $this->retailAdmin($store, 'v2-p214-discovery@example.test');
        $order = $this->retailOrder($store, 'V2-P214-DISCOVERY-ORDER');

        foreach ([
            'ar' => 'rtl',
            'en' => 'ltr',
        ] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $navigation = app(AdminNavigation::class)->groupsFor($actor->fresh());
            $operations = collect($navigation)->firstWhere('key', 'operations');
            $this->assertIsArray($operations);
            $links = collect($operations['children'])
                ->where('key', 'order_operations')
                ->values();
            $this->assertCount(1, $links);
            $this->assertSame('admin.operations.orders.index', $links->first()['route']);
            $this->assertTrue(Route::has('admin.operations.orders.dispatch'));
            $this->assertTrue(Route::has('admin.operations.orders.dispatch.clear'));

            $this->actingAs($actor->fresh())
                ->get(route('admin.operations.orders.index', ['channel' => 'b2c']))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-order-inline-dispatch', false)
                ->assertSee(route('admin.operations.orders.dispatch', $order), false)
                ->assertDontSee('option value="van:', false);
        }
    }

    public function test_order_dispatch_explicit_deny_hides_action_and_blocks_forged_write(): void
    {
        $store = $this->retailStore('V2-P214-DENY');
        $actor = $this->retailAdmin($store, 'v2-p214-deny@example.test');
        $order = $this->retailOrder($store, 'V2-P214-DENY-ORDER');
        $permission = Permission::query()->where('code', 'orders.dispatch')->firstOrFail();

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->getKey(),
            'permission_id' => $permission->getKey(),
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        $this->actingAs($actor->fresh())
            ->get(route('admin.operations.orders.index', ['channel' => 'b2c']))
            ->assertOk()
            ->assertViewHas('rows', static fn (array $rows): bool => collect($rows)
                ->firstWhere('id', $order)['can_dispatch_authorized'] === false)
            ->assertDontSee('<form method="post" action="'.route('admin.operations.orders.dispatch', $order).'" class="order-inline-picker"', false);

        $this->actingAs($actor->fresh())
            ->patch(route('admin.operations.orders.dispatch', $order), [
                'assignee_type' => 'driver',
                'assignee_id' => 1,
                'reason' => 'Forged request',
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('order_dispatch_states', ['order_id' => $order]);
    }

    public function test_retail_store_and_wholesale_channel_cannot_dispatch_another_scope(): void
    {
        $ownStore = $this->retailStore('V2-P214-OWN');
        $otherStore = $this->retailStore('V2-P214-OTHER');
        $actor = $this->retailAdmin($ownStore, 'v2-p214-owner@example.test');
        $foreignOrder = $this->retailOrder($otherStore, 'V2-P214-FOREIGN-ORDER');

        $this->actingAs($actor)
            ->get(route('admin.operations.orders.index', [
                'channel' => 'b2c',
                'store_id' => $otherStore,
            ]))
            ->assertNotFound();

        $this->actingAs($actor)
            ->patch(route('admin.operations.orders.dispatch', $foreignOrder), [
                'assignee_type' => 'driver',
                'assignee_id' => 1,
                'reason' => 'Cross-store attempt',
            ])
            ->assertNotFound();

        $this->actingAs($actor)
            ->delete(route('admin.operations.orders.dispatch.clear', $foreignOrder), [
                'reason' => 'Cross-store attempt',
            ])
            ->assertNotFound();

        $b2bStore = app(WholesalePrincipal::class)->storeId();
        $b2bOrder = $this->wholesaleOrder($b2bStore);

        $this->actingAs($actor)
            ->get(route('admin.operations.orders.index', ['channel' => 'b2b']))
            ->assertForbidden();
        $this->actingAs($actor)
            ->patch(route('admin.operations.orders.dispatch', $b2bOrder), [
                'assignee_type' => 'van',
                'assignee_id' => 1,
                'reason' => 'Cross-channel attempt',
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('order_dispatch_states', ['order_id' => $foreignOrder]);
        $this->assertDatabaseMissing('order_dispatch_states', ['order_id' => $b2bOrder]);
    }

    public function test_server_rejects_wrong_fulfillment_actor_for_both_channels(): void
    {
        $store = $this->retailStore('V2-P214-ACTOR');
        $retailOrder = $this->retailOrder($store, 'V2-P214-ACTOR-RETAIL');
        $wholesaleOrder = $this->wholesaleOrder(app(WholesalePrincipal::class)->storeId());
        $actor = $this->superAdmin();

        $this->actingAs($actor)
            ->patch(route('admin.operations.orders.dispatch', $retailOrder), [
                'assignee_type' => 'van',
                'assignee_id' => 1,
                'reason' => 'B2C must not use Van',
            ])
            ->assertSessionHasErrors('assignee_type');

        $this->actingAs($actor)
            ->patch(route('admin.operations.orders.dispatch', $wholesaleOrder), [
                'assignee_type' => 'driver',
                'assignee_id' => 1,
                'reason' => 'B2B must not use Driver',
            ])
            ->assertSessionHasErrors('assignee_type');

        $this->assertDatabaseMissing('order_dispatch_states', ['order_id' => $retailOrder]);
        $this->assertDatabaseMissing('order_dispatch_states', ['order_id' => $wholesaleOrder]);
    }

    public function test_roleless_user_has_neither_order_navigation_nor_direct_order_access(): void
    {
        $store = $this->retailStore('V2-P214-ROLELESS');
        $order = $this->retailOrder($store, 'V2-P214-ROLELESS-ORDER');
        $actor = User::factory()->create(['is_active' => true]);

        $links = collect(app(AdminNavigation::class)->groupsFor($actor))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->pluck('key')
            ->all();
        $this->assertNotContains('order_operations', $links);

        $this->actingAs($actor)
            ->get(route('admin.operations.orders.index'))
            ->assertForbidden();
        $this->actingAs($actor)
            ->patch(route('admin.operations.orders.dispatch', $order), [
                'assignee_type' => 'driver',
                'assignee_id' => 1,
                'reason' => 'Roleless attempt',
            ])
            ->assertForbidden();
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
        $actor = User::factory()->create(['email' => $email, 'locale' => 'en', 'is_active' => true]);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->getKey(),
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->getKey(),
            'store_id' => $storeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor->fresh();
    }

    private function superAdmin(): User
    {
        $actor = User::factory()->create(['is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $actor->fresh();
    }

    private function retailOrder(int $storeId, string $number): int
    {
        $customer = app(B2cCustomerService::class)->create($storeId, ['name' => 'V2 P214 Buyer']);

        return (int) DB::table('orders')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => (int) $customer->legacy_customer_id,
            'b2c_customer_id' => (int) $customer->id,
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

    private function wholesaleOrder(int $storeId): int
    {
        $customer = Customer::query()->create(['name' => 'V2 P214 Wholesale Buyer', 'type' => 'b2b']);

        return (int) DB::table('orders')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => $customer->getKey(),
            'order_number' => 'V2-P214-WHOLESALE-'.uniqid(),
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
}
