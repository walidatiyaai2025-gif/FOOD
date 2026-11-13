<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserStoreRole;
use App\Services\B2cCustomerService;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 P210: the existing action-required Orders tab is the server-authorized
 * awaiting_dispatch queue. Naming must not introduce parallel navigation or
 * unscoped data access.
 */
final class V2AwaitingDispatchQueueAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authorized_awaiting_dispatch_tab_is_localized_and_contains_exact_nonterminal_rows(): void
    {
        $actor = $this->superAdmin();
        $store = $this->retailStore('P210-OWN');
        $foreignStore = $this->retailStore('P210-FOREIGN');
        $customer = app(B2cCustomerService::class)->create($store, ['name' => 'P210 Buyer']);
        $foreignCustomer = app(B2cCustomerService::class)->create($foreignStore, ['name' => 'P210 Other Buyer']);

        $awaiting = $this->retailOrder($store, (int) $customer->legacy_customer_id, (int) $customer->id, 'P210-AWAITING', 'pending');
        $assigned = $this->retailOrder($store, (int) $customer->legacy_customer_id, (int) $customer->id, 'P210-ASSIGNED', 'confirmed');
        $terminal = $this->retailOrder($store, (int) $customer->legacy_customer_id, (int) $customer->id, 'P210-DELIVERED', 'delivered');
        $foreign = $this->retailOrder($foreignStore, (int) $foreignCustomer->legacy_customer_id, (int) $foreignCustomer->id, 'P210-FOREIGN-AWAITING', 'pending');
        $this->dispatch($awaiting, 'awaiting_dispatch');
        $this->dispatch($assigned, 'assigned');
        $this->dispatch($terminal, 'awaiting_dispatch');
        $this->dispatch($foreign, 'awaiting_dispatch');

        $this->assertContains('order_operations', $this->navigationKeys($actor));

        foreach (['ar' => ['rtl', 'بانتظار التوجيه'], 'en' => ['ltr', 'Awaiting dispatch']] as $locale => [$direction, $label]) {
            $actor->forceFill(['locale' => $locale])->save();

            $response = $this->actingAs($actor->fresh())->get(route('admin.operations.orders.index', [
                'channel' => 'b2c', 'store_id' => $store, 'action_required' => 1,
            ]));
            $response->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee($label)
                ->assertSee('data-order-action-tab', false)
                ->assertSee('data-order-awaiting-dispatch-queue="P210"', false)
                ->assertViewHas('selectedActionRequired', true)
                ->assertViewHas('actionRequiredCount', 1)
                ->assertViewHas('rows', static fn (array $rows): bool => count($rows) === 1
                    && (int) $rows[0]['id'] === $awaiting
                    && $rows[0]['channel'] === 'b2c');

            $html = $response->getContent();
            $this->assertSame(1, substr_count($html, 'data-order-awaiting-dispatch-queue="P210"'));
            $this->assertSame(1, preg_match(
                '/<a class="status-tab"[^>]*data-order-action-tab[^>]*data-order-awaiting-dispatch-queue="P210"[^>]*aria-current="page"/s',
                $html,
            ));
            $this->assertSame(1, preg_match(
                '/<a class="status-tab"[^>]*data-order-awaiting-dispatch-queue="P210"[^>]*aria-label="'.preg_quote($label, '/').'"/s',
                $html,
            ));
        }
    }

    public function test_wholesale_queue_is_van_only_and_can_never_mix_retail_or_terminal_records(): void
    {
        $actor = $this->superAdmin();
        $wholesale = app(WholesalePrincipal::class)->storeId();
        $retail = $this->retailStore('P210-B2C');
        $customer = (int) DB::table('customers')->insertGetId([
            'type' => 'b2b',
            'name' => 'P210 B2B Buyer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $b2bCustomer = (int) DB::table('b2b_customers')->insertGetId([
            'legacy_customer_id' => $customer,
            'name' => 'P210 B2B Buyer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $awaiting = $this->wholesaleOrder($wholesale, $customer, $b2bCustomer, 'P210-B2B-AWAITING', 'pending');
        $delivered = $this->wholesaleOrder($wholesale, $customer, $b2bCustomer, 'P210-B2B-DELIVERED', 'delivered');
        $this->dispatch($awaiting, 'awaiting_dispatch');
        $this->dispatch($delivered, 'awaiting_dispatch');

        $retailBuyer = app(B2cCustomerService::class)->create($retail, ['name' => 'P210 Retail Buyer']);
        $retailOrder = $this->retailOrder($retail, (int) $retailBuyer->legacy_customer_id, (int) $retailBuyer->id, 'P210-B2C-AWAITING', 'pending');
        $this->dispatch($retailOrder, 'awaiting_dispatch');

        $this->actingAs($actor)->get(route('admin.operations.orders.index', [
            'channel' => 'b2b', 'store_id' => $wholesale, 'action_required' => 1,
        ]))->assertOk()
            ->assertViewHas('actionRequiredCount', 1)
            ->assertViewHas('rows', static fn (array $rows): bool => count($rows) === 1
                && (int) $rows[0]['id'] === $awaiting
                && $rows[0]['channel'] === 'b2b');

        $this->actingAs($actor)->get(route('admin.operations.orders.index', [
            'channel' => 'all', 'action_required' => 1,
        ]))->assertForbidden();

        $badStore = $this->actingAs($actor)->get(route('admin.operations.orders.index', [
            'channel' => 'b2b', 'store_id' => $retail, 'action_required' => 1,
        ]));
        $this->assertContains($badStore->getStatusCode(), [403, 404]);
    }

    public function test_store_scoped_operator_can_see_only_own_queue_and_disabled_or_denied_actor_cannot(): void
    {
        $store = $this->retailStore('P210-STORE');
        $other = $this->retailStore('P210-OTHER');
        $buyer = app(B2cCustomerService::class)->create($store, ['name' => 'P210 Own Buyer']);
        $foreignBuyer = app(B2cCustomerService::class)->create($other, ['name' => 'P210 Foreign Buyer']);
        $ownOrder = $this->retailOrder($store, (int) $buyer->legacy_customer_id, (int) $buyer->id, 'P210-OWN-QUEUED', 'pending');
        $foreignOrder = $this->retailOrder($other, (int) $foreignBuyer->legacy_customer_id, (int) $foreignBuyer->id, 'P210-OTHER-QUEUED', 'pending');
        $this->dispatch($ownOrder, 'awaiting_dispatch');
        $this->dispatch($foreignOrder, 'awaiting_dispatch');

        $operator = $this->retailOperator($store);
        $this->actingAs($operator)->get(route('admin.operations.orders.index', [
            'channel' => 'b2c', 'store_id' => $store, 'action_required' => 1,
        ]))->assertOk()
            ->assertViewHas('rows', static fn (array $rows): bool => count($rows) === 1
                && (int) $rows[0]['id'] === $ownOrder);

        $denied = $this->actingAs($operator)->get(route('admin.operations.orders.index', [
            'channel' => 'b2c', 'store_id' => $other, 'action_required' => 1,
        ]));
        $this->assertContains($denied->getStatusCode(), [403, 404]);
        $denied->assertDontSee('P210-OTHER-QUEUED');

        $this->actingAs($operator)->get(route('admin.operations.orders.index', [
            'channel' => 'b2b', 'action_required' => 1,
        ]))->assertForbidden();

        $operator->forceFill(['is_active' => false])->save();
        $this->actingAs($operator->fresh())->get(route('admin.operations.orders.index', [
            'channel' => 'b2c', 'store_id' => $store, 'action_required' => 1,
        ]))->assertForbidden();

        $unassigned = User::factory()->create(['is_active' => true]);
        $this->assertNotContains('order_operations', $this->navigationKeys($unassigned));
        $this->actingAs($unassigned)->get(route('admin.operations.orders.index', [
            'channel' => 'b2c', 'store_id' => $store, 'action_required' => 1,
        ]))->assertForbidden();

        $deniedOperator = $this->retailOperator($store);
        DB::table('user_permission_overrides')->insert([
            'user_id' => $deniedOperator->id,
            'permission_id' => Permission::query()->where('code', 'orders.view')->firstOrFail()->id,
            'scope_key' => 'store:'.$store,
            'store_id' => $store,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $deniedOperator->forceFill(['has_permission_overrides' => true])->save();
        $this->actingAs($deniedOperator->fresh())->get(route('admin.operations.orders.index', [
            'channel' => 'b2c', 'store_id' => $store, 'action_required' => 1,
        ]))->assertForbidden();

        $this->assertDatabaseHas('orders', ['id' => $ownOrder, 'status' => 'pending']);
        $this->assertDatabaseHas('orders', ['id' => $foreignOrder, 'status' => 'pending']);
    }

    public function test_queue_is_server_paginated_and_filter_is_strictly_validated(): void
    {
        $actor = $this->superAdmin();
        $store = $this->retailStore('P210-PAGE');
        $buyer = app(B2cCustomerService::class)->create($store, ['name' => 'P210 Paged Buyer']);

        for ($i = 1; $i <= 12; $i++) {
            $order = $this->retailOrder($store, (int) $buyer->legacy_customer_id, (int) $buyer->id, 'P210-PAGE-'.$i, 'pending');
            $this->dispatch($order, 'awaiting_dispatch');
        }

        $this->actingAs($actor)->get(route('admin.operations.orders.index', [
            'channel' => 'b2c',
            'store_id' => $store,
            'action_required' => 1,
            'per_page' => 5,
            'page' => 3,
        ]))->assertOk()
            ->assertViewHas('orders', static function ($paginator) use ($store): bool {
                if (! $paginator instanceof LengthAwarePaginator) {
                    return false;
                }

                $pageUrl = $paginator->url(3);

                return $paginator->total() === 12
                    && $paginator->perPage() === 5
                    && $paginator->currentPage() === 3
                    && str_contains($pageUrl, 'action_required=1')
                    && str_contains($pageUrl, 'channel=b2c')
                    && str_contains($pageUrl, 'store_id='.$store);
            })
            ->assertViewHas('rows', static fn (array $rows): bool => count($rows) === 2);

        $this->actingAs($actor)->getJson(route('admin.operations.orders.index', [
            'channel' => 'b2c',
            'store_id' => $store,
            'action_required' => 'not-a-boolean',
        ]))->assertUnprocessable()->assertJsonValidationErrors('action_required');
    }

    private function dispatch(int $orderId, string $status): void
    {
        DB::table('order_dispatch_states')->insert([
            'order_id' => $orderId,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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

    private function retailOperator(int $store): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        UserStoreRole::query()->create([
            'user_id' => $actor->id,
            'store_id' => $store,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
        ]);

        return $actor;
    }

    private function superAdmin(): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail()->id);

        return $actor;
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

    private function retailOrder(int $store, int $legacy, int $customer, string $number, string $status): int
    {
        return $this->insertOrder($store, $legacy, 'b2c', $number, $status, ['b2c_customer_id' => $customer]);
    }

    private function wholesaleOrder(int $store, int $legacy, int $customer, string $number, string $status): int
    {
        return $this->insertOrder($store, $legacy, 'b2b', $number, $status, ['b2b_customer_id' => $customer]);
    }

    /** @param array<string, int> $extra */
    private function insertOrder(int $store, int $legacy, string $channel, string $number, string $status, array $extra): int
    {
        return (int) DB::table('orders')->insertGetId([
            'store_id' => $store,
            'customer_id' => $legacy,
            'order_number' => $number,
            'channel' => $channel,
            'status' => $status,
            'currency' => 'KWD',
            'subtotal' => 10,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 10,
            'created_at' => now(),
            'updated_at' => now(),
            ...$extra,
        ]);
    }
}
