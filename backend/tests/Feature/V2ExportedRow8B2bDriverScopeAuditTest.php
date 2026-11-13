<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\BuiltInJobRoleCatalog;
use App\Services\OperationalTenantScope;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * V2 #2913, exported Table 5 row 8. Historical roles are not live identity
 * evidence. All fixtures are synthetic; no exported account identifier is used.
 */
final class V2ExportedRow8B2bDriverScopeAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_legacy_b2b_driver_role_carries_only_its_historical_grant_not_van_or_retail_authority(): void
    {
        $definition = app(BuiltInJobRoleCatalog::class)->definition('B2B_DRIVER');

        $this->assertIsArray($definition);
        $this->assertSame('field_operations', $definition['domain']);
        $this->assertSame('global', $definition['scope']);
        $this->assertSame('operator', $definition['level']);
        $this->assertSame(['deliveries.b2b.execute'], $definition['permissions']);

        $actor = $this->legacyDriver();

        $this->assertTrue($actor->hasRole('B2B_DRIVER'));
        $this->assertFalse($actor->hasRole('B2C_DRIVER'));
        $this->assertFalse($actor->hasRole('VAN_OPERATOR'));
        $this->assertSame(['deliveries.b2b.execute'], $actor->inheritedPermissionCodes());
        $this->assertSame(['deliveries.b2b.execute'], $actor->effectivePermissionCodes());
        $this->assertTrue($actor->hasPermission('deliveries.b2b.execute'));

        foreach ([
            'deliveries.b2c.execute', 'van.login', 'orders.dispatch',
            'drivers.b2b.manage', 'drivers.b2c.manage', 'users.view',
            'roles.manage', 'security.view', 'finance.view',
        ] as $permission) {
            $this->assertFalse($actor->hasPermission($permission), $permission);
        }

        $this->assertSame(1, $actor->roles()->count());
        $this->assertSame(0, $actor->storeRoleAssignments()->count());

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $presentation = app(BuiltInJobRoleCatalog::class)->presentation('B2B_DRIVER');
            $this->assertNotSame('', trim((string) $presentation['name']));
            $this->assertNotSame('B2B_DRIVER', (string) $presentation['name']);
            $this->assertNotEmpty($presentation['access_boundaries']);
        }
    }

    public function test_legacy_role_does_not_create_a_dashboard_or_store_scoped_principal_in_either_locale(): void
    {
        $wholesale = $this->store('B2B', 'V2-2913-WHOLESALE');
        $retail = $this->store('B2C', 'V2-2913-RETAIL');
        $actor = $this->legacyDriver();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $actor = $actor->fresh();

            $this->assertFalse($actor->hasRole('B2B_DRIVER', $wholesale));
            $this->assertFalse($actor->hasPermission('deliveries.b2b.execute', $wholesale));
            $this->assertFalse($actor->hasPermission('deliveries.b2c.execute', $retail));
            $this->assertSame([], $actor->inheritedPermissionCodes($wholesale));
            $this->assertSame([], $actor->inheritedPermissionCodes($retail));
            $this->assertSame([], app(OperationalTenantScope::class)->allowedStoreIds($actor, 'deliveries.b2b.execute', 'b2b'));
            $this->assertSame([], app(OperationalTenantScope::class)->allowedStoreIds($actor, 'deliveries.b2c.execute', 'b2c'));
            $this->assertSame([], app(AdminNavigation::class)->groupsFor($actor));

            $this->actingAs($actor)
                ->get(route('admin.security.index'))
                ->assertForbidden();
            $this->actingAs($actor)
                ->get(route('admin.b2b.module', ['module' => 'inventory', 'store_id' => $wholesale]))
                ->assertForbidden();

            $retailResponse = $this->actingAs($actor)
                ->get(route('admin.b2c.module', ['module' => 'inventory', 'store_id' => $retail]));
            $this->assertContains($retailResponse->getStatusCode(), [403, 404]);
        }

        $this->assertDatabaseHas('role_user', [
            'user_id' => $actor->id,
            'role_id' => Role::query()->where('code', 'B2B_DRIVER')->firstOrFail()->id,
        ]);
    }

    public function test_legacy_b2b_driver_cannot_use_van_or_retail_driver_runtime_even_with_mobile_token_scopes(): void
    {
        $actor = $this->legacyDriver();

        // Token scope is not a substitute for canonical Van assignment or a
        // B2C driver role plus active Retail driver record.
        Sanctum::actingAs($actor, ['app:van', 'app:driver']);

        $this->getJson('/api/v1/van/wallet')->assertForbidden();
        $this->getJson('/api/v1/driver/wallet')->assertForbidden();

        $this->assertFalse($actor->hasPermission('van.login'));
        $this->assertFalse($actor->hasPermission('deliveries.b2c.execute'));
        $this->assertSame(1, $actor->roles()->count());
    }

    public function test_mobile_only_global_roles_do_not_project_erp_or_admin_navigation(): void
    {
        foreach (['B2B_DRIVER', 'B2C_DRIVER', 'VAN_OPERATOR'] as $roleCode) {
            $user = User::factory()->create(['is_active' => true]);
            $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

            $navigation = app(AdminNavigation::class);
            $this->assertSame([], $navigation->for($user->fresh()), $roleCode);
            $this->assertSame([], $navigation->groupsFor($user->fresh()), $roleCode);
        }
    }

    private function legacyDriver(): User
    {
        $role = Role::query()->where('code', 'B2B_DRIVER')->firstOrFail();
        $this->assertSame('global', $role->scope);
        $this->assertTrue((bool) $role->is_active);

        $actor = User::factory()->create([
            'name' => 'V2 2913 Synthetic Legacy Driver',
            'is_active' => true,
            'locale' => 'en',
        ]);
        $actor->roles()->attach($role->id);

        return $actor->fresh();
    }

    private function store(string $channel, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $channel)->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
