<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AdminSidebarManager;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * V2 #2542 / P212: delivery and execution remain within the canonical
 * Orders & Operations domain, with navigation projecting server permissions.
 */
final class V2DeliveryExecutionSidebarAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_wholesale_delivery_entry_reuses_a_real_orders_route_in_arabic_and_english(): void
    {
        $this->store('B2B', 'P212-WHOLESALE');
        $actor = $this->operator([
            'OPERATIONS' => ['orders.view'],
        ]);

        $registry = app(AdminSidebarManager::class)->registry();
        $this->assertSame('admin.nav_groups.operations', $registry['groups']['operations']['label']);
        $this->assertSame('Orders & Operations', __('admin.nav_groups.operations', [], 'en'));
        $this->assertSame('الطلبات والعمليات', __('admin.nav_groups.operations', [], 'ar'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $group = $this->operations($actor);

            $this->assertIsArray($group);
            $items = collect($group['children'])->keyBy('key');
            $this->assertTrue($items->has('order_operations'));
            $this->assertSame('admin.operations.orders.index', $items->get('order_operations')['route']);
            $this->assertTrue(Route::has($items->get('order_operations')['route']));
            $this->assertFalse($items->has('b2c_orders'));

            $this->actingAs($actor->fresh())
                ->get(route('admin.operations.orders.index', [], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-nav-group="operations"', false);
        }
    }

    public function test_wholesale_sidebar_initializes_the_same_principal_as_the_http_orders_workspace(): void
    {
        // The initial seed does not need to pre-create the canonical Wholesale
        // store. The real HTTP route resolves it before evaluating scope.
        $actor = $this->operator([
            'OPERATIONS' => ['orders.view'],
        ]);

        $group = $this->operations($actor);
        $this->assertIsArray($group);
        $this->assertContains('order_operations', collect($group['children'])->pluck('key')->all());
        $this->assertDatabaseHas('stores', [
            'code' => WholesalePrincipal::STORE_CODE,
            'is_active' => true,
        ]);
        $this->actingAs($actor->fresh())
            ->get(route('admin.operations.orders.index', [], false))
            ->assertOk();
    }

    public function test_roleless_or_unrelated_roles_cannot_see_or_open_delivery_operations(): void
    {
        $this->store('B2B', 'P212-NOGRANT');
        $roleless = User::factory()->create(['is_active' => true]);
        $unrelated = $this->operator([
            'P212_REPORT_READER' => ['reports.view'],
        ]);

        foreach ([$roleless, $unrelated] as $actor) {
            $this->assertNull($this->operations($actor));
            $this->actingAs($actor->fresh())
                ->get(route('admin.operations.orders.index', [], false))
                ->assertForbidden();
        }
    }

    public function test_explicit_permission_deny_removes_order_execution_without_erasing_other_grants(): void
    {
        $this->store('B2B', 'P212-DENY');
        $actor = $this->operator([
            'OPERATIONS' => ['orders.view'],
            'P212_TRACKING_ROLE' => ['drivers.tracking.view'],
        ]);

        $before = collect($this->operations($actor)['children'] ?? [])->pluck('key')->all();
        $this->assertContains('order_operations', $before);
        $this->assertContains('driver_live_tracking', $before);

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

        $this->assertFalse($actor->fresh()->hasPermission('orders.view'));
        $after = collect($this->operations($actor->fresh())['children'] ?? [])->pluck('key')->all();
        $this->assertNotContains('order_operations', $after);
        $this->assertContains('driver_live_tracking', $after);

        $this->actingAs($actor->fresh())
            ->get(route('admin.operations.orders.index', [], false))
            ->assertForbidden();
    }

    public function test_global_orders_permission_without_an_authorized_operational_channel_never_exposes_dead_link(): void
    {
        $this->store('B2B', 'P212-UNLISTED-B2B');
        $this->store('B2C', 'P212-UNLISTED-B2C');
        $actor = $this->operator([
            'P212_UNLISTED_GLOBAL_ORDERS' => ['orders.view'],
        ]);

        $this->assertTrue($actor->fresh()->hasPermission('orders.view'));
        $keys = collect($this->operations($actor)['children'] ?? [])->pluck('key')->all();
        $this->assertNotContains('order_operations', $keys);

        $this->actingAs($actor->fresh())
            ->get(route('admin.operations.orders.index', [], false))
            ->assertForbidden();
    }

    public function test_retail_delivery_link_carries_its_own_store_not_a_foreign_store(): void
    {
        $ownStoreId = $this->store('B2C', 'P212-OWN');
        $foreignStoreId = $this->store('B2C', 'P212-FOREIGN');
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $ownStoreId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $group = $this->operations($actor->fresh());
        $this->assertIsArray($group);
        $items = collect($group['children'])->keyBy('key');
        $this->assertTrue($items->has('b2c_orders'));
        $retail = $items->get('b2c_orders');
        $this->assertSame((string) $ownStoreId, $retail['params']['store_id']);
        $this->assertNotSame((string) $foreignStoreId, $retail['params']['store_id']);
        $this->assertTrue(Route::has($retail['route']));
        $this->assertFalse($items->has('b2b_drivers'));

        $this->actingAs($actor->fresh())
            ->get(route('admin.operations.orders.index', [
                'channel' => 'b2c',
                'store_id' => $ownStoreId,
            ], false))
            ->assertOk();
        $this->actingAs($actor->fresh())
            ->get(route('admin.operations.orders.index', [
                'channel' => 'b2c',
                'store_id' => $foreignStoreId,
            ], false))
            ->assertNotFound();
        $this->actingAs($actor->fresh())
            ->get(route('admin.operations.orders.index', ['channel' => 'b2b'], false))
            ->assertForbidden();
    }

    /** @return array<string, mixed>|null */
    private function operations(User $actor): ?array
    {
        return collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
            ->firstWhere('key', 'operations');
    }

    /** @param array<string, list<string>> $roles */
    private function operator(array $roles): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        foreach ($roles as $code => $permissions) {
            $role = Role::query()->firstOrCreate(
                ['code' => $code],
                [
                    'name' => $code,
                    'scope' => 'global',
                    'is_system' => false,
                    'is_active' => true,
                ],
            );
            $role->permissions()->syncWithoutDetaching(
                Permission::query()->whereIn('code', $permissions)->pluck('id'),
            );
            $actor->roles()->attach($role);
        }

        return $actor;
    }

    private function store(string $channel, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $channel)->value('id'),
            'business_profile' => $channel === 'B2C' ? 'RETAIL_STORE' : null,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
