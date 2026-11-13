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
 * V2 P209: Pending orders are a real, authorized queue inside the canonical
 * Operations > Orders page, not a parallel Sidebar permission or dead route.
 */
final class V2PendingOrdersQueueAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_pending_queue_is_discoverable_and_exact_in_arabic_and_english(): void
    {
        $actor = $this->superAdmin();
        $storeId = $this->retailStore('P209-NAV');
        $foreignStore = $this->retailStore('P209-FOREIGN-NAV');
        $buyer = app(B2cCustomerService::class)->create($storeId, ['name' => 'P209 Buyer']);
        $foreignBuyer = app(B2cCustomerService::class)->create($foreignStore, ['name' => 'P209 Foreign Buyer']);

        $pending = $this->order($storeId, (int) $buyer->legacy_customer_id, (int) $buyer->id, 'P209-PENDING', 'pending');
        $this->order($storeId, (int) $buyer->legacy_customer_id, (int) $buyer->id, 'P209-CONFIRMED', 'confirmed');
        $this->order($foreignStore, (int) $foreignBuyer->legacy_customer_id, (int) $foreignBuyer->id, 'P209-FOREIGN', 'pending');

        $this->assertContains('order_operations', $this->navigationKeys($actor));

        foreach (['ar' => ['rtl', 'الطلبات المعلقة'], 'en' => ['ltr', 'Pending orders']] as $locale => [$direction, $label]) {
            $actor->forceFill(['locale' => $locale])->save();

            $url = route('admin.operations.orders.index', [
                'channel' => 'b2c',
                'store_id' => $storeId,
                'status' => 'pending',
                'action_required' => 0,
            ]);
            $response = $this->actingAs($actor->fresh())->get($url);
            $response->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee($label)
                ->assertSee('data-order-pending-queue="P209"', false)
                ->assertSee('data-order-status-tab="pending"', false)
                ->assertViewHas('selectedStatus', 'pending')
                ->assertViewHas('selectedActionRequired', false)
                ->assertViewHas('rows', static fn (array $rows): bool => count($rows) === 1
                    && (int) $rows[0]['id'] === $pending
                    && $rows[0]['status'] === 'pending');

            $html = $response->getContent();
            $this->assertSame(1, substr_count($html, 'data-order-pending-queue="P209"'));
            $this->assertSame(1, preg_match(
                '/<a class="status-tab"[^>]*data-order-pending-queue="P209"[^>]*aria-current="page"/s',
                $html,
            ));
        }
    }

    public function test_pending_queue_is_server_paginated_without_leaking_other_stores_or_statuses(): void
    {
        $actor = $this->superAdmin();
        $store = $this->retailStore('P209-PAGE');
        $foreign = $this->retailStore('P209-OTHER');
        $buyer = app(B2cCustomerService::class)->create($store, ['name' => 'P209 Paged Buyer']);
        $foreignBuyer = app(B2cCustomerService::class)->create($foreign, ['name' => 'P209 Other Buyer']);

        for ($i = 1; $i <= 26; $i++) {
            $this->order($store, (int) $buyer->legacy_customer_id, (int) $buyer->id, 'P209-PAGE-'.$i, 'pending');
        }

        $this->order($store, (int) $buyer->legacy_customer_id, (int) $buyer->id, 'P209-DONE', 'delivered');
        $this->order($foreign, (int) $foreignBuyer->legacy_customer_id, (int) $foreignBuyer->id, 'P209-OTHER-STORE', 'pending');

        $this->actingAs($actor)->get(route('admin.operations.orders.index', [
            'channel' => 'b2c',
            'store_id' => $store,
            'status' => 'pending',
            'action_required' => 0,
            'per_page' => 10,
            'page' => 3,
        ]))
            ->assertOk()
            ->assertViewHas('orders', static function ($paginator) use ($store): bool {
                if (! $paginator instanceof LengthAwarePaginator) {
                    return false;
                }

                $thirdPage = $paginator->url(3);

                return $paginator->total() === 26
                    && $paginator->perPage() === 10
                    && $paginator->currentPage() === 3
                    && str_contains($thirdPage, 'channel=b2c')
                    && str_contains($thirdPage, 'store_id='.$store)
                    && str_contains($thirdPage, 'status=pending');
            })
            ->assertViewHas('rows', static fn (array $rows): bool => count($rows) === 6
                && collect($rows)->every(static fn (array $row): bool => $row['status'] === 'pending'));
    }

    public function test_retail_operator_can_read_only_own_pending_queue_and_disabled_account_cannot_read_it(): void
    {
        $store = $this->retailStore('P209-AUTH-OWN');
        $otherStore = $this->retailStore('P209-AUTH-OTHER');
        $buyer = app(B2cCustomerService::class)->create($store, ['name' => 'Own Buyer']);
        $foreignBuyer = app(B2cCustomerService::class)->create($otherStore, ['name' => 'Foreign Buyer']);

        $own = $this->order($store, (int) $buyer->legacy_customer_id, (int) $buyer->id, 'P209-OWN-ORDER', 'pending');
        $foreign = $this->order($otherStore, (int) $foreignBuyer->legacy_customer_id, (int) $foreignBuyer->id, 'P209-FOREIGN-ORDER', 'pending');
        $operator = $this->retailOperator($store);

        $this->actingAs($operator)->get(route('admin.operations.orders.index', [
            'channel' => 'b2c', 'store_id' => $store, 'status' => 'pending', 'action_required' => 0,
        ]))->assertOk()
            ->assertViewHas('rows', static fn (array $rows): bool => count($rows) === 1
                && (int) $rows[0]['id'] === $own);

        $forbiddenStore = $this->actingAs($operator)->get(route('admin.operations.orders.index', [
            'channel' => 'b2c', 'store_id' => $otherStore, 'status' => 'pending',
        ]));
        $this->assertContains($forbiddenStore->getStatusCode(), [403, 404]);
        $forbiddenStore->assertDontSee('P209-FOREIGN-ORDER');

        $this->actingAs($operator)->get(route('admin.operations.orders.index', [
            'channel' => 'b2b', 'status' => 'pending',
        ]))->assertForbidden();

        $operator->forceFill(['is_active' => false])->save();
        $this->actingAs($operator->fresh())->get(route('admin.operations.orders.index', [
            'channel' => 'b2c', 'store_id' => $store, 'status' => 'pending',
        ]))->assertForbidden();

        $this->assertDatabaseHas('orders', ['id' => $own, 'status' => 'pending']);
        $this->assertDatabaseHas('orders', ['id' => $foreign, 'status' => 'pending']);
    }

    public function test_wholesale_pending_queue_stays_in_its_principal_channel_and_never_mixes_retail_orders(): void
    {
        $actor = $this->superAdmin();
        $wholesale = app(WholesalePrincipal::class)->storeId();
        $retail = $this->retailStore('P209-RETAIL-ISOLATION');

        $legacy = (int) DB::table('customers')->insertGetId([
            'type' => 'b2b',
            'name' => 'P209 Wholesale Buyer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $b2bCustomer = (int) DB::table('b2b_customers')->insertGetId([
            'legacy_customer_id' => $legacy,
            'name' => 'P209 Wholesale Buyer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $pending = (int) DB::table('orders')->insertGetId([
            'store_id' => $wholesale,
            'customer_id' => $legacy,
            'b2b_customer_id' => $b2bCustomer,
            'order_number' => 'P209-WHOLESALE-PENDING',
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
        $retailBuyer = app(B2cCustomerService::class)->create($retail, ['name' => 'P209 Retail Buyer']);
        $this->order($retail, (int) $retailBuyer->legacy_customer_id, (int) $retailBuyer->id, 'P209-RETAIL-PENDING', 'pending');

        $this->actingAs($actor)->get(route('admin.operations.orders.index', [
            'channel' => 'b2b',
            'store_id' => $wholesale,
            'status' => 'pending',
            'action_required' => 0,
        ]))->assertOk()
            ->assertViewHas('rows', static fn (array $rows): bool => count($rows) === 1
                && (int) $rows[0]['id'] === $pending
                && $rows[0]['channel'] === 'b2b');

        $crossStore = $this->actingAs($actor)->get(route('admin.operations.orders.index', [
            'channel' => 'b2b', 'store_id' => $retail, 'status' => 'pending',
        ]));
        $this->assertContains($crossStore->getStatusCode(), [403, 404]);

        $this->actingAs($actor)->get(route('admin.operations.orders.index', [
            'channel' => 'all', 'status' => 'pending',
        ]))->assertForbidden();
    }

    public function test_permissionless_and_explicitly_denied_actors_are_rejected_on_direct_pending_url(): void
    {
        $store = $this->retailStore('P209-DENY');
        $unassigned = User::factory()->create(['is_active' => true]);

        $this->assertNotContains('order_operations', $this->navigationKeys($unassigned));
        $this->actingAs($unassigned)->get(route('admin.operations.orders.index', [
            'channel' => 'b2c', 'store_id' => $store, 'status' => 'pending',
        ]))->assertForbidden();

        $operator = $this->retailOperator($store);
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

        $this->actingAs($operator->fresh())->get(route('admin.operations.orders.index', [
            'channel' => 'b2c', 'store_id' => $store, 'status' => 'pending',
        ]))->assertForbidden();

        $this->actingAs($this->superAdmin())->getJson(route('admin.operations.orders.index', [
            'channel' => 'b2c', 'store_id' => $store, 'status' => 'malicious-state',
        ]))->assertUnprocessable()->assertJsonValidationErrors('status');
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
        $operator = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        UserStoreRole::query()->create([
            'user_id' => $operator->id,
            'store_id' => $store,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
        ]);

        return $operator;
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

    private function order(int $store, int $legacy, int $customer, string $number, string $status): int
    {
        return (int) DB::table('orders')->insertGetId([
            'store_id' => $store,
            'customer_id' => $legacy,
            'b2c_customer_id' => $customer,
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
}
