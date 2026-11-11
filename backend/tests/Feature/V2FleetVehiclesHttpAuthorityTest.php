<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreType;
use App\Models\User;
use App\Models\Van;
use App\Services\AdminSidebarManager;
use App\Services\VanRegistryService;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P264: Vehicles are the existing Van registry, not an unprotected
 * generic Fleet page. Read and management permissions are separate.
 */
final class V2FleetVehiclesHttpAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_vehicle_registry_has_one_real_authorized_entry_and_renders_registered_van_in_both_locales(): void
    {
        $van = app(VanRegistryService::class)->createVan([
            'code' => 'P2594-FLEET-ONE',
            'plate_number' => 'P2594-PLATE',
            'vehicle_type' => 'delivery_van',
            'capacity_units' => 24,
        ]);
        $actor = $this->actorWithPermissions('P2594_B2B_VIEWER', ['drivers.b2b.view']);
        $entry = app(AdminSidebarManager::class)->registry()['items']['field_ops_vans'];

        $this->assertSame('field_operations', $entry['group']);
        $this->assertSame(['drivers.b2b.view'], $entry['permissions']);
        $this->assertTrue(Route::has('admin.field-operations.vans'));
        $this->assertTrue(Route::has('admin.field-operations.vans.show'));
        $this->assertTrue(Route::has('admin.field-operations.vans.store'));
        $this->assertTrue(Route::has('admin.field-operations.vans.suspend'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $children = $this->vehicleNavigation($actor->fresh());
            $this->assertCount(1, $children);
            $item = $children->first();
            $this->assertSame('field_operations', $item['group_key']);
            $this->assertSame('admin.field-operations.vans', $item['route']);
            $this->assertNull($item['permission']); // Server controller enforces drivers.b2b.view.

            $this->actingAs($actor->fresh())
                ->get(route('admin.field-operations.vans'))
                ->assertOk()
                ->assertViewIs('admin.field-operations')
                ->assertViewHas('section', 'vans')
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-nav-item="field_ops_vans"', false)
                ->assertSee('P2594-FLEET-ONE');

            $this->actingAs($actor->fresh())
                ->get(route('admin.field-operations.vans.show', ['van' => $van->getKey()]))
                ->assertOk()
                ->assertViewHas('section', 'van-detail')
                ->assertSee('P2594-FLEET-ONE');
        }
    }

    public function test_viewer_can_read_but_cannot_create_or_suspend_a_van(): void
    {
        $actor = $this->actorWithPermissions('P2594_READONLY', ['drivers.b2b.view']);
        $van = app(VanRegistryService::class)->createVan(['code' => 'P2594-READONLY-VAN']);

        $this->actingAs($actor)
            ->get(route('admin.field-operations.vans'))
            ->assertOk();

        $this->actingAs($actor)
            ->post(route('admin.field-operations.vans.store'), [
                'code' => 'P2594-FORBIDDEN-NEW',
            ])
            ->assertForbidden();

        $this->actingAs($actor)
            ->post(route('admin.field-operations.vans.suspend', ['van' => $van->getKey()]))
            ->assertForbidden();

        $this->assertDatabaseMissing('vans', ['code' => 'P2594-FORBIDDEN-NEW']);
        $this->assertSame('active', $van->fresh()->status);
    }

    public function test_authorized_fleet_manager_can_create_and_suspend_van_using_real_workflow(): void
    {
        $actor = $this->actorWithPermissions('P2594_FLEET_MANAGER', [
            'drivers.b2b.view',
            'drivers.b2b.manage',
        ]);

        $this->actingAs($actor)
            ->from(route('admin.field-operations.vans'))
            ->post(route('admin.field-operations.vans.store'), [
                'code' => 'P2594-MANAGED-VAN',
                'plate_number' => 'P2594-MANAGED-PLATE',
                'capacity_units' => 32,
            ])
            ->assertRedirect(route('admin.field-operations.vans'))
            ->assertSessionHasNoErrors();

        $van = Van::query()->where('code', 'P2594-MANAGED-VAN')->firstOrFail();
        $this->assertSame('active', $van->status);
        $this->assertSame(32, (int) $van->capacity_units);

        $this->actingAs($actor)
            ->from(route('admin.field-operations.vans'))
            ->post(route('admin.field-operations.vans.suspend', ['van' => $van->getKey()]), [
                'reason' => 'P2594 test suspension',
            ])
            ->assertRedirect(route('admin.field-operations.vans'))
            ->assertSessionHasNoErrors();

        $this->assertSame('suspended', $van->fresh()->status);
    }

    public function test_retail_roleless_and_inactive_actors_have_neither_vehicle_entry_nor_http_access(): void
    {
        $store = Store::query()->create([
            'store_type_id' => StoreType::query()->where('code', 'B2C')->firstOrFail()->getKey(),
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'code' => 'P2594-RETAIL-ONLY',
            'name' => 'P2594 Retail Store',
            'is_active' => true,
        ]);
        $retail = User::factory()->create(['is_active' => true]);
        DB::table('user_store_roles')->insert([
            'user_id' => $retail->getKey(),
            'store_id' => $store->getKey(),
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $roleless = User::factory()->create(['is_active' => true]);
        $inactive = $this->actorWithPermissions('P2594_INACTIVE', ['drivers.b2b.view', 'drivers.b2b.manage']);
        $inactive->forceFill(['is_active' => false])->save();

        foreach ([$retail, $roleless, $inactive] as $actor) {
            foreach (['ar', 'en'] as $locale) {
                $actor->forceFill(['locale' => $locale])->save();
                app()->setLocale($locale);

                $this->assertCount(0, $this->vehicleNavigation($actor->fresh()));

                $this->actingAs($actor->fresh())
                    ->get(route('admin.field-operations.vans'))
                    ->assertForbidden();

                $this->actingAs($actor->fresh())
                    ->post(route('admin.field-operations.vans.store'), [
                        'code' => 'P2594-UNAUTHORIZED-NEW',
                    ])
                    ->assertForbidden();
            }
        }

        $this->assertDatabaseMissing('vans', ['code' => 'P2594-UNAUTHORIZED-NEW']);
    }

    public function test_multi_role_retail_actor_does_not_gain_van_authority_without_b2b_permission(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'FINANCE')->firstOrFail());

        $this->assertCount(0, $this->vehicleNavigation($actor));

        $this->actingAs($actor)
            ->get(route('admin.field-operations.vans'))
            ->assertForbidden();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function vehicleNavigation(User $user): Collection
    {
        return collect(app(AdminNavigation::class)->groupsFor($user))
            ->flatMap(static fn (array $group): array => collect($group['children'])
                ->map(static fn (array $item): array => [
                    ...$item,
                    'group_key' => $group['key'],
                ])->all())
            ->where('key', 'field_ops_vans')
            ->values();
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
        $role->permissions()->attach(
            Permission::query()->whereIn('code', $codes)->pluck('id'),
        );
        $actor->roles()->attach($role);

        return $actor;
    }
}
