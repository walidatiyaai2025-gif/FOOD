<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Role;
use App\Models\User;
use App\Services\OperationalTenantScope;
use App\Services\VanRuntimeContextResolver;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * V2 #2910, historical Table 5 data row 5: a B2B_DRIVER role is not
 * sufficient evidence of an active Driver/Van assignment or admin rights.
 * No historical or production account identifier is used as a fixture.
 */
final class V2ExportedAccountRow5ScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_historic_wholesale_driver_role_cannot_gain_driver_app_or_dashboard_access(): void
    {
        $actor = $this->wholesaleDriverActor();
        $role = Role::query()->where('code', 'B2B_DRIVER')->firstOrFail();
        $wholesaleStore = (int) app(WholesalePrincipal::class)->storeId();

        $this->assertSame('global', $role->scope);
        $this->assertTrue((bool) $role->is_active);
        $this->assertSame(
            ['deliveries.b2b.execute'],
            $role->permissions()->pluck('permissions.code')->sort()->values()->all(),
        );
        $this->assertSame(['deliveries.b2b.execute'], $actor->inheritedPermissionCodes());
        $this->assertTrue($actor->hasPermission('deliveries.b2b.execute'));
        $this->assertSame(0, $actor->storeRoleAssignments()->count());

        foreach (['deliveries.b2c.execute', 'van.login', 'orders.view', 'inventory.view', 'roles.manage', 'users.delete'] as $permission) {
            $this->assertFalse($actor->hasPermission($permission), 'B2B_DRIVER must not inherit '.$permission);
        }

        $this->assertSame([], app(OperationalTenantScope::class)->allowedStoreIds($actor, 'orders.view', 'b2b'));
        $this->assertSame([], app(OperationalTenantScope::class)->allowedStoreIds($actor, 'inventory.view', 'b2c'));

        // A historical B2B driver profile is not a B2C Driver App assignment.
        Driver::query()->create([
            'user_id' => $actor->getKey(),
            'store_id' => $wholesaleStore,
            'driver_type' => 'b2b',
            'is_available' => true,
            'is_active' => true,
        ]);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $actor = $actor->fresh();

            $keys = $this->navigationKeys(app(AdminNavigation::class)->groupsFor($actor));
            $this->assertNotContains('sidebar_role_access', $keys);
            $this->assertNotContains('b2c_inventory', $keys);
            $this->assertNotContains('b2b_orders', $keys);

            $this->actingAs($actor)->get(route('admin.security.index'))->assertForbidden();
            $this->actingAs($actor)->get(route('admin.sidebar-access.roles'))->assertForbidden();
            $this->actingAs($actor)->get('/admin/b2b/orders')->assertForbidden();

            $this->postJson('/api/v1/auth/login', $this->loginPayload($actor, 'driver'))
                ->assertForbidden();

            // An app token does not authorize an unrelated app or channel.
            Sanctum::actingAs($actor, ['app:driver']);
            $this->getJson('/api/v1/driver/wallet')->assertForbidden();
            $this->getJson('/api/v1/van/visits')->assertForbidden();

            Sanctum::actingAs($actor, ['app:van']);
            $this->getJson('/api/v1/van/visits')->assertForbidden();
            $this->postJson('/api/v1/auth/login', $this->loginPayload($actor, 'van'))
                ->assertForbidden();
        }

        $this->assertDatabaseHas('users', ['id' => $actor->getKey(), 'is_active' => true]);
        $this->assertDatabaseHas('drivers', [
            'user_id' => $actor->getKey(),
            'driver_type' => 'b2b',
            'is_active' => true,
        ]);
        $this->assertSame(1, $actor->fresh()->roles()->count());
        $this->assertSame(0, DB::table('personal_access_tokens')
            ->where('tokenable_type', $actor->getMorphClass())
            ->where('tokenable_id', $actor->getKey())->count());
    }

    public function test_legacy_van_compatibility_requires_the_same_active_driver_and_never_another_users_profile(): void
    {
        $actor = $this->wholesaleDriverActor();
        $other = User::factory()->create(['is_active' => true]);
        $storeId = (int) app(WholesalePrincipal::class)->storeId();

        $ownDriver = Driver::query()->create([
            'user_id' => $actor->getKey(),
            'store_id' => $storeId,
            'driver_type' => 'b2b',
            'is_available' => true,
            'is_active' => true,
        ]);
        $foreignDriver = Driver::query()->create([
            'user_id' => $other->getKey(),
            'store_id' => $storeId,
            'driver_type' => 'b2b',
            'is_available' => true,
            'is_active' => true,
        ]);

        $resolver = app(VanRuntimeContextResolver::class);

        // The legacy bridge recognizes a matching profile only; Van HTTP
        // still requires an actual effective Van assignment in addition.
        $this->assertFalse($resolver->canUseRuntime($actor, ['driver_id' => null]));
        $this->assertFalse($resolver->canUseRuntime($actor, ['driver_id' => $foreignDriver->getKey()]));
        $this->assertTrue($resolver->canUseRuntime($actor, ['driver_id' => $ownDriver->getKey()]));
        $this->assertNull($resolver->resolve($actor)['selected']);

        Sanctum::actingAs($actor, ['app:van']);
        $this->getJson('/api/v1/van/visits')->assertForbidden();

        $ownDriver->forceFill(['is_active' => false])->save();
        $this->assertFalse($resolver->canUseRuntime($actor, ['driver_id' => $ownDriver->getKey()]));
        $this->assertFalse($actor->hasPermission('van.login'));
        $this->assertSame(0, $actor->storeRoleAssignments()->count());
        $this->assertDatabaseHas('drivers', ['id' => $ownDriver->getKey(), 'is_active' => false]);
        $this->assertDatabaseHas('drivers', ['id' => $foreignDriver->getKey(), 'is_active' => true]);
    }

    private function wholesaleDriverActor(): User
    {
        $actor = User::factory()->create([
            'name' => 'Synthetic Wholesale Driver Row5',
            'email' => 'v2-row5-synthetic@example.test',
            'password' => Hash::make('test-only-credential'),
            'is_active' => true,
            'locale' => 'en',
        ]);
        $actor->roles()->attach(Role::query()->where('code', 'B2B_DRIVER')->firstOrFail());

        return $actor->fresh();
    }

    /** @return array{email:string,password:string,app:string} */
    private function loginPayload(User $actor, string $app): array
    {
        return [
            'email' => (string) $actor->email,
            'password' => 'test-only-credential',
            'app' => $app,
        ];
    }

    /** @return list<string> */
    private function navigationKeys(array $groups): array
    {
        $keys = [];
        foreach ($groups as $group) {
            foreach ($group['children'] as $item) {
                $keys[] = $item['key'];
                foreach ((array) ($item['children'] ?? []) as $child) {
                    $keys[] = $child['key'];
                }
            }
        }

        return $keys;
    }
}
