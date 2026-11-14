<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\OperationalTenantScope;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * V2 #3344: deactivation must revoke the *reported* effective grants as well
 * as the actual policy decision, without deleting any historical RBAC rows.
 * Every actor and store below is a disposable synthetic test fixture.
 */
final class V2InactiveEffectivePermissionProjectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_disabled_super_admin_reports_no_effective_permissions_but_keeps_historical_role(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $role = Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail();
        $actor->roles()->attach($role);

        $this->assertTrue($actor->hasPermission('roles.manage'));
        $this->assertContains('roles.manage', $actor->effectivePermissionCodes());

        $actor->forceFill(['is_active' => false, 'deactivated_at' => now()])->save();
        $actor = $actor->fresh();

        $this->assertTrue($actor->hasRole('SUPER_ADMIN'));
        $this->assertContains('roles.manage', $actor->inheritedPermissionCodes());
        $this->assertFalse($actor->hasPermission('roles.manage'));
        $this->assertSame([], $actor->effectivePermissionCodes());
        $this->assertDatabaseHas('role_user', ['user_id' => $actor->id, 'role_id' => $role->id]);

        $actor->forceFill(['is_active' => true, 'deactivated_at' => null])->save();
        $this->assertContains('roles.manage', $actor->fresh()->effectivePermissionCodes());
    }

    public function test_deactivation_masks_legacy_global_allow_in_arabic_and_english_without_erasing_audit_rows(): void
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $role = Role::query()->where('code', 'CUSTOMER_SERVICE_SUPERVISOR')->firstOrFail();
        $actor->roles()->attach($role);

        $ability = Permission::query()->where('code', 'orders.manage')->firstOrFail();
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => $ability->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'allow',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
        $actor = $actor->fresh();

        $this->assertNotSame([], $actor->inheritedPermissionCodes());
        $this->assertContains('orders.manage', $actor->effectivePermissionCodes());

        $actor->forceFill(['is_active' => false, 'deactivated_at' => now()])->save();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $inactive = $actor->fresh();

            $this->assertFalse($inactive->hasPermission('orders.manage'));
            $this->assertSame([], $inactive->effectivePermissionCodes());
            $this->assertNotSame([], $inactive->inheritedPermissionCodes());

            // Existing authenticated API middleware must still deny revoked users.
            Sanctum::actingAs($inactive);
            $this->getJson('/api/v1/orders')->assertUnauthorized();
        }

        $this->assertDatabaseHas('role_user', ['user_id' => $actor->id, 'role_id' => $role->id]);
        $this->assertDatabaseHas('user_permission_overrides', [
            'user_id' => $actor->id,
            'permission_id' => $ability->id,
            'scope_key' => 'global',
            'effect' => 'allow',
        ]);
        $this->assertDatabaseHas('users', ['id' => $actor->id, 'is_active' => false]);

        $actor->forceFill(['is_active' => true, 'deactivated_at' => null])->save();
        $this->assertContains('orders.manage', $actor->fresh()->effectivePermissionCodes());
    }

    public function test_inactive_retail_store_admin_has_no_effective_store_grants_and_cannot_cross_tenants(): void
    {
        $storeId = $this->store('V2-3344-RETAIL-ONE');
        $otherStoreId = $this->store('V2-3344-RETAIL-TWO');

        $actor = User::factory()->create(['is_active' => true]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor = $actor->fresh();

        $this->assertTrue($actor->hasPermission('catalog.manage', $storeId));
        $this->assertContains('catalog.manage', $actor->effectivePermissionCodes($storeId));
        $this->assertFalse($actor->hasPermission('catalog.manage', $otherStoreId));

        $actor->forceFill(['is_active' => false, 'deactivated_at' => now()])->save();
        $actor = $actor->fresh();

        $this->assertFalse($actor->hasPermission('catalog.manage', $storeId));
        $this->assertSame([], $actor->effectivePermissionCodes());
        $this->assertSame([], $actor->effectivePermissionCodes($storeId));
        $this->assertSame([], $actor->effectivePermissionCodes($otherStoreId));
        $this->assertContains('catalog.manage', $actor->inheritedPermissionCodes($storeId));
        $this->assertSame([], app(OperationalTenantScope::class)->allowedStoreIds($actor, 'catalog.manage', 'b2c'));
        $this->assertDatabaseHas('user_store_roles', [
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
        ]);

        $actor->forceFill(['is_active' => true, 'deactivated_at' => null])->save();
        $restored = $actor->fresh();
        $this->assertContains('catalog.manage', $restored->effectivePermissionCodes($storeId));
        $this->assertNotContains('catalog.manage', $restored->effectivePermissionCodes($otherStoreId));
    }

    private function store(string $code): int
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
}
