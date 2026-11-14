<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\OperationalTenantScope;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 #2767 / T019R01: audit overlapping role assignments using synthetic
 * identities only. The historical exported account is never loaded or altered.
 */
final class V2OverlappingAccountRoleAuthorizationAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_overlapping_global_and_store_roles_keep_wholesale_and_retail_authority_separate(): void
    {
        [$actor, $wholesaleId, $ownedRetailId, $foreignRetailId] = $this->overlappingActor();

        $this->assertTrue($actor->hasRole('CUSTOMER_SERVICE_SUPERVISOR'));
        $this->assertFalse($actor->hasRole('SUPER_ADMIN'));
        $this->assertFalse($actor->hasPermission('roles.manage'));
        $this->assertFalse($actor->hasPermission('users.delete'));

        $this->assertTrue($actor->hasPermission('orders.view', $wholesaleId));
        $this->assertTrue($actor->hasPermission('orders.view', $ownedRetailId));
        $this->assertFalse($actor->hasPermission('orders.view', $foreignRetailId));

        $scope = app(OperationalTenantScope::class);
        $this->assertContains($wholesaleId, $scope->allowedStoreIds($actor, 'orders.view', 'b2b'));
        $this->assertSame([$ownedRetailId], $scope->allowedStoreIds($actor, 'orders.view', 'b2c'));
        $this->assertNotContains($foreignRetailId, $actor->storeRoleAssignments()->pluck('store_id')->all());

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            // A second operational role must not authorize direct Security Center access.
            $this->actingAs($actor->fresh())
                ->get(route('admin.sidebar-access.roles'))
                ->assertForbidden();
        }
    }

    public function test_global_deny_still_wins_over_both_inherited_roles_and_store_allow(): void
    {
        [$actor, $wholesaleId, $ownedRetailId] = $this->overlappingActor();

        $this->override($actor, 'orders.view', 'global', null, 'deny');
        $this->override($actor, 'orders.view', 'store:'.$ownedRetailId, $ownedRetailId, 'allow');
        $actor->forceFill(['has_permission_overrides' => true])->save();
        $actor = $actor->fresh();

        foreach ([$wholesaleId, $ownedRetailId] as $storeId) {
            $this->assertFalse($actor->hasPermission('orders.view', $storeId));
            $this->assertNotContains('orders.view', $actor->effectivePermissionCodes($storeId));
            $this->assertSame('deny', $actor->permissionOverrideEffects($storeId)['orders.view']);
        }

        $scope = app(OperationalTenantScope::class);
        $this->assertSame([], $scope->allowedStoreIds($actor, 'orders.view', 'b2b'));
        $this->assertSame([], $scope->allowedStoreIds($actor, 'orders.view', 'b2c'));

        // A targeted DENY does not remove unrelated authorized capabilities or
        // the historical role rows needed for later account reconciliation.
        $this->assertTrue($actor->hasPermission('customers.view', $wholesaleId));
        $this->assertCount(1, $actor->storeRoleAssignments);
        $this->assertCount(1, $actor->roles);
    }

    public function test_forged_foreign_store_allow_cannot_extend_a_mixed_role_account_and_deactivation_clears_access(): void
    {
        [$actor, $wholesaleId, $ownedRetailId, $foreignRetailId] = $this->overlappingActor();

        $this->override($actor, 'customers.view', 'store:'.$foreignRetailId, $foreignRetailId, 'allow');
        $actor->forceFill(['has_permission_overrides' => true])->save();
        $actor = $actor->fresh();

        $this->assertTrue($actor->hasPermission('customers.view', $wholesaleId));
        $this->assertTrue($actor->hasPermission('customers.view', $ownedRetailId));
        $this->assertFalse($actor->hasPermission('customers.view', $foreignRetailId));
        $this->assertNotContains('customers.view', $actor->effectivePermissionCodes($foreignRetailId));
        $this->assertNotContains(
            $foreignRetailId,
            app(OperationalTenantScope::class)->allowedStoreIds($actor, 'customers.view', 'b2c'),
        );

        $actor->forceFill(['is_active' => false])->save();
        $actor = $actor->fresh();

        $this->assertFalse($actor->hasPermission('orders.view', $wholesaleId));
        $this->assertFalse($actor->hasPermission('customers.view', $ownedRetailId));
        $this->assertSame([], $actor->effectivePermissionCodes($ownedRetailId));
        $this->assertSame([], app(OperationalTenantScope::class)->allowedStoreIds($actor, 'orders.view'));
        $this->assertSame([], app(AdminNavigation::class)->groupsFor($actor));
        $this->assertCount(1, $actor->roles);
        $this->assertCount(1, $actor->storeRoleAssignments);
    }

    /** @return array{0: User, 1: int, 2: int, 3: int} */
    private function overlappingActor(): array
    {
        $wholesaleId = (int) app(WholesalePrincipal::class)->storeId();
        $ownedRetailId = $this->retailStore('V2-2767-OWNED');
        $foreignRetailId = $this->retailStore('V2-2767-FOREIGN');

        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'CUSTOMER_SERVICE_SUPERVISOR')->firstOrFail());

        DB::table('user_store_roles')->insert([
            'user_id' => $actor->getKey(),
            'store_id' => $ownedRetailId,
            'role_id' => Role::query()->where('code', 'RETAIL_CUSTOMER_SUPPORT')->firstOrFail()->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$actor->fresh(), $wholesaleId, $ownedRetailId, $foreignRetailId];
    }

    private function retailStore(string $code): int
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

    private function override(User $actor, string $permission, string $scopeKey, ?int $storeId, string $effect): void
    {
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->getKey(),
            'permission_id' => Permission::query()->where('code', $permission)->firstOrFail()->getKey(),
            'scope_key' => $scopeKey,
            'store_id' => $storeId,
            'effect' => $effect,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
