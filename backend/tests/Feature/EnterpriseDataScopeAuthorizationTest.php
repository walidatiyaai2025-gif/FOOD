<?php

namespace Tests\Feature;

use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\EnterpriseScopeAuthorizer;
use App\Support\TenantContextResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class EnterpriseDataScopeAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_retail_context_derives_enterprise_scope_from_server_side_store_mapping(): void
    {
        [$user, $store] = $this->retailUserWithPermission('reports.view', 'R-SCOPE-A');

        $context = app(TenantContextResolver::class)->retail($user, $store->id);

        $this->assertNotNull($context->enterpriseScope);
        $this->assertSame($store->id, $context->enterpriseScope->storeId);

        $hierarchy = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertNotNull($hierarchy);
        $this->assertSame($hierarchy['organization_id'], $context->enterpriseScope->organizationId);
        $this->assertSame($hierarchy['business_unit_id'], $context->enterpriseScope->businessUnitId);
    }

    public function test_permission_and_scope_are_both_required(): void
    {
        [$user, $store] = $this->retailUserWithPermission('reports.view', 'R-SCOPE-B');
        $context = app(TenantContextResolver::class)->retail($user, $store->id);
        $scope = $context->enterpriseScope;
        $this->assertNotNull($scope);

        $authorizer = app(EnterpriseScopeAuthorizer::class);

        $this->assertTrue($authorizer->allows(
            $user,
            $context,
            'reports.view',
            'business_unit',
            $scope->businessUnitId,
        ));
        $this->assertFalse($authorizer->allows(
            $user,
            $context,
            'finance.manage',
            'business_unit',
            $scope->businessUnitId,
        ));
        $this->assertFalse($authorizer->allows(
            $user,
            $context,
            'reports.view',
            'business_unit',
            $scope->businessUnitId + 9999,
        ));
    }

    public function test_cross_store_scope_is_denied_even_when_permission_exists(): void
    {
        [$user, $storeA] = $this->retailUserWithPermission('reports.view', 'R-SCOPE-C');
        [, $storeB] = $this->retailUserWithPermission('reports.view', 'R-SCOPE-D');

        $context = app(TenantContextResolver::class)->retail($user, $storeA->id);

        $this->assertFalse(app(EnterpriseScopeAuthorizer::class)->allows(
            $user,
            $context,
            'reports.view',
            'store',
            $storeB->id,
        ));
    }

    public function test_unknown_scope_type_denies_by_default(): void
    {
        [$user, $store] = $this->retailUserWithPermission('reports.view', 'R-SCOPE-E');
        $context = app(TenantContextResolver::class)->retail($user, $store->id);

        $this->assertFalse(app(EnterpriseScopeAuthorizer::class)->allows(
            $user,
            $context,
            'reports.view',
            'client_supplied_scope',
            $store->id,
        ));
    }

    public function test_super_admin_retail_bypass_still_requires_explicit_support_access(): void
    {
        $store = $this->createScopedRetailStore('R-SCOPE-F');
        $role = Role::query()->create([
            'code' => 'SUPER_ADMIN',
            'name' => 'Super Admin',
            'scope' => 'global',
            'is_active' => true,
            'is_system' => true,
        ]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->roles()->attach($role->id);

        $this->expectException(HttpException::class);
        app(TenantContextResolver::class)->retail($admin, $store->id, false);
    }

    /**
     * @return array{User, Store}
     */
    private function retailUserWithPermission(string $permissionCode, string $storeCode): array
    {
        $permission = Permission::query()->firstOrCreate(
            ['code' => $permissionCode],
            ['name' => $permissionCode],
        );
        $role = Role::query()->create([
            'code' => 'ROLE_'.strtoupper(str_replace('.', '_', $permissionCode)).'_'.$storeCode,
            'name' => $permissionCode,
            'scope' => 'store',
            'is_active' => true,
            'is_system' => false,
        ]);
        $role->permissions()->attach($permission->id);

        $user = User::factory()->create(['is_active' => true]);
        $store = $this->createScopedRetailStore($storeCode);

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$user, $store];
    }

    private function createScopedRetailStore(string $code): Store
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        if ($typeId < 1) {
            $typeId = DB::table('store_types')->insertGetId([
                'code' => 'B2C',
                'name' => 'Retail',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $store = Store::query()->create([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);

        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }
}
