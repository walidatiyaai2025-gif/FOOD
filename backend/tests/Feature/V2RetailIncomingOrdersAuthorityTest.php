<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\B2cCustomerService;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * V2 #2538 / Sidebar P208: incoming Retail orders reuse the real B2C
 * Orders workspace with its pending-status filter, not a ghost module.
 */
final class V2RetailIncomingOrdersAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_pending_retail_orders_are_reachable_in_both_locales_and_never_include_other_stores_or_statuses(): void
    {
        $owned = $this->retailStore('P208-OWN');
        $foreign = $this->retailStore('P208-FOREIGN');
        $actor = $this->retailAdmin($owned);
        $incomingOrder = $this->retailOrder($owned, 'P208-INCOMING', 'pending');
        $completedOrder = $this->retailOrder($owned, 'P208-COMPLETED', 'delivered');
        $foreignOrder = $this->retailOrder($foreign, 'P208-FOREIGN-ORDER', 'pending');

        $this->assertTrue(Route::has('admin.b2c.orders.canonical'));
        $this->assertTrue(Route::has('admin.b2c.module'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $actor = $actor->fresh();

            $items = $this->operationsItems($actor);
            $this->assertArrayHasKey('b2c_orders', $items);
            $this->assertSame('admin.b2c.module', $items['b2c_orders']['route']);
            $this->assertSame(
                ['module' => 'orders', 'store_id' => (string) $owned],
                $items['b2c_orders']['params'],
            );
            $this->assertArrayNotHasKey('b2c_incoming_orders', $items);

            // The existing Retail Orders link resolves to the supported B2C
            // route, while the pending filter provides the incoming queue.
            $this->assertSame(
                route('admin.b2c.orders.canonical', ['store_id' => $owned]),
                route($items['b2c_orders']['route'], $items['b2c_orders']['params']),
            );

            $this->actingAs($actor)
                ->get(route('admin.b2c.orders.canonical', [
                    'store_id' => $owned,
                    'status' => 'pending',
                ]))
                ->assertOk()
                ->assertViewIs('admin.order-operations')
                ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'">', false)
                ->assertViewHas('selectedChannel', 'b2c')
                ->assertViewHas('selectedStatus', 'pending')
                ->assertViewHas('storeIds', [$owned])
                ->assertViewHas('rows', static function (array $rows) use ($incomingOrder, $completedOrder, $foreignOrder): bool {
                    $ids = collect($rows)->pluck('id')->all();

                    return in_array($incomingOrder, $ids, true)
                        && ! in_array($completedOrder, $ids, true)
                        && ! in_array($foreignOrder, $ids, true);
                });
        }
    }

    public function test_retail_incoming_alias_pins_channel_and_rejects_foreign_store_and_order_selection(): void
    {
        $owned = $this->retailStore('P208-CHANNEL-OWN');
        $foreign = $this->retailStore('P208-CHANNEL-FOREIGN');
        $actor = $this->retailAdmin($owned);
        $foreignOrder = $this->retailOrder($foreign, 'P208-FORGED-ORDER', 'pending');

        $this->actingAs($actor)
            ->get(route('admin.b2c.orders.canonical', [
                'store_id' => $owned,
                'channel' => 'b2b',
                'status' => 'pending',
            ]))
            ->assertOk()
            ->assertViewHas('selectedChannel', 'b2c')
            ->assertViewHas('storeIds', [$owned]);

        $this->actingAs($actor)
            ->get(route('admin.b2c.orders.canonical', [
                'store_id' => $foreign,
                'status' => 'pending',
            ]))
            ->assertNotFound();

        $this->actingAs($actor)
            ->get(route('admin.b2c.orders.canonical', [
                'store_id' => $owned,
                'order' => $foreignOrder,
            ]))
            ->assertNotFound();

        $this->actingAs($actor)
            ->get(route('admin.operations.orders.index', ['channel' => 'b2b']))
            ->assertForbidden();
    }

    public function test_incoming_orders_have_no_independent_unready_module_and_fail_closed_without_permission(): void
    {
        $owned = $this->retailStore('P208-DENY');
        $actor = $this->retailAdmin($owned);

        // A separate "incoming_orders" module has no authorized Retail
        // profile implementation; do not advertise or open a dead route.
        $this->assertArrayNotHasKey('b2c_incoming_orders', $this->operationsItems($actor));
        $this->actingAs($actor)
            ->get(route('admin.b2c.module', [
                'module' => 'incoming_orders',
                'store_id' => $owned,
            ]))
            ->assertNotFound();

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', 'orders.view')->firstOrFail()->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
        $actor = $actor->fresh();

        $this->assertArrayNotHasKey('b2c_orders', $this->operationsItems($actor));
        $this->actingAs($actor)
            ->get(route('admin.b2c.orders.canonical', [
                'store_id' => $owned,
                'status' => 'pending',
            ]))
            ->assertForbidden();

        $roleless = User::factory()->create(['is_active' => true]);
        $this->assertArrayNotHasKey('b2c_orders', $this->operationsItems($roleless));
        $this->actingAs($roleless)
            ->get(route('admin.b2c.orders.canonical', ['status' => 'pending']))
            ->assertForbidden();

        DB::table('stores')->where('id', $owned)->update(['is_active' => false]);
        $this->assertArrayNotHasKey('b2c_orders', $this->operationsItems($actor->fresh()));
    }

    /** @return array<string, array<string, mixed>> */
    private function operationsItems(User $actor): array
    {
        app()->forgetInstance(AdminNavigation::class);
        $groups = app(AdminNavigation::class)->groupsFor($actor);
        $operations = collect($groups)->firstWhere('key', 'operations');

        return collect((array) ($operations['children'] ?? []))->keyBy('key')->all();
    }

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function retailAdmin(int $storeId): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor->fresh();
    }

    private function retailOrder(int $storeId, string $number, string $status): int
    {
        $customer = app(B2cCustomerService::class)->create($storeId, ['name' => $number.' Buyer']);

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
}
