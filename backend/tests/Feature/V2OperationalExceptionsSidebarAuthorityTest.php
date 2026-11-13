<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserStoreRole;
use App\Services\B2cCustomerService;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2OperationalExceptionsSidebarAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_canonical_orders_exception_dashboard_is_discoverable_in_both_languages(): void
    {
        $actor = $this->globalAdmin();
        $store = $this->retailStore('V2-P211-NAV');
        $customer = app(B2cCustomerService::class)->create($store, ['name' => 'P211 Navigation Buyer']);
        $delayed = $this->order($store, (int) $customer->legacy_customer_id, (int) $customer->id, 'P211-DELAYED-NAV', 'pending', now()->subDays(2));

        $this->assertContains('order_operations', $this->navigationKeys($actor));

        foreach (['ar' => ['rtl', 'الاستثناءات التشغيلية'], 'en' => ['ltr', 'Operational exceptions']] as $locale => [$direction, $label]) {
            $actor->forceFill(['locale' => $locale])->save();

            $this->actingAs($actor->fresh())
                ->get(route('admin.operations.orders.index', ['channel' => 'b2c', 'store_id' => $store]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee($label)
                ->assertSee('data-sales-exception="delayed"', false)
                ->assertSee('name="exception"', false);

            $this->actingAs($actor->fresh())
                ->get(route('admin.operations.orders.index', [
                    'channel' => 'b2c',
                    'store_id' => $store,
                    'exception' => 'delayed',
                ]))
                ->assertOk()
                ->assertViewHas('rows', fn (array $rows): bool => count($rows) === 1
                    && (int) $rows[0]['id'] === $delayed);
        }
    }

    public function test_all_operational_exception_filters_return_only_eligible_authorized_orders(): void
    {
        $actor = $this->globalAdmin();
        $store = $this->retailStore('V2-P211-OWN');
        $otherStore = $this->retailStore('V2-P211-OTHER');
        $customer = app(B2cCustomerService::class)->create($store, ['name' => 'P211 Buyer']);
        $otherCustomer = app(B2cCustomerService::class)->create($otherStore, ['name' => 'Foreign P211 Buyer']);

        $legacy = (int) $customer->legacy_customer_id;
        $retail = (int) $customer->id;
        $delayed = $this->order($store, $legacy, $retail, 'P211-DELAYED', 'pending', now()->subDays(2));
        $awaiting = $this->order($store, $legacy, $retail, 'P211-AWAITING', 'confirmed', now()->subHour());
        $payment = $this->order($store, $legacy, $retail, 'P211-PAYMENT', 'pending', now()->subHour());
        $invoice = $this->order($store, $legacy, $retail, 'P211-INVOICE', 'delivered', now()->subHour());
        $cancelled = $this->order($store, $legacy, $retail, 'P211-CANCELLED', 'cancelled', now()->subHour());
        $refunded = $this->order($store, $legacy, $retail, 'P211-REFUNDED', 'refunded', now()->subHour());
        $failed = $this->order($store, $legacy, $retail, 'P211-FAILED', 'failed', now()->subHour());
        $deliveredNoDispatch = $this->order($store, $legacy, $retail, 'P211-TERMINAL', 'delivered', now()->subHour());
        $foreign = $this->order(
            $otherStore,
            (int) $otherCustomer->legacy_customer_id,
            (int) $otherCustomer->id,
            'P211-FOREIGN-DELAYED',
            'pending',
            now()->subDays(3),
        );

        DB::table('order_dispatch_states')->insert([
            ['order_id' => $awaiting, 'status' => 'awaiting_dispatch', 'created_at' => now(), 'updated_at' => now()],
            ['order_id' => $deliveredNoDispatch, 'status' => 'awaiting_dispatch', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('payments')->insert([
            'order_id' => $payment,
            'invoice_id' => null,
            'provider' => 'TEST',
            'provider_reference' => 'P211-FAILED',
            'status' => 'failed',
            'amount' => 10,
            'currency' => 'KWD',
            'metadata' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $expected = [
            'delayed' => [$delayed],
            'awaiting_dispatch' => [$awaiting],
            'payment' => [$payment],
            'invoice' => [$invoice, $deliveredNoDispatch],
            'cancelled_refunded' => [$cancelled, $refunded],
            'failed_returns' => [$failed, $refunded],
        ];

        foreach ($expected as $exception => $ids) {
            $this->actingAs($actor)
                ->get(route('admin.operations.orders.index', [
                    'channel' => 'b2c',
                    'store_id' => $store,
                    'exception' => $exception,
                    'action_required' => 0,
                ]))
                ->assertOk()
                ->assertViewHas('rows', static function (array $rows) use ($ids, $foreign): bool {
                    $actual = array_map(static fn (array $row): int => (int) $row['id'], $rows);
                    sort($actual);
                    sort($ids);

                    return $actual === $ids && ! in_array($foreign, $actual, true);
                });
        }

        $this->actingAs($actor)
            ->getJson(route('admin.operations.orders.index', [
                'channel' => 'b2c',
                'store_id' => $store,
                'exception' => 'not-a-real-exception',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('exception');
    }

    public function test_store_scoped_operator_cannot_read_foreign_exception_or_elevate_with_direct_url(): void
    {
        $ownStore = $this->retailStore('V2-P211-STORE');
        $otherStore = $this->retailStore('V2-P211-FORBIDDEN');
        $buyer = app(B2cCustomerService::class)->create($ownStore, ['name' => 'Own P211 Buyer']);
        $otherBuyer = app(B2cCustomerService::class)->create($otherStore, ['name' => 'Other P211 Buyer']);
        $own = $this->order($ownStore, (int) $buyer->legacy_customer_id, (int) $buyer->id, 'P211-SCOPED-ORDER', 'pending', now()->subDays(2));
        $foreign = $this->order($otherStore, (int) $otherBuyer->legacy_customer_id, (int) $otherBuyer->id, 'P211-FOREIGN-ORDER', 'pending', now()->subDays(2));
        $operator = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        UserStoreRole::query()->create([
            'user_id' => $operator->id,
            'store_id' => $ownStore,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
        ]);

        $this->actingAs($operator)
            ->get(route('admin.operations.orders.index', [
                'channel' => 'b2c',
                'store_id' => $ownStore,
                'exception' => 'delayed',
            ]))
            ->assertOk()
            ->assertViewHas('rows', fn (array $rows): bool => count($rows) === 1
                && (int) $rows[0]['id'] === $own);

        $denied = $this->actingAs($operator)->get(route('admin.operations.orders.index', [
            'channel' => 'b2c',
            'store_id' => $otherStore,
            'exception' => 'delayed',
        ]));
        $this->assertContains($denied->getStatusCode(), [403, 404]);
        $denied->assertDontSee('P211-FOREIGN-ORDER');

        $this->actingAs($operator)
            ->get(route('admin.operations.orders.index', [
                'channel' => 'b2b',
                'exception' => 'delayed',
            ]))
            ->assertForbidden();

        $this->assertDatabaseHas('orders', ['id' => $foreign, 'status' => 'pending']);
        $this->assertDatabaseHas('orders', ['id' => $own, 'status' => 'pending']);
    }

    public function test_permissionless_and_explicitly_denied_users_cannot_open_exceptions(): void
    {
        $store = $this->retailStore('V2-P211-DENY');
        $unassigned = User::factory()->create(['locale' => 'en', 'is_active' => true]);

        $this->assertNotContains('order_operations', $this->navigationKeys($unassigned));
        $this->actingAs($unassigned)
            ->get(route('admin.operations.orders.index', [
                'channel' => 'b2c',
                'store_id' => $store,
                'exception' => 'awaiting_dispatch',
            ]))
            ->assertForbidden();

        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        $operator = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        UserStoreRole::query()->create([
            'user_id' => $operator->id,
            'store_id' => $store,
            'role_id' => $role->id,
        ]);

        DB::table('user_permission_overrides')->insert([
            'user_id' => $operator->id,
            'permission_id' => Permission::query()->where('code', 'orders.view')->firstOrFail()->id,
            'scope_key' => 'store:'.$store,
            'store_id' => $store,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $operator->forceFill(['has_permission_overrides' => true])->save();

        $this->actingAs($operator->fresh())
            ->get(route('admin.operations.orders.index', [
                'channel' => 'b2c',
                'store_id' => $store,
                'exception' => 'payment',
            ]))
            ->assertForbidden();
    }

    public function test_exception_selector_remains_accessible_through_responsive_advanced_filters(): void
    {
        $actor = $this->globalAdmin();
        $store = $this->retailStore('V2-P211-MOBILE');

        $response = $this->actingAs($actor)
            ->get(route('admin.operations.orders.index', ['channel' => 'b2c', 'store_id' => $store]));

        $response->assertOk()
            ->assertSee('class="order-grid-filter-more"', false)
            ->assertSee('class="order-grid-filter-popover"', false)
            ->assertSee('name="exception"', false);

        $html = $response->getContent();
        $this->assertSame(1, preg_match(
            '/<details class="order-grid-filter-more">.*?<div class="order-grid-filter-popover">.*?<select name="exception"/s',
            $html,
        ));
        $this->assertSame(1, substr_count($html, '<select name="exception"'));
        $this->assertStringNotContainsString('select[name="exception"]{display:none}', $html);
    }

    /** @return list<string> */
    private function navigationKeys(User $actor): array
    {
        $keys = [];
        foreach (app(AdminNavigation::class)->groupsFor($actor) as $group) {
            foreach ((array) ($group['children'] ?? []) as $item) {
                $this->appendNavigationKeys($keys, $item);
            }
        }

        return $keys;
    }

    /** @param list<string> $keys */
    private function appendNavigationKeys(array &$keys, array $item): void
    {
        $keys[] = (string) $item['key'];
        foreach ((array) ($item['children'] ?? []) as $child) {
            $this->appendNavigationKeys($keys, $child);
        }
    }

    private function globalAdmin(): User
    {
        $user = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail()->id);

        return $user;
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

    private function order(int $storeId, int $legacyCustomerId, int $b2cCustomerId, string $number, string $status, mixed $createdAt): int
    {
        return (int) DB::table('orders')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => $legacyCustomerId,
            'b2c_customer_id' => $b2cCustomerId,
            'order_number' => $number,
            'channel' => 'b2c',
            'status' => $status,
            'currency' => 'KWD',
            'subtotal' => 10,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 10,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
