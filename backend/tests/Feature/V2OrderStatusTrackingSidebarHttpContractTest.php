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
 * V2 #2545 / P215: status tabs and exact-order timeline are authorized
 * surfaces within canonical Orders; Sidebar metadata never grants access.
 */
final class V2OrderStatusTrackingSidebarHttpContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authorized_status_tabs_and_record_timeline_are_discoverable_in_arabic_and_english(): void
    {
        $store = $this->retailStore('P215-LOCALE');
        $actor = $this->retailAdmin($store, 'p215-locale@example.test');
        $order = $this->retailOrder($store, 'P215-LOCALE-ORDER', 'delivered');
        $this->history($order, $store, 'pending', 'delivered');

        $this->assertTrue(Route::has('admin.operations.orders.index'));
        $this->assertTrue(Route::has('admin.operations.orders.popup'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $nav = app(AdminNavigation::class)->groupsFor($actor->fresh());
            $operations = collect($nav)->firstWhere('key', 'operations');
            $this->assertIsArray($operations);
            $links = collect($operations['children'])->where('key', 'order_operations')->values();
            $this->assertCount(1, $links);
            $this->assertSame('admin.operations.orders.index', $links->first()['route']);

            $timelineUrl = route('admin.operations.orders.popup', [
                'order' => $order,
                'kind' => 'timeline',
            ]);

            $this->actingAs($actor->fresh())
                ->get(route('admin.operations.orders.index', ['channel' => 'b2c', 'status' => 'delivered']))
                ->assertOk()
                ->assertSee('lang="'.$locale.'"', false)
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-order-status-tabs', false)
                ->assertSee('data-order-status-selected="delivered"', false)
                ->assertSee('data-order-timeline-icon data-order-popup', false)
                ->assertSee('data-order-popup-url="'.$timelineUrl.'"', false)
                ->assertViewHas('rows', static fn (array $rows): bool => collect($rows)
                    ->contains(static fn (array $row): bool => $row['id'] === $order));

            $this->actingAs($actor->fresh())
                ->get($timelineUrl)
                ->assertOk()
                ->assertViewIs('admin._order-operation-popup')
                ->assertViewHas('timeline', static fn (array $events): bool => collect($events)
                    ->contains(static fn (array $event): bool => $event['kind'] === 'status'))
                ->assertSee('data-order-popup-content="timeline"', false)
                ->assertSee('data-kind="status"', false);
        }
    }

    public function test_status_filter_preserves_store_boundary_and_never_leaks_unmatched_orders(): void
    {
        $store = $this->retailStore('P215-FILTER');
        $actor = $this->retailAdmin($store, 'p215-filter@example.test');
        $delivered = $this->retailOrder($store, 'P215-FILTER-DELIVERED', 'delivered');
        $pending = $this->retailOrder($store, 'P215-FILTER-PENDING', 'pending');
        $foreignStore = $this->retailStore('P215-FILTER-OTHER');
        $foreign = $this->retailOrder($foreignStore, 'P215-FILTER-FOREIGN', 'delivered');

        $this->actingAs($actor)
            ->get(route('admin.operations.orders.index', [
                'channel' => 'b2c',
                'store_id' => $store,
                'status' => 'delivered',
                'action_required' => 0,
            ]))
            ->assertOk()
            ->assertViewHas('selectedStatus', 'delivered')
            ->assertViewHas('selectedStoreId', $store)
            ->assertViewHas('rows', static function (array $rows) use ($delivered, $pending, $foreign): bool {
                $ids = collect($rows)->pluck('id')->all();

                return in_array($delivered, $ids, true)
                    && ! in_array($pending, $ids, true)
                    && ! in_array($foreign, $ids, true);
            });
    }

    public function test_cross_store_and_cross_channel_status_tracking_fail_closed(): void
    {
        $own = $this->retailStore('P215-OWN');
        $foreign = $this->retailStore('P215-FOREIGN');
        $actor = $this->retailAdmin($own, 'p215-scoped@example.test');
        $order = $this->retailOrder($foreign, 'P215-FOREIGN-ORDER', 'pending');
        $wholesaleOrder = $this->wholesaleOrder(app(WholesalePrincipal::class)->storeId());

        $this->actingAs($actor)
            ->get(route('admin.operations.orders.index', [
                'channel' => 'b2c',
                'store_id' => $foreign,
                'status' => 'pending',
            ]))
            ->assertNotFound();

        $this->actingAs($actor)
            ->get(route('admin.operations.orders.popup', ['order' => $order, 'kind' => 'timeline']))
            ->assertNotFound();

        $this->actingAs($actor)
            ->get(route('admin.operations.orders.index', ['channel' => 'b2b']))
            ->assertForbidden();

        $this->actingAs($actor)
            ->get(route('admin.operations.orders.popup', ['order' => $wholesaleOrder, 'kind' => 'timeline']))
            ->assertNotFound();
    }

    public function test_explicit_order_view_deny_removes_navigation_and_blocks_direct_timeline(): void
    {
        $store = $this->retailStore('P215-DENY');
        $actor = $this->retailAdmin($store, 'p215-deny@example.test');
        $order = $this->retailOrder($store, 'P215-DENY-ORDER', 'pending');
        $this->deny($actor, 'orders.view');

        $links = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->pluck('key')
            ->all();
        $this->assertNotContains('order_operations', $links);

        $this->actingAs($actor->fresh())
            ->get(route('admin.operations.orders.index', ['channel' => 'b2c']))
            ->assertForbidden();

        $this->actingAs($actor->fresh())
            ->get(route('admin.operations.orders.popup', ['order' => $order, 'kind' => 'timeline']))
            ->assertNotFound();
    }

    public function test_read_only_order_view_can_track_timeline_without_management_grant(): void
    {
        $store = $this->retailStore('P215-READ');
        $actor = $this->retailAdmin($store, 'p215-read@example.test');
        $order = $this->retailOrder($store, 'P215-READ-ORDER', 'pending');
        $this->deny($actor, 'orders.manage');
        $this->deny($actor, 'orders.dispatch');

        $this->actingAs($actor->fresh())
            ->get(route('admin.operations.orders.index', ['channel' => 'b2c']))
            ->assertOk()
            ->assertViewHas('rows', static function (array $rows) use ($order): bool {
                $row = collect($rows)->firstWhere('id', $order);

                return $row !== null
                    && $row['can_manage'] === false
                    && $row['can_dispatch_authorized'] === false;
            });

        $this->actingAs($actor->fresh())
            ->get(route('admin.operations.orders.popup', ['order' => $order, 'kind' => 'timeline']))
            ->assertOk();

        $this->actingAs($actor->fresh())
            ->post(route('admin.operations.orders.transition', $order), ['status' => 'cancelled'])
            ->assertNotFound();

        $this->assertDatabaseHas('orders', ['id' => $order, 'status' => 'pending']);
    }

    public function test_roleless_actor_cannot_browse_tracking_or_invent_sidebar_permission(): void
    {
        $store = $this->retailStore('P215-ROLELESS');
        $order = $this->retailOrder($store, 'P215-ROLELESS-ORDER', 'pending');
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        $links = collect(app(AdminNavigation::class)->groupsFor($actor))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->pluck('key')
            ->all();
        $this->assertNotContains('order_operations', $links);

        $this->actingAs($actor)
            ->get(route('admin.operations.orders.index'))
            ->assertForbidden();

        $this->actingAs($actor)
            ->get(route('admin.operations.orders.popup', ['order' => $order, 'kind' => 'timeline']))
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
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en', 'email' => $email]);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->getKey(),
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->getKey(),
            'store_id' => $storeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor->fresh();
    }

    private function retailOrder(int $storeId, string $number, string $status): int
    {
        $customer = app(B2cCustomerService::class)->create($storeId, ['name' => 'P215 Status Buyer']);

        return (int) DB::table('orders')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => (int) $customer->legacy_customer_id,
            'b2c_customer_id' => (int) $customer->id,
            'order_number' => $number,
            'channel' => 'b2c',
            'status' => $status,
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
        $customer = Customer::query()->create(['name' => 'P215 Wholesale Buyer', 'type' => 'b2b']);

        return (int) DB::table('orders')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => $customer->getKey(),
            'order_number' => 'P215-WHOLESALE-ORDER',
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

    private function history(int $order, int $store, string $from, string $to): void
    {
        DB::table('order_status_history')->insert([
            'order_id' => $order,
            'store_id' => $store,
            'user_id' => null,
            'from_status' => $from,
            'to_status' => $to,
            'note' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function deny(User $actor, string $permission): void
    {
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->getKey(),
            'permission_id' => Permission::query()->where('code', $permission)->firstOrFail()->getKey(),
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
    }
}
