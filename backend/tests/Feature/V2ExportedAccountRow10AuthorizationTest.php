<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\OperationalTenantScope;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 #2915 (native Table 5, data row 10).
 *
 * These are disposable synthetic accounts. The archived row's actual role,
 * identity, live assignments and permission overrides remain unverified;
 * never substitute these fixtures for a private account reconciliation.
 */
final class V2ExportedAccountRow10AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_retail_only_account_does_not_inherit_another_store_wholesale_or_admin_permissions(): void
    {
        $ownStore = $this->createRetailStore('OWN');
        $foreignStore = $this->createRetailStore('FOREIGN');
        $user = $this->retailSupportActor($ownStore);
        $scope = app(OperationalTenantScope::class);

        $this->assertTrue($user->hasRole('RETAIL_CUSTOMER_SUPPORT', $ownStore));
        $this->assertFalse($user->hasRole('RETAIL_CUSTOMER_SUPPORT', $foreignStore));
        $this->assertTrue($user->hasPermission('orders.view', $ownStore));
        $this->assertFalse($user->hasPermission('orders.view', $foreignStore));
        $this->assertFalse($user->hasPermission('orders.view'));
        $this->assertFalse($user->hasPermission('users.delete', $ownStore));
        $this->assertSame([$ownStore], $scope->allowedStoreIds($user, 'orders.view', 'b2c'));
        $this->assertSame([], $scope->allowedStoreIds($user, 'orders.view', 'b2b'));

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $user->forceFill(['locale' => $locale])->save();
            $user = $user->fresh();

            $keys = $this->navigationKeys(app(AdminNavigation::class)->groupsFor($user));
            $this->assertNotContains('b2b_orders', $keys);
            $this->assertNotContains('sidebar_role_access', $keys);

            $this->actingAs($user)->get('/admin/b2b/orders')->assertForbidden();
            $this->actingAs($user)->get(route('admin.security.index'))->assertForbidden();
        }

        // An historical store assignment is retained, not silently moved.
        $this->assertDatabaseHas('user_store_roles', [
            'user_id' => $user->getKey(),
            'store_id' => $ownStore,
        ]);
        $this->assertDatabaseMissing('user_store_roles', [
            'user_id' => $user->getKey(),
            'store_id' => $foreignStore,
        ]);
    }

    public function test_global_deny_overrides_store_role_and_legacy_store_allow_without_deleting_audit_rows(): void
    {
        $ownStore = $this->createRetailStore('DENY');
        $user = $this->retailSupportActor($ownStore);
        $permissionId = (int) Permission::query()->where('code', 'orders.view')->firstOrFail()->getKey();

        DB::table('user_permission_overrides')->insert([
            [
                'user_id' => $user->getKey(),
                'permission_id' => $permissionId,
                'scope_key' => 'global',
                'store_id' => null,
                'effect' => 'deny',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $user->getKey(),
                'permission_id' => $permissionId,
                'scope_key' => 'store:'.$ownStore,
                'store_id' => $ownStore,
                'effect' => 'allow',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        $user->forceFill(['has_permission_overrides' => true])->save();
        $user = $user->fresh();

        $this->assertContains('orders.view', $user->inheritedPermissionCodes($ownStore));
        $this->assertFalse($user->hasPermission('orders.view', $ownStore));
        $this->assertNotContains('orders.view', $user->effectivePermissionCodes($ownStore));
        $this->assertSame([], app(OperationalTenantScope::class)->allowedStoreIds($user, 'orders.view', 'b2c'));
        $this->assertSame(2, DB::table('user_permission_overrides')->where('user_id', $user->getKey())->count());

        // Revocation must preserve the identity and historical role/override trail.
        $user->forceFill(['is_active' => false])->save();
        $user = $user->fresh();
        $this->assertFalse($user->hasPermission('orders.view', $ownStore));
        $this->assertSame([], app(OperationalTenantScope::class)->allowedStoreIds($user, 'orders.view', 'b2c'));
        $this->assertDatabaseHas('users', ['id' => $user->getKey(), 'is_active' => false]);
        $this->assertDatabaseHas('user_store_roles', ['user_id' => $user->getKey(), 'store_id' => $ownStore]);
        $this->assertSame(2, DB::table('user_permission_overrides')->where('user_id', $user->getKey())->count());
    }

    private function createRetailStore(string $suffix): int
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => 'V2-T5R10-'.$suffix,
            'name' => 'Synthetic retail account scope '.$suffix,
            'business_profile' => 'RETAIL_STORE',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function retailSupportActor(int $storeId): User
    {
        $user = User::factory()->create([
            'name' => 'Synthetic Row10 Retail Support',
            'email' => 'v2-t5r10-synthetic@example.test',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'RETAIL_CUSTOMER_SUPPORT')->firstOrFail();

        DB::table('user_store_roles')->insert([
            'user_id' => $user->getKey(),
            'store_id' => $storeId,
            'role_id' => $role->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user->fresh();
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
