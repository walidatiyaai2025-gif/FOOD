<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\OperationalTenantScope;
use App\Services\RbacManager;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ChannelTenantIsolationAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_wholesale_global_operations_never_inherit_retail_store_access(): void
    {
        $wholesale = $this->store('B2B', 'ISO-WHOLESALE');
        $retail = $this->store('B2C', 'ISO-RETAIL');
        $user = $this->globalUser('OPERATIONS', 'wholesale-ops@example.test');

        $scope = app(OperationalTenantScope::class);

        $this->assertSame([$wholesale], $scope->allowedStoreIds($user, 'orders.manage', 'b2b'));
        $this->assertSame([], $scope->allowedStoreIds($user, 'orders.manage', 'b2c'));
        $this->assertTrue($user->hasPermission('orders.manage', $wholesale));
        $this->assertFalse($user->hasPermission('orders.manage', $retail));
        $this->assertFalse($user->hasPermission('drivers.b2c.manage', $retail));
    }

    public function test_retail_role_is_limited_to_exact_assigned_store_and_never_wholesale(): void
    {
        $storeA = $this->store('B2C', 'ISO-RETAIL-A');
        $storeB = $this->store('B2C', 'ISO-RETAIL-B');
        $wholesale = $this->store('B2B', 'ISO-WHOLESALE-B');
        $user = User::query()->create([
            'name' => 'Retail Operations',
            'email' => 'retail-ops@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);

        $role = Role::query()->where('code', 'RETAIL_OPERATIONS')->firstOrFail();
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeA,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $scope = app(OperationalTenantScope::class);

        $this->assertSame([$storeA], $scope->allowedStoreIds($user, 'orders.manage', 'b2c'));
        $this->assertSame([], $scope->allowedStoreIds($user, 'orders.manage', 'b2b'));
        $this->assertTrue($user->hasPermission('orders.manage', $storeA));
        $this->assertFalse($user->hasPermission('orders.manage', $storeB));
        $this->assertFalse($user->hasPermission('orders.manage', $wholesale));

        $this->actingAs($user)
            ->get('/admin/b2c/orders?store_id='.$storeB)
            ->assertStatus(404);

        $this->actingAs($user)
            ->get('/admin/b2b/orders')
            ->assertForbidden();
    }

    public function test_rbac_rejects_store_role_assignment_to_wholesale_store(): void
    {
        $actor = $this->globalUser('SUPER_ADMIN', 'owner-rbac@example.test');
        $target = User::query()->create([
            'name' => 'Target',
            'email' => 'target-rbac@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $wholesale = $this->store('B2B', 'ISO-RBAC-WHOLESALE');
        $retailRole = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();

        $this->expectException(ValidationException::class);

        app(RbacManager::class)->replaceUserRoles(
            $actor,
            $target,
            [],
            [['store_id' => $wholesale, 'role_id' => (int) $retailRole->id]],
            Request::create('/admin/security/users/'.$target->id.'/roles', 'PUT'),
        );
    }

    public function test_custom_roles_cannot_mix_wholesale_and_retail_permissions(): void
    {
        $actor = $this->globalUser('SUPER_ADMIN', 'owner-role-domain@example.test');
        $b2bPermission = Permission::query()->where('code', 'b2b.accounts.manage')->firstOrFail();

        try {
            app(RbacManager::class)->createRole($actor, 'BAD_RETAIL_ROLE', [
                'name' => 'Bad Retail Role',
                'description' => null,
                'scope' => 'store',
                'is_active' => true,
                'permission_ids' => [(int) $b2bPermission->id],
            ], Request::create('/admin/security/roles', 'POST'));

            $this->fail('Retail role accepted a wholesale-only permission.');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('roles', ['code' => 'BAD_RETAIL_ROLE']);
        }

        $retailPermission = Permission::query()->where('code', 'drivers.b2c.manage')->firstOrFail();

        $this->expectException(ValidationException::class);
        app(RbacManager::class)->createRole($actor, 'BAD_GLOBAL_ROLE', [
            'name' => 'Bad Global Role',
            'description' => null,
            'scope' => 'global',
            'is_active' => true,
            'permission_ids' => [(int) $retailPermission->id],
        ], Request::create('/admin/security/roles', 'POST'));
    }

    public function test_security_assignment_ui_lists_only_retail_stores(): void
    {
        $owner = $this->globalUser('SUPER_ADMIN', 'owner-security-ui@example.test');
        $this->store('B2B', 'WHOLESALE-SHOULD-NOT-APPEAR');
        $this->store('B2C', 'RETAIL-SHOULD-APPEAR');

        $this->actingAs($owner)
            ->get('/admin/security')
            ->assertOk()
            ->assertSee('RETAIL-SHOULD-APPEAR')
            ->assertDontSee('WHOLESALE-SHOULD-NOT-APPEAR');
    }

    public function test_lookup_visibility_is_permission_scoped_inside_each_retail_store(): void
    {
        $storeA = $this->store('B2C', 'LOOKUP-RETAIL-A');
        $storeB = $this->store('B2C', 'LOOKUP-RETAIL-B');
        $user = User::query()->create([
            'name' => 'Scoped Lookup User',
            'email' => 'scoped-lookups@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);

        $this->assignStoreRole($user, $storeA, 'RETAIL_INVENTORY');
        $this->assignStoreRole($user, $storeB, 'RETAIL_OPERATIONS');

        $this->unit('LOOK-A', 'Store A Unit', 'store', $storeA);
        $this->unit('LOOK-B', 'Store B Unit', 'store', $storeB);
        $this->unit('LOOK-W', 'Wholesale Unit', 'b2b', null);

        $this->actingAs($user)
            ->get('/admin/lookups?type=units')
            ->assertOk()
            ->assertSee('Store A Unit')
            ->assertDontSee('Store B Unit')
            ->assertDontSee('Wholesale Unit');
    }

    public function test_wholesale_inventory_role_can_use_wholesale_catalog_and_lookups_only(): void
    {
        $wholesale = $this->store('B2B', 'WHOLESALE-INVENTORY');
        $retail = $this->store('B2C', 'RETAIL-HIDDEN-FROM-WHOLESALE');
        $user = $this->globalUser('INVENTORY', 'wholesale-inventory@example.test');

        $this->unit('WHOLESALE-U', 'Wholesale Visible Unit', 'b2b', null);
        $this->unit('RETAIL-U', 'Retail Hidden Unit', 'store', $retail);

        $this->actingAs($user)
            ->get('/admin/lookups?type=units')
            ->assertOk()
            ->assertSee('Wholesale Visible Unit')
            ->assertDontSee('Retail Hidden Unit');

        $this->actingAs($user)
            ->get('/admin/catalog?tab=products&store_id='.$wholesale)
            ->assertOk();

        $this->actingAs($user)
            ->get('/admin/catalog?tab=products&store_id='.$retail)
            ->assertNotFound();
    }

    public function test_fresh_rbac_contains_no_mixed_scope_roles(): void
    {
        $this->assertDatabaseMissing('roles', ['scope' => 'both']);
    }

    public function test_user_facing_channel_labels_use_retail_not_b2c(): void
    {
        app()->setLocale('ar');
        $this->assertSame('إدارة التجزئة', __('admin.channels.b2c'));
        $this->assertSame('التجزئة', __('notifications.channel_options.b2c'));
        $this->assertStringNotContainsStringIgnoringCase('b2c', (string) __('admin.b2c_workspace.title'));

        app()->setLocale('en');
        $this->assertSame('Retail', __('admin.channels.b2c'));
        $this->assertSame('Retail', __('notifications.channel_options.b2c'));
        $this->assertSame('Retail Management', __('admin.b2c_workspace.title'));
    }

    private function store(string $type, string $code): int
    {
        $typeId = (int) DB::table('store_types')->where('code', $type)->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assignStoreRole(User $user, int $storeId, string $roleCode): void
    {
        $roleId = (int) Role::query()->where('code', $roleCode)->value('id');

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function unit(string $code, string $name, string $scope, ?int $storeId): void
    {
        DB::table('units')->insert([
            'store_id' => $storeId,
            'scope' => $scope,
            'scope_key' => $scope === 'store' ? 'store:'.$storeId : $scope,
            'code' => $code,
            'name' => $name,
            'name_ar' => $name,
            'name_en' => $name,
            'decimal_places' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function globalUser(string $roleCode, string $email): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => $email,
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }
}
