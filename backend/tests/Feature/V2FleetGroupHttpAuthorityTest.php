<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreType;
use App\Models\User;
use App\Services\AdminSidebarManager;
use App\Services\FleetLocationService;
use App\Services\VanRegistryService;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P260: Fleet is an existing Field Operations business-domain group.
 * The map and the Van registry are independently authorized server routes.
 */
final class V2FleetGroupHttpAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_fleet_group_registry_reuses_real_map_and_van_routes_without_duplicate_sidebar(): void
    {
        $registry = app(AdminSidebarManager::class)->registry()['items'];

        $this->assertSame('field_operations', $registry['field_ops_fleet']['group']);
        $this->assertSame(['drivers.tracking.view'], $registry['field_ops_fleet']['permissions']);
        $this->assertSame('field_operations', $registry['field_ops_vans']['group']);
        $this->assertSame(['drivers.b2b.view'], $registry['field_ops_vans']['permissions']);

        foreach ([
            'admin.field-operations.fleet',
            'admin.field-operations.fleet.feed',
            'admin.field-operations.vans',
            'admin.field-operations.vans.show',
        ] as $routeName) {
            $this->assertTrue(Route::has($routeName), $routeName);
        }

        $admin = $this->superAdmin();
        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $admin->forceFill(['locale' => $locale])->save();

            $groups = collect(app(AdminNavigation::class)->groupsFor($admin->fresh()))
                ->where('key', 'field_operations')
                ->values();
            $this->assertCount(1, $groups);

            $children = collect($groups->first()['children']);
            $this->assertCount(1, $children->where('key', 'field_ops_fleet'));
            $this->assertCount(1, $children->where('key', 'field_ops_vans'));
            $this->assertSame('admin.field-operations.fleet', $children->firstWhere('key', 'field_ops_fleet')['route']);
            $this->assertSame('admin.field-operations.vans', $children->firstWhere('key', 'field_ops_vans')['route']);

            $this->actingAs($admin->fresh())
                ->get(route('admin.field-operations.fleet'))
                ->assertOk()
                ->assertViewIs('admin.field-operations')
                ->assertViewHas('section', 'fleet')
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-nav-item="field_ops_fleet"', false);

            $this->actingAs($admin->fresh())
                ->get(route('admin.field-operations.vans'))
                ->assertOk()
                ->assertViewHas('section', 'vans')
                ->assertSee('dir="'.$direction.'"', false);
        }
    }

    public function test_real_fleet_feed_is_van_only_and_respects_selected_b2b_store(): void
    {
        $first = $this->store('B2B', 'P2590-B2B-ONE');
        $second = $this->store('B2B', 'P2590-B2B-TWO');
        $retail = $this->store('B2C', 'P2590-B2C');
        $vanOne = app(VanRegistryService::class)->createVan(['code' => 'P2590-VAN-ONE']);
        $vanTwo = app(VanRegistryService::class)->createVan(['code' => 'P2590-VAN-TWO']);
        $service = app(FleetLocationService::class);

        foreach ([
            ['van', (int) $vanOne->getKey(), $first->getKey(), 'b2b'],
            ['van', (int) $vanTwo->getKey(), $second->getKey(), 'b2b'],
            ['driver', 259012, $retail->getKey(), 'b2c'],
        ] as [$type, $actorId, $storeId, $channel]) {
            $service->heartbeat($type, (int) $actorId, [
                'vehicle_id' => $type === 'van' ? $actorId : null,
                'store_id' => (int) $storeId,
                'channel' => $channel,
                'latitude' => 29.3,
                'longitude' => 47.9,
                'captured_at' => now()->toDateTimeString(),
                'source_app' => $type,
            ]);
        }

        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->get(route('admin.field-operations.fleet.feed', ['actor_type' => 'driver']))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.actor_type', 'van')
            ->assertJsonPath('data.1.actor_type', 'van');

        $this->actingAs($admin)
            ->get(route('admin.field-operations.fleet.feed', ['store_id' => $first->getKey()]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.store_id', $first->getKey())
            ->assertJsonPath('data.0.van.code', 'P2590-VAN-ONE');

        $this->actingAs($admin)
            ->get(route('admin.field-operations.fleet.feed', ['store_id' => $retail->getKey()]))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_wholesale_van_view_does_not_grant_fleet_live_tracking(): void
    {
        $actor = $this->actorWithPermissions('P2590_B2B_READ_ONLY', ['drivers.b2b.view']);

        $children = $this->fieldOperationsChildren($actor);
        $this->assertContains('field_ops_vans', $children->pluck('key')->all());
        $this->assertNotContains('field_ops_fleet', $children->pluck('key')->all());
        $this->assertNotContains('field_ops_finance', $children->pluck('key')->all());

        $this->actingAs($actor)->get(route('admin.field-operations.vans'))->assertOk();
        $this->actingAs($actor)->get(route('admin.field-operations.fleet'))->assertForbidden();
        $this->actingAs($actor)->get(route('admin.field-operations.fleet.feed'))->assertForbidden();
    }

    public function test_retail_tracking_can_open_map_but_cannot_read_wholesale_fleet(): void
    {
        $retail = $this->store('B2C', 'P2590-RETAIL-OWN');
        $wholesale = $this->store('B2B', 'P2590-FOREIGN-WHOLESALE');
        $van = app(VanRegistryService::class)->createVan(['code' => 'P2590-PRIVATE-VAN']);
        app(FleetLocationService::class)->heartbeat('van', (int) $van->getKey(), [
            'store_id' => $wholesale->getKey(),
            'channel' => 'b2b',
            'latitude' => 29.3,
            'longitude' => 47.9,
            'captured_at' => now()->toDateTimeString(),
            'source_app' => 'van',
        ]);
        $actor = $this->retailAdmin((int) $retail->getKey());

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $children = $this->fieldOperationsChildren($actor->fresh());
            $this->assertContains('field_ops_fleet', $children->pluck('key')->all());
            $this->assertNotContains('field_ops_vans', $children->pluck('key')->all());

            $this->actingAs($actor->fresh())
                ->get(route('admin.field-operations.fleet'))
                ->assertOk()
                ->assertViewHas('section', 'fleet')
                ->assertSee('dir="'.$direction.'"', false);

            $this->actingAs($actor->fresh())
                ->get(route('admin.field-operations.fleet.feed'))
                ->assertOk()
                ->assertJsonCount(0, 'data');

            $this->actingAs($actor->fresh())
                ->get(route('admin.field-operations.fleet.feed', ['store_id' => $wholesale->getKey()]))
                ->assertNotFound();

            $this->actingAs($actor->fresh())
                ->get(route('admin.field-operations.vans'))
                ->assertForbidden();
        }
    }

    public function test_roleless_and_inactive_users_have_no_field_operations_group_or_fleet_http(): void
    {
        $roleless = User::factory()->create(['is_active' => true]);
        $inactive = $this->actorWithPermissions('P2590_INACTIVE', ['drivers.tracking.view', 'drivers.b2b.view']);
        $inactive->forceFill(['is_active' => false])->save();

        foreach ([$roleless, $inactive] as $actor) {
            foreach (['ar', 'en'] as $locale) {
                app()->setLocale($locale);
                $actor->forceFill(['locale' => $locale])->save();

                $this->assertNull(collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
                    ->firstWhere('key', 'field_operations'));

                $this->actingAs($actor->fresh())
                    ->get(route('admin.field-operations.fleet'))
                    ->assertForbidden();
                $this->actingAs($actor->fresh())
                    ->get(route('admin.field-operations.fleet.feed'))
                    ->assertForbidden();
                $this->actingAs($actor->fresh())
                    ->get(route('admin.field-operations.vans'))
                    ->assertForbidden();
            }
        }
    }

    public function test_explicit_global_tracking_deny_removes_map_and_blocks_feed_without_affecting_other_roles(): void
    {
        $retail = $this->store('B2C', 'P2590-RETAIL-DENY');
        $actor = $this->retailAdmin((int) $retail->getKey());

        $permission = Permission::query()->where('code', 'drivers.tracking.view')->firstOrFail();
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

        $keys = $this->fieldOperationsChildren($actor->fresh())->pluck('key')->all();
        $this->assertNotContains('field_ops_fleet', $keys);
        $this->assertNotContains('field_ops_vans', $keys);

        $this->actingAs($actor->fresh())
            ->get(route('admin.field-operations.fleet'))
            ->assertForbidden();
        $this->actingAs($actor->fresh())
            ->get(route('admin.field-operations.fleet.feed'))
            ->assertForbidden();
    }

    public function test_second_global_finance_role_never_grants_wholesale_van_inventory_to_retail(): void
    {
        $retail = $this->store('B2C', 'P2590-MULTI-RETAIL');
        $actor = $this->retailAdmin((int) $retail->getKey());
        $actor->roles()->attach(Role::query()->where('code', 'FINANCE')->firstOrFail());

        $children = $this->fieldOperationsChildren($actor->fresh());
        $this->assertContains('field_ops_fleet', $children->pluck('key')->all());
        $this->assertNotContains('field_ops_vans', $children->pluck('key')->all());

        $this->actingAs($actor->fresh())
            ->get(route('admin.field-operations.vans'))
            ->assertForbidden();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function fieldOperationsChildren(User $user): Collection
    {
        return collect(collect(app(AdminNavigation::class)->groupsFor($user))
            ->firstWhere('key', 'field_operations')['children'] ?? []);
    }

    /** @param list<string> $codes */
    private function actorWithPermissions(string $code, array $codes): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $role = Role::query()->create([
            'code' => $code,
            'name' => $code,
            'scope' => 'global',
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->attach(Permission::query()->whereIn('code', $codes)->pluck('id'));
        $actor->roles()->attach($role);

        return $actor;
    }

    private function superAdmin(): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $actor;
    }

    private function store(string $type, string $code): Store
    {
        $storeType = StoreType::query()->where('code', $type)->firstOrFail();

        return Store::query()->create([
            'store_type_id' => $storeType->getKey(),
            'business_profile' => $type === 'B2C' ? StoreBusinessProfile::RETAIL_STORE->value : null,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
    }

    private function retailAdmin(int $storeId): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->getKey(),
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor;
    }
}
