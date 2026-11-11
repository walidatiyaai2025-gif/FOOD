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
 * V2 #2547 / P217: failure/refund triage stays in authorized Orders,
 * with a real scoped queue and no invented return/credit transition.
 */
final class V2OrderFailureReturnsSidebarHttpContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authorized_failure_refund_queue_is_discoverable_and_filters_exact_records_in_both_locales(): void
    {
        $store = $this->retailStore('P217-DISCOVER');
        $admin = $this->retailAdmin($store, 'p217-discover@example.test');
        $failed = $this->retailOrder($store, 'P217-DISCOVER-FAILED', 'failed');
        $refunded = $this->retailOrder($store, 'P217-DISCOVER-REFUNDED', 'refunded');
        $cancelled = $this->retailOrder($store, 'P217-DISCOVER-CANCELLED', 'cancelled');
        $delivered = $this->retailOrder($store, 'P217-DISCOVER-DELIVERED', 'delivered');

        $this->assertTrue(Route::has('admin.operations.orders.index'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $admin->forceFill(['locale' => $locale])->save();

            $operations = collect(app(AdminNavigation::class)->groupsFor($admin->fresh()))
                ->firstWhere('key', 'operations');
            $this->assertIsArray($operations);
            $ordersLinks = collect($operations['children'])
                ->where('key', 'order_operations')->values();
            $this->assertCount(1, $ordersLinks);
            $this->assertSame('admin.operations.orders.index', $ordersLinks->first()['route']);

            $this->actingAs($admin->fresh())
                ->get(route('admin.operations.orders.index', ['channel' => 'b2c']))
                ->assertOk()
                ->assertSee('lang="'.$locale.'"', false)
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-order-failure-returns-tab', false)
                ->assertSee(__('order_operations.dashboard.exceptions.failed_returns'))
                ->assertViewHas('dashboard', static fn (array $dashboard): bool => (int) (collect($dashboard['exceptions'] ?? [])
                    ->firstWhere('key', 'failed_returns')['count'] ?? -1) === 2);

            $this->actingAs($admin->fresh())
                ->get(route('admin.operations.orders.index', [
                    'channel' => 'b2c',
                    'store_id' => $store,
                    'exception' => 'failed_returns',
                    'action_required' => 0,
                ]))
                ->assertOk()
                ->assertViewHas('selectedException', 'failed_returns')
                ->assertViewHas('selectedStoreId', $store)
                ->assertSee('data-order-exception-selected="failed_returns"', false)
                ->assertViewHas('rows', static function (array $rows) use ($failed, $refunded, $cancelled, $delivered): bool {
                    $ids = collect($rows)->pluck('id')->all();

                    return in_array($failed, $ids, true)
                        && in_array($refunded, $ids, true)
                        && ! in_array($cancelled, $ids, true)
                        && ! in_array($delivered, $ids, true);
                });
        }
    }

    public function test_status_tabs_clear_failure_refund_exception_instead_of_silently_stacking_filters(): void
    {
        $store = $this->retailStore('P217-RESET');
        $admin = $this->retailAdmin($store, 'p217-reset@example.test');
        $this->retailOrder($store, 'P217-RESET-FAILED', 'failed');

        $response = $this->actingAs($admin)
            ->get(route('admin.operations.orders.index', [
                'channel' => 'b2c',
                'store_id' => $store,
                'exception' => 'failed_returns',
                'action_required' => 0,
            ]))
            ->assertOk()
            ->assertSee('data-order-failure-returns-tab', false);

        $html = (string) $response->getContent();
        $this->assertSame(
            1,
            preg_match('/<a class="status-tab"\s+data-order-status-tab="all"\s+href="([^"]+)"/', $html, $match),
        );
        $this->assertStringNotContainsString('exception=', $match[1]);
        $this->assertStringContainsString('store_id=', $match[1]);
        $this->assertStringContainsString('channel=b2c', $match[1]);

        $this->actingAs($admin)
            ->get(route('admin.operations.orders.index', [
                'channel' => 'b2c',
                'store_id' => $store,
                'action_required' => 0,
            ]))
            ->assertOk()
            ->assertViewHas('selectedException', null);
    }

    public function test_failure_refund_queue_never_reads_foreign_store_or_wholesale_orders(): void
    {
        $own = $this->retailStore('P217-OWN');
        $foreign = $this->retailStore('P217-OTHER');
        $admin = $this->retailAdmin($own, 'p217-owner@example.test');
        $mine = $this->retailOrder($own, 'P217-OWN-FAILED', 'failed');
        $foreignOrder = $this->retailOrder($foreign, 'P217-OTHER-FAILED', 'failed');
        $wholesale = $this->wholesaleOrder('P217-WHOLESALE-REFUNDED', 'refunded');

        $this->actingAs($admin)
            ->get(route('admin.operations.orders.index', ['channel' => 'b2c', 'exception' => 'failed_returns']))
            ->assertOk()
            ->assertViewHas('rows', static function (array $rows) use ($mine, $foreignOrder, $wholesale): bool {
                $ids = collect($rows)->pluck('id')->all();

                return in_array($mine, $ids, true)
                    && ! in_array($foreignOrder, $ids, true)
                    && ! in_array($wholesale, $ids, true);
            });

        $this->actingAs($admin)
            ->get(route('admin.operations.orders.index', [
                'channel' => 'b2c', 'store_id' => $foreign, 'exception' => 'failed_returns',
            ]))
            ->assertNotFound();

        $this->actingAs($admin)
            ->get(route('admin.operations.orders.index', [
                'channel' => 'b2b', 'exception' => 'failed_returns',
            ]))
            ->assertForbidden();

        $this->actingAs($admin)
            ->get(route('admin.operations.orders.popup', ['order' => $foreignOrder, 'kind' => 'timeline']))
            ->assertNotFound();
    }

    public function test_wholesale_and_retail_channels_remain_separate_for_super_admin(): void
    {
        $retail = $this->retailStore('P217-CHANNEL-RETAIL');
        $retailOrder = $this->retailOrder($retail, 'P217-CHANNEL-RETAIL-FAILED', 'failed');
        $b2bOrder = $this->wholesaleOrder('P217-CHANNEL-WHOLESALE-REFUNDED', 'refunded');
        $admin = $this->superAdmin();

        foreach ([
            'b2b' => [$b2bOrder, $retailOrder],
            'b2c' => [$retailOrder, $b2bOrder],
        ] as $channel => [$expected, $forbidden]) {
            $this->actingAs($admin)
                ->get(route('admin.operations.orders.index', [
                    'channel' => $channel, 'exception' => 'failed_returns',
                ]))
                ->assertOk()
                ->assertViewHas('rows', static function (array $rows) use ($expected, $forbidden): bool {
                    $ids = collect($rows)->pluck('id')->all();

                    return in_array($expected, $ids, true)
                        && ! in_array($forbidden, $ids, true);
                });
        }
    }

    public function test_refunded_orders_are_terminal_but_failed_orders_remain_eligible_for_dispatch_review(): void
    {
        $store = $this->retailStore('P217-TERMINAL');
        $admin = $this->retailAdmin($store, 'p217-terminal@example.test');
        $failed = $this->retailOrder($store, 'P217-FAILED-REVIEW', 'failed');
        $refunded = $this->retailOrder($store, 'P217-REFUNDED-TERMINAL', 'refunded');

        foreach ([$failed, $refunded] as $id) {
            DB::table('order_dispatch_states')->insert([
                'order_id' => $id,
                'status' => 'awaiting_dispatch',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->actingAs($admin)
            ->get(route('admin.operations.orders.index', ['channel' => 'b2c']))
            ->assertOk()
            ->assertViewHas('actionRequiredCount', 1)
            ->assertViewHas('dashboard', static fn (array $dashboard): bool => (int) ($dashboard['kpis']['awaiting_dispatch'] ?? -1) === 1);

        $this->actingAs($admin)
            ->get(route('admin.operations.orders.index', [
                'channel' => 'b2c', 'action_required' => 1,
            ]))
            ->assertOk()
            ->assertViewHas('rows', static function (array $rows) use ($failed, $refunded): bool {
                $ids = collect($rows)->pluck('id')->all();

                return in_array($failed, $ids, true)
                    && ! in_array($refunded, $ids, true);
            });

        $this->actingAs($admin)
            ->get(route('admin.operations.orders.index', [
                'channel' => 'b2c', 'exception' => 'failed_returns',
            ]))
            ->assertOk()
            ->assertViewHas('rows', static function (array $rows) use ($refunded): bool {
                $row = collect($rows)->firstWhere('id', $refunded);

                return $row !== null && $row['available_statuses'] === [];
            });
    }

    public function test_explicit_view_deny_hides_navigation_and_fails_closed_on_direct_queue(): void
    {
        $store = $this->retailStore('P217-DENY');
        $admin = $this->retailAdmin($store, 'p217-deny@example.test');
        $order = $this->retailOrder($store, 'P217-DENY-FAILED', 'failed');
        $this->deny($admin, 'orders.view');

        $keys = collect(app(AdminNavigation::class)->groupsFor($admin->fresh()))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->pluck('key')->all();
        $this->assertNotContains('order_operations', $keys);

        $this->actingAs($admin->fresh())
            ->get(route('admin.operations.orders.index', ['channel' => 'b2c', 'exception' => 'failed_returns']))
            ->assertForbidden();

        $this->actingAs($admin->fresh())
            ->get(route('admin.operations.orders.popup', ['order' => $order, 'kind' => 'timeline']))
            ->assertNotFound();
    }

    public function test_view_only_actor_can_reconcile_failure_without_upgrading_to_manage_permission(): void
    {
        $store = $this->retailStore('P217-READONLY');
        $admin = $this->retailAdmin($store, 'p217-readonly@example.test');
        $order = $this->retailOrder($store, 'P217-READONLY-FAILED', 'failed');
        $this->deny($admin, 'orders.manage');
        $this->deny($admin, 'orders.dispatch');

        $this->actingAs($admin->fresh())
            ->get(route('admin.operations.orders.index', [
                'channel' => 'b2c', 'exception' => 'failed_returns',
            ]))
            ->assertOk()
            ->assertViewHas('rows', static function (array $rows) use ($order): bool {
                $row = collect($rows)->firstWhere('id', $order);

                return $row !== null
                    && $row['can_manage'] === false
                    && $row['can_dispatch_authorized'] === false;
            });

        $this->actingAs($admin->fresh())
            ->post(route('admin.operations.orders.transition', $order), ['status' => 'cancelled'])
            ->assertNotFound();

        $this->assertDatabaseHas('orders', ['id' => $order, 'status' => 'failed']);
    }

    public function test_roleless_and_disabled_actors_never_acquire_failure_refund_queue(): void
    {
        $store = $this->retailStore('P217-ROLELESS');
        $actor = User::factory()->create(['is_active' => true]);

        $keys = collect(app(AdminNavigation::class)->groupsFor($actor))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->pluck('key')->all();
        $this->assertNotContains('order_operations', $keys);

        $this->actingAs($actor)
            ->get(route('admin.operations.orders.index', ['exception' => 'failed_returns']))
            ->assertForbidden();

        $admin = $this->retailAdmin($store, 'p217-disabled@example.test');
        $admin->forceFill(['is_active' => false])->save();

        $response = $this->actingAs($admin->fresh())
            ->get(route('admin.operations.orders.index', [
                'channel' => 'b2c', 'exception' => 'failed_returns',
            ]));
        $this->assertContains($response->getStatusCode(), [302, 401, 403, 404]);
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

    private function retailAdmin(int $store, string $email): User
    {
        $user = User::factory()->create(['is_active' => true, 'email' => $email, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->getKey(),
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->getKey(),
            'store_id' => $store,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user->fresh();
    }

    private function retailOrder(int $store, string $number, string $status): int
    {
        $customer = app(B2cCustomerService::class)->create($store, ['name' => 'P217 Buyer '.$number]);

        return (int) DB::table('orders')->insertGetId([
            'store_id' => $store,
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

    private function wholesaleOrder(string $number, string $status): int
    {
        $store = app(WholesalePrincipal::class)->storeId();
        $customer = Customer::query()->create(['name' => 'P217 Wholesale Buyer', 'type' => 'b2b']);

        return (int) DB::table('orders')->insertGetId([
            'store_id' => $store,
            'customer_id' => $customer->getKey(),
            'order_number' => $number,
            'channel' => 'b2b',
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

    private function superAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }

    private function deny(User $user, string $code): void
    {
        DB::table('user_permission_overrides')->insert([
            'user_id' => $user->getKey(),
            'permission_id' => Permission::query()->where('code', $code)->firstOrFail()->getKey(),
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user->forceFill(['has_permission_overrides' => true])->save();
    }
}
