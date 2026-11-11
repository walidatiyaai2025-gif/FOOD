<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\OperationalTenantScope;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 P091: a scoped ALLOW remains inside its assigned Retail store and cannot
 * bypass a global DENY, inactive role or a B2B/B2C permission boundary.
 */
final class V2ScopedAllowTenantBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_security_center_store_allow_opens_only_the_assigned_retail_store_in_both_locales(): void
    {
        $storeA = $this->retailStore('V2-P091-A');
        $storeB = $this->retailStore('V2-P091-B');
        $wholesale = $this->wholesaleStore();
        $actor = $this->retailInventoryUser([$storeA, $storeB]);
        $admin = $this->superAdmin();

        $this->assertFalse($actor->hasPermission('orders.view', $storeA));
        $this->assertFalse($actor->hasPermission('orders.view', $storeB));

        // The existing Super Admin Security Center writes a real scoped ALLOW
        // without removing inherited permissions from the role template.
        $selected = Permission::query()
            ->whereIn('code', [...$actor->inheritedPermissionCodes($storeA), 'orders.view'])
            ->pluck('id')
            ->all();

        $this->actingAs($admin)
            ->put(route('admin.security.users.permissions', $actor), [
                'change_reason' => 'Regression fixture approved RBAC access change',
                'scope_type' => 'store',
                'store_id' => $storeA,
                'permission_ids' => $selected,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('user_permission_overrides', [
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', 'orders.view')->firstOrFail()->id,
            'scope_key' => 'store:'.$storeA,
            'effect' => 'allow',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.permissions_changed',
            'user_id' => $admin->id,
            'auditable_id' => $actor->id,
        ]);
        $this->assertTrue($actor->fresh()->hasPermission('orders.view', $storeA));
        $this->assertFalse($actor->fresh()->hasPermission('orders.view', $storeB));
        $this->assertFalse($actor->fresh()->hasPermission('orders.view', $wholesale));
        $this->assertContains('orders.view', $actor->fresh()->effectivePermissionCodes($storeA));
        $this->assertNotContains('orders.view', $actor->fresh()->effectivePermissionCodes($storeB));

        $scopes = app(OperationalTenantScope::class);
        $this->assertSame([$storeA], $scopes->allowedStoreIds($actor->fresh(), 'orders.view', 'b2c'));
        $this->assertSame([], $scopes->allowedStoreIds($actor->fresh(), 'orders.view', 'b2b'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $this->actingAs($actor->fresh())
                ->get('/admin/b2c/orders?store_id='.$storeA)
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);
            $this->actingAs($actor->fresh())
                ->get('/admin/b2c/orders?store_id='.$storeB)
                ->assertNotFound();
            $this->actingAs($actor->fresh())
                ->get('/admin/b2b/orders')
                ->assertForbidden();
        }

        // A legitimate single-user ALLOW never mutates the shared role.
        $this->assertFalse(
            Role::query()->where('code', 'RETAIL_INVENTORY')
                ->firstOrFail()->permissions()->where('code', 'orders.view')->exists(),
        );
    }

    public function test_forged_foreign_and_cross_channel_allow_cannot_grant_real_access(): void
    {
        $assigned = $this->retailStore('V2-P091-OWNED');
        $foreign = $this->retailStore('V2-P091-FOREIGN');
        $wholesale = $this->wholesaleStore();
        $actor = $this->retailInventoryUser([$assigned]);

        // Forged legacy rows must not expand a Retail user's tenant or domain.
        $this->insertOverride($actor, 'orders.view', 'store:'.$foreign, $foreign, 'allow');
        $this->insertOverride($actor, 'orders.view', 'store:'.$wholesale, $wholesale, 'allow');
        $this->insertOverride($actor, 'b2b.accounts.view', 'store:'.$assigned, $assigned, 'allow');

        $user = $actor->fresh();
        $scope = app(OperationalTenantScope::class);
        $this->assertSame([], $scope->allowedStoreIds($user, 'orders.view', 'b2c'));
        $this->assertSame([], $scope->allowedStoreIds($user, 'orders.view', 'b2b'));
        $this->assertFalse($user->hasPermission('orders.view', $foreign));
        $this->assertFalse($user->hasPermission('orders.view', $wholesale));
        $this->assertFalse($user->hasPermission('b2b.accounts.view', $assigned));
        $this->assertNotContains('b2b.accounts.view', $user->effectivePermissionCodes($assigned));

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $user->forceFill(['locale' => $locale])->save();
            $this->actingAs($user->fresh())
                ->get('/admin/b2c/orders?store_id='.$foreign)
                ->assertForbidden();
            $this->actingAs($user->fresh())
                ->get('/admin/b2b/orders')
                ->assertForbidden();
        }
    }

    public function test_global_deny_and_revoked_store_assignment_override_scoped_allow(): void
    {
        $store = $this->retailStore('V2-P091-DENY');
        $actor = $this->retailInventoryUser([$store]);

        $this->insertOverride($actor, 'orders.view', 'store:'.$store, $store, 'allow');
        $this->assertTrue($actor->fresh()->hasPermission('orders.view', $store));
        $this->assertSame(
            [$store],
            app(OperationalTenantScope::class)->allowedStoreIds($actor->fresh(), 'orders.view', 'b2c'),
        );

        $this->insertOverride($actor, 'orders.view', 'global', null, 'deny');
        $this->assertFalse($actor->fresh()->hasPermission('orders.view', $store));
        $this->assertNotContains('orders.view', $actor->fresh()->effectivePermissionCodes($store));
        $this->assertSame([], app(OperationalTenantScope::class)
            ->allowedStoreIds($actor->fresh(), 'orders.view', 'b2c'));

        DB::table('user_permission_overrides')
            ->where('user_id', $actor->id)
            ->where('scope_key', 'global')
            ->delete();
        DB::table('user_store_roles')->where('user_id', $actor->id)->delete();

        $this->assertFalse($actor->fresh()->hasPermission('orders.view', $store));
        $this->assertSame([], app(OperationalTenantScope::class)
            ->allowedStoreIds($actor->fresh(), 'orders.view', 'b2c'));
        $this->actingAs($actor->fresh())
            ->get('/admin/b2c/orders?store_id='.$store)
            ->assertForbidden();
    }

    /** @param list<int> $storeIds */
    private function retailInventoryUser(array $storeIds): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $roleId = Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail()->id;

        foreach ($storeIds as $storeId) {
            DB::table('user_store_roles')->insert([
                'user_id' => $actor->id,
                'store_id' => $storeId,
                'role_id' => $roleId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $actor->fresh();
    }

    private function superAdmin(): User
    {
        $admin = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $admin;
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

    private function wholesaleStore(): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2B')->value('id'),
            'code' => 'V2-P091-WHOLESALE',
            'name' => 'P091 Wholesale',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertOverride(
        User $actor,
        string $permissionCode,
        string $scopeKey,
        ?int $storeId,
        string $effect,
    ): void {
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', $permissionCode)->firstOrFail()->id,
            'scope_key' => $scopeKey,
            'store_id' => $storeId,
            'effect' => $effect,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
    }
}
