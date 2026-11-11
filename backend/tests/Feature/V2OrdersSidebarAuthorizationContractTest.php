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
 * V2 #2524 / P194: existing Orders destinations must reflect the same
 * operational permission, channel, and Retail store scope as HTTP access.
 */
final class V2OrdersSidebarAuthorizationContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_retail_orders_navigation_opens_authorized_real_workspaces_in_both_locales(): void
    {
        $storeId = $this->retailStore('P194-AUTHORIZED');
        $actor = $this->retailAdmin($storeId, 'p194-authorized@example.test');
        $orderId = $this->retailOrder($storeId, 'P194-AUTHORIZED-ORDER');

        $this->assertTrue(Route::has('admin.operations.orders.index'));
        $this->assertTrue(Route::has('admin.b2c.module'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $actor = $actor->fresh();

            $navigation = app(AdminNavigation::class)->groupsFor($actor);
            $operations = collect($navigation)->firstWhere('key', 'operations');
            $this->assertIsArray($operations);

            $links = collect($operations['children'])->keyBy('key');
            $this->assertTrue($links->has('order_operations'));
            $this->assertSame('admin.operations.orders.index', $links['order_operations']['route']);
            $this->assertTrue($links->has('b2c_orders'));
            $this->assertSame('admin.b2c.module', $links['b2c_orders']['route']);
            $this->assertSame(
                ['module' => 'orders', 'store_id' => (string) $storeId],
                $links['b2c_orders']['params'],
            );

            $this->actingAs($actor)
                ->get(route('admin.operations.orders.index', ['channel' => 'b2c', 'store_id' => $storeId]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertViewHas('rows', static fn (array $rows): bool => collect($rows)
                    ->contains(static fn (array $row): bool => $row['id'] === $orderId));

            $this->actingAs($actor)
                ->get(route('admin.b2c.module', ['module' => 'orders', 'store_id' => $storeId]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);
        }
    }

    public function test_retail_operator_cannot_select_foreign_store_or_wholesale_orders(): void
    {
        $ownStore = $this->retailStore('P194-OWN');
        $foreignStore = $this->retailStore('P194-FOREIGN');
        $actor = $this->retailAdmin($ownStore, 'p194-scoped@example.test');
        $foreignOrder = $this->retailOrder($foreignStore, 'P194-FOREIGN-ORDER');

        $this->actingAs($actor)
            ->get(route('admin.operations.orders.index', [
                'channel' => 'b2c',
                'store_id' => $foreignStore,
            ]))
            ->assertNotFound();

        $this->actingAs($actor)
            ->get(route('admin.b2c.module', [
                'module' => 'orders',
                'store_id' => $foreignStore,
            ]))
            ->assertNotFound();

        $this->actingAs($actor)
            ->get(route('admin.operations.orders.index', ['channel' => 'b2b']))
            ->assertForbidden();

        $this->actingAs($actor)
            ->get(route('admin.operations.orders.popup', [
                'order' => $foreignOrder,
                'kind' => 'details',
            ]))
            ->assertNotFound();
    }

    public function test_explicit_order_view_deny_revokes_both_navigation_and_http_access(): void
    {
        $storeId = $this->retailStore('P194-DENY');
        $actor = $this->retailAdmin($storeId, 'p194-denied@example.test');
        $this->deny($actor, 'orders.view');

        $keys = $this->navigationKeys($actor->fresh());
        $this->assertNotContains('order_operations', $keys);
        $this->assertNotContains('b2c_orders', $keys);

        $this->actingAs($actor->fresh())
            ->get(route('admin.operations.orders.index', ['channel' => 'b2c']))
            ->assertForbidden();

        $this->actingAs($actor->fresh())
            ->get(route('admin.b2c.module', [
                'module' => 'orders',
                'store_id' => $storeId,
            ]))
            ->assertForbidden();
    }

    public function test_disabled_retail_store_and_unsupported_restaurant_module_do_not_advertise_orders(): void
    {
        $restaurantId = $this->retailStore('P194-RESTAURANT', StoreBusinessProfile::RESTAURANT);
        $restaurantAdmin = $this->retailAdmin($restaurantId, 'p194-restaurant@example.test');

        $this->assertNotContains('b2c_orders', $this->navigationKeys($restaurantAdmin));
        $this->actingAs($restaurantAdmin)
            ->get(route('admin.b2c.module', [
                'module' => 'orders',
                'store_id' => $restaurantId,
            ]))
            ->assertNotFound();

        $storeId = $this->retailStore('P194-INACTIVE');
        $actor = $this->retailAdmin($storeId, 'p194-inactive@example.test');
        DB::table('stores')->where('id', $storeId)->update(['is_active' => false]);

        $keys = $this->navigationKeys($actor->fresh());
        $this->assertNotContains('b2c_orders', $keys);
        $this->assertNotContains('order_operations', $keys);

        $this->actingAs($actor->fresh())
            ->get(route('admin.operations.orders.index', ['channel' => 'b2c']))
            ->assertForbidden();

        $this->actingAs($actor->fresh())
            ->get(route('admin.b2c.module', [
                'module' => 'orders',
                'store_id' => $storeId,
            ]))
            ->assertForbidden();
    }

    public function test_roleless_user_has_no_orders_destination_or_direct_read(): void
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        $this->assertNotContains('order_operations', $this->navigationKeys($actor));
        $this->assertNotContains('b2c_orders', $this->navigationKeys($actor));

        $this->actingAs($actor)
            ->get(route('admin.operations.orders.index'))
            ->assertForbidden();

        $this->actingAs($actor)
            ->get(route('admin.b2c.module', ['module' => 'orders']))
            ->assertForbidden();
    }

    private function retailStore(string $code, StoreBusinessProfile $profile = StoreBusinessProfile::RETAIL_STORE): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => $profile->value,
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

    private function retailOrder(int $storeId, string $number): int
    {
        $customer = app(B2cCustomerService::class)->create($storeId, ['name' => 'P194 Scoped Buyer']);

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

    /** @return list<string> */
    private function navigationKeys(User $actor): array
    {
        return collect(app(AdminNavigation::class)->groupsFor($actor))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->pluck('key')
            ->all();
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
