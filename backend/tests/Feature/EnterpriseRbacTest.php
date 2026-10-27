<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\RbacManager;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EnterpriseRbacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_super_admin_can_open_bilingual_security_center(): void
    {
        $admin = $this->userWithGlobalRole('SUPER_ADMIN', 'ar');

        $this->actingAs($admin)
            ->get('/admin/security')
            ->assertOk()
            ->assertSee('المستخدمون والأدوار والصلاحيات')
            ->assertSee('dir="rtl"', false);
    }

    public function test_security_center_defaults_to_compact_users_tab_and_splits_governance_surfaces(): void
    {
        $admin = $this->userWithGlobalRole('SUPER_ADMIN', 'en');

        $this->actingAs($admin)
            ->get('/admin/security')
            ->assertOk()
            ->assertSee('data-security-users-table', false)
            ->assertSee('+ New user')
            ->assertDontSee('data-security-governance-dashboard', false)
            ->assertDontSee('data-security-session-registry', false)
            ->assertDontSee('data-security-audit-explorer', false);

        $this->actingAs($admin)
            ->get('/admin/security?section=governance')
            ->assertOk()
            ->assertSee('data-security-governance-dashboard', false)
            ->assertDontSee('data-security-users-table', false);

        $this->actingAs($admin)
            ->get('/admin/security?section=sessions')
            ->assertOk()
            ->assertSee('data-security-session-registry', false)
            ->assertDontSee('data-security-governance-dashboard', false);

        $this->actingAs($admin)
            ->get('/admin/security?section=audit')
            ->assertOk()
            ->assertSee('data-security-audit-explorer', false)
            ->assertDontSee('data-security-users-table', false);
    }

    public function test_super_admin_can_create_customer_service_manager_from_security_center(): void
    {
        $admin = $this->userWithGlobalRole('SUPER_ADMIN', 'en');
        $managerRole = Role::query()->where('code', 'CUSTOMER_SERVICE_MANAGER')->firstOrFail();

        $this->actingAs($admin)
            ->post('/admin/security/users', [
                'name' => 'Customer Service Manager',
                'username' => 'cs.manager',
                'email' => 'cs.manager@example.test',
                'password' => 'SecurePass!2026',
                'password_confirmation' => 'SecurePass!2026',
                'locale' => 'ar',
                'role_id' => $managerRole->id,
                'is_active' => true,
            ])
            ->assertRedirect(route('admin.security.index', ['section' => 'users']));

        $manager = User::query()->where('email', 'cs.manager@example.test')->firstOrFail();

        $this->assertSame('cs.manager', $manager->username);
        $this->assertSame('ar', $manager->locale);
        $this->assertTrue($manager->is_active);
        $this->assertTrue(Hash::check('SecurePass!2026', $manager->password));
        $this->assertTrue($manager->hasRole('CUSTOMER_SERVICE_MANAGER'));
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.created',
            'auditable_type' => User::class,
            'auditable_id' => $manager->id,
            'user_id' => $admin->id,
        ]);
    }

    public function test_user_creation_rolls_back_when_operator_cannot_grant_initial_role(): void
    {
        $operator = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $operatorRole = Role::query()->create([
            'code' => 'USER_ONBOARDING_OPERATOR',
            'name' => 'User Onboarding Operator',
            'scope' => 'global',
            'is_system' => false,
            'is_active' => true,
        ]);
        $operatorRole->permissions()->attach([
            Permission::query()->where('code', 'security.view')->value('id'),
            Permission::query()->where('code', 'users.roles.manage')->value('id'),
        ]);
        $operator->roles()->attach($operatorRole);
        $managerRole = Role::query()->where('code', 'CUSTOMER_SERVICE_MANAGER')->firstOrFail();

        $this->actingAs($operator)
            ->from('/admin/security?section=users')
            ->post('/admin/security/users', [
                'name' => 'Forbidden Manager',
                'username' => 'forbidden.manager',
                'email' => 'forbidden.manager@example.test',
                'password' => 'SecurePass!2026',
                'password_confirmation' => 'SecurePass!2026',
                'locale' => 'en',
                'role_id' => $managerRole->id,
                'is_active' => true,
            ])
            ->assertRedirect('/admin/security?section=users')
            ->assertSessionHasErrors();

        $this->assertDatabaseMissing('users', ['email' => 'forbidden.manager@example.test']);
    }

    public function test_security_center_exposes_localized_searchable_permission_review_controls(): void
    {
        $admin = $this->userWithGlobalRole('SUPER_ADMIN', 'en');

        $this->actingAs($admin)
            ->get('/admin/security?section=roles')
            ->assertOk()
            ->assertSee('Roles — Manage')
            ->assertSee('roles.manage')
            ->assertSee('Search permissions by business name or technical code')
            ->assertSee('Review access impact before saving')
            ->assertSee('data-bulk-action="select"', false)
            ->assertSee('data-impact-summary', false);

        $admin->forceFill(['locale' => 'ar'])->save();

        $this->actingAs($admin->fresh())
            ->get('/admin/security?section=roles')
            ->assertOk()
            ->assertSee('الأدوار — إدارة')
            ->assertSee('ابحث عن صلاحية بالاسم التجاري أو الكود التقني')
            ->assertSee('راجع أثر الصلاحيات قبل الحفظ');
    }

    public function test_delegated_security_operator_is_only_offered_grants_within_own_authority(): void
    {
        $operator = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $operatorRole = Role::query()->create([
            'code' => 'SECURITY_DELEGATE',
            'name' => 'Security Delegate',
            'scope' => 'global',
            'is_system' => false,
            'is_active' => true,
        ]);
        $operatorRole->permissions()->attach([
            Permission::query()->where('code', 'security.view')->value('id'),
            Permission::query()->where('code', 'roles.manage')->value('id'),
            Permission::query()->where('code', 'users.roles.manage')->value('id'),
        ]);
        $operator->roles()->attach($operatorRole);

        $this->actingAs($operator)
            ->get('/admin/security?section=roles')
            ->assertOk()
            ->assertSee('Roles — Manage')
            ->assertSee('User role assignments — Manage')
            ->assertDontSee('platform.manage')
            ->assertDontSee('Platform — Manage')
            ->assertSee('Only permissions within your own effective authority are offered.')
            ->assertSee('This role includes permissions outside your grant authority.');
    }

    public function test_ordinary_admin_without_security_permission_is_denied(): void
    {
        $admin = $this->userWithGlobalRole('B2B_ADMIN');

        $this->actingAs($admin)->get('/admin/security')->assertForbidden();
    }

    public function test_admin_can_deactivate_user_and_revoke_existing_tokens_with_audit(): void
    {
        $admin = $this->userWithGlobalRole('SUPER_ADMIN');
        $target = User::factory()->create(['is_active' => true]);
        $target->createToken('existing-session');

        $this->actingAs($admin)
            ->patch("/admin/security/users/{$target->id}/status", [
                'is_active' => false,
                'reason' => 'Contract ended',
            ])
            ->assertRedirect();

        $target->refresh();

        $this->assertFalse($target->is_active);
        $this->assertNotNull($target->deactivated_at);
        $this->assertSame('Contract ended', $target->deactivation_reason);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $target->id]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.status_changed',
            'auditable_type' => User::class,
            'auditable_id' => $target->id,
            'user_id' => $admin->id,
        ]);
    }

    public function test_deactivated_user_cannot_use_api(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/orders')->assertUnauthorized();
    }

    public function test_last_active_super_admin_cannot_be_deactivated_by_delegated_operator(): void
    {
        $superAdmin = $this->userWithGlobalRole('SUPER_ADMIN');
        $operator = User::factory()->create(['is_active' => true]);
        $role = Role::query()->create([
            'code' => 'SECURITY_OPERATOR',
            'name' => 'Security Operator',
            'scope' => 'global',
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->attach(Permission::query()->where('code', 'users.status.manage')->value('id'));
        $operator->roles()->attach($role);

        Sanctum::actingAs($operator);

        $this->patchJson("/api/v1/admin/security/users/{$superAdmin->id}/status", ['is_active' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_active');

        $this->assertTrue($superAdmin->fresh()->is_active);
    }

    public function test_store_scoped_role_grants_permissions_only_for_assigned_store(): void
    {
        $admin = $this->userWithGlobalRole('SUPER_ADMIN');
        $target = User::factory()->create();
        $storeId = $this->createB2cStore('RBAC-A');
        $otherStoreId = $this->createB2cStore('RBAC-B');
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();

        app(RbacManager::class)->replaceUserRoles(
            $admin,
            $target,
            [],
            [['store_id' => $storeId, 'role_id' => (int) $role->id]],
            Request::create('/test', 'PUT'),
        );

        $this->assertTrue($target->fresh()->hasPermission('catalog.manage', $storeId));
        $this->assertFalse($target->fresh()->hasPermission('catalog.manage'));
        $this->assertFalse($target->fresh()->hasPermission('catalog.manage', $otherStoreId));
    }

    public function test_any_permission_change_fully_reconciles_stale_builtin_rbac_database_state(): void
    {
        $admin = $this->userWithGlobalRole('SUPER_ADMIN');
        $target = $this->userWithGlobalRole('CUSTOMER_SERVICE_SUPERVISOR');
        $agentRole = Role::query()->where('code', 'CUSTOMER_SERVICE_AGENT')->firstOrFail();
        $dispatch = Permission::query()->where('code', 'orders.dispatch')->firstOrFail();
        $manage = Permission::query()->where('code', 'orders.manage')->firstOrFail();
        $customRole = Role::query()->create([
            'code' => 'CUSTOM_PERMISSION_ROLE',
            'name' => 'Custom Permission Role',
            'scope' => 'global',
            'is_system' => false,
            'is_active' => true,
        ]);
        $customPermission = Permission::query()->where('code', 'reports.view')->firstOrFail();
        $customRole->permissions()->attach($customPermission->id);

        DB::table('permission_role')
            ->where('role_id', $agentRole->id)
            ->where('permission_id', $dispatch->id)
            ->delete();

        DB::table('permissions')
            ->where('id', $dispatch->id)
            ->update(['name' => 'STALE LEGACY PERMISSION NAME']);

        DB::table('user_permission_overrides')->insert([
            'user_id' => $target->id,
            'permission_id' => $manage->id,
            'scope_key' => 'legacy-broken-scope',
            'store_id' => null,
            'effect' => 'legacy',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $selected = Permission::query()
            ->whereIn('code', $target->inheritedPermissionCodes())
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->push((int) $manage->id)
            ->unique()
            ->values()
            ->all();

        $this->actingAs($admin)
            ->put(route('admin.security.users.permissions', $target), [
                'scope_type' => 'global',
                'permission_ids' => $selected,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('permission_role', [
            'role_id' => $agentRole->id,
            'permission_id' => $dispatch->id,
        ]);
        $this->assertDatabaseHas('permission_role', [
            'role_id' => $customRole->id,
            'permission_id' => $customPermission->id,
        ]);
        $this->assertDatabaseHas('permissions', [
            'id' => $dispatch->id,
            'code' => 'orders.dispatch',
            'name' => ((array) config('permissions.abilities'))['orders.dispatch'],
        ]);
        $this->assertDatabaseMissing('user_permission_overrides', [
            'user_id' => $target->id,
            'scope_key' => 'legacy-broken-scope',
        ]);
        $this->assertDatabaseHas('user_permission_overrides', [
            'user_id' => $target->id,
            'permission_id' => $manage->id,
            'scope_key' => 'global',
            'effect' => 'allow',
        ]);
        $this->assertTrue($target->fresh()->hasPermission('orders.manage'));
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'rbac.database_reconciled',
            'user_id' => $admin->id,
        ]);
    }

    public function test_super_admin_can_customize_final_user_permissions_without_mutating_role(): void
    {
        $admin = $this->userWithGlobalRole('SUPER_ADMIN');
        $target = $this->userWithGlobalRole('CUSTOMER_SERVICE_SUPERVISOR');
        $dispatch = Permission::query()->where('code', 'orders.dispatch')->firstOrFail();
        $manage = Permission::query()->where('code', 'orders.manage')->firstOrFail();
        $role = Role::query()->where('code', 'CUSTOMER_SERVICE_SUPERVISOR')->firstOrFail();

        $selected = Permission::query()
            ->whereIn('code', $target->inheritedPermissionCodes())
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->reject(fn (int $id): bool => $id === (int) $dispatch->id)
            ->push((int) $manage->id)
            ->unique()
            ->values()
            ->all();

        $this->actingAs($admin)
            ->put(route('admin.security.users.permissions', $target), [
                'scope_type' => 'global',
                'permission_ids' => $selected,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $fresh = $target->fresh();
        $this->assertFalse($fresh->hasPermission('orders.dispatch'));
        $this->assertTrue($fresh->hasPermission('orders.manage'));
        $this->assertTrue((bool) $fresh->has_permission_overrides);
        $this->assertTrue($role->permissions()->where('code', 'orders.dispatch')->exists());
        $this->assertFalse($role->permissions()->where('code', 'orders.manage')->exists());
        $this->assertDatabaseHas('user_permission_overrides', [
            'user_id' => $target->id,
            'permission_id' => $dispatch->id,
            'scope_key' => 'global',
            'effect' => 'deny',
        ]);
        $this->assertDatabaseHas('user_permission_overrides', [
            'user_id' => $target->id,
            'permission_id' => $manage->id,
            'scope_key' => 'global',
            'effect' => 'allow',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.permissions_changed',
            'user_id' => $admin->id,
            'auditable_type' => User::class,
            'auditable_id' => $target->id,
        ]);
    }

    public function test_allow_override_cannot_outlive_its_active_global_role_domain(): void
    {
        $admin = $this->userWithGlobalRole('SUPER_ADMIN');
        $target = $this->userWithGlobalRole('CUSTOMER_SERVICE_SUPERVISOR');
        $ordersManage = Permission::query()->where('code', 'orders.manage')->firstOrFail();
        $role = Role::query()->where('code', 'CUSTOMER_SERVICE_SUPERVISOR')->firstOrFail();

        $selected = Permission::query()
            ->whereIn('code', $target->inheritedPermissionCodes())
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->push((int) $ordersManage->id)
            ->unique()
            ->values()
            ->all();

        app(RbacManager::class)->replaceUserPermissions(
            $admin,
            $target,
            $selected,
            'global',
            null,
            Request::create('/test-permissions', 'PUT'),
        );

        $this->assertTrue($target->fresh()->hasPermission('orders.manage'));

        $role->forceFill(['is_active' => false])->save();

        $fresh = $target->fresh();
        $this->assertFalse($fresh->hasPermission('orders.manage'));
        $this->assertNotContains('orders.manage', $fresh->effectivePermissionCodes());
    }

    public function test_changing_user_role_resets_individual_permission_overrides(): void
    {
        $admin = $this->userWithGlobalRole('SUPER_ADMIN');
        $target = $this->userWithGlobalRole('CUSTOMER_SERVICE_SUPERVISOR');
        $ordersManage = Permission::query()->where('code', 'orders.manage')->firstOrFail();
        $agentRole = Role::query()->where('code', 'CUSTOMER_SERVICE_AGENT')->firstOrFail();

        app(RbacManager::class)->replaceUserPermissions(
            $admin,
            $target,
            [$ordersManage->id],
            'global',
            null,
            Request::create('/test-permissions', 'PUT'),
        );

        $this->assertDatabaseHas('user_permission_overrides', [
            'user_id' => $target->id,
            'permission_id' => $ordersManage->id,
            'scope_key' => 'global',
            'effect' => 'allow',
        ]);

        app(RbacManager::class)->replaceUserRoles(
            $admin,
            $target,
            [(int) $agentRole->id],
            [],
            Request::create('/test-roles', 'PUT'),
        );

        $fresh = $target->fresh();
        $this->assertTrue($fresh->hasRole('CUSTOMER_SERVICE_AGENT'));
        $this->assertFalse($fresh->hasPermission('orders.manage'));
        $this->assertFalse((bool) $fresh->has_permission_overrides);
        $this->assertDatabaseMissing('user_permission_overrides', [
            'user_id' => $target->id,
        ]);
    }

    public function test_store_permission_override_is_isolated_to_selected_store(): void
    {
        $admin = $this->userWithGlobalRole('SUPER_ADMIN');
        $target = User::factory()->create(['is_active' => true]);
        $storeA = $this->createB2cStore('RBAC-OVERRIDE-A');
        $storeB = $this->createB2cStore('RBAC-OVERRIDE-B');
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        $catalogManage = Permission::query()->where('code', 'catalog.manage')->firstOrFail();

        app(RbacManager::class)->replaceUserRoles(
            $admin,
            $target,
            [],
            [
                ['store_id' => $storeA, 'role_id' => (int) $role->id],
                ['store_id' => $storeB, 'role_id' => (int) $role->id],
            ],
            Request::create('/test', 'PUT'),
        );

        $selected = Permission::query()
            ->whereIn('code', $target->fresh()->inheritedPermissionCodes($storeA))
            ->where('id', '!=', $catalogManage->id)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $this->actingAs($admin)
            ->put(route('admin.security.users.permissions', $target), [
                'scope_type' => 'store',
                'store_id' => $storeA,
                'permission_ids' => $selected,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $fresh = $target->fresh();
        $this->assertFalse($fresh->hasPermission('catalog.manage', $storeA));
        $this->assertTrue($fresh->hasPermission('catalog.manage', $storeB));
        $this->assertDatabaseHas('user_permission_overrides', [
            'user_id' => $target->id,
            'permission_id' => $catalogManage->id,
            'scope_key' => 'store:'.$storeA,
            'store_id' => $storeA,
            'effect' => 'deny',
        ]);
        $this->assertDatabaseMissing('user_permission_overrides', [
            'user_id' => $target->id,
            'permission_id' => $catalogManage->id,
            'scope_key' => 'store:'.$storeB,
        ]);
    }

    public function test_retail_user_override_rejects_platform_permission_outside_role_domain(): void
    {
        $admin = $this->userWithGlobalRole('SUPER_ADMIN');
        $target = User::factory()->create(['is_active' => true]);
        $storeId = $this->createB2cStore('RBAC-DOMAIN-GUARD');
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        $systemUpdate = Permission::query()->where('code', 'system.update')->firstOrFail();

        app(RbacManager::class)->replaceUserRoles(
            $admin,
            $target,
            [],
            [['store_id' => $storeId, 'role_id' => (int) $role->id]],
            Request::create('/test', 'PUT'),
        );

        $this->actingAs($admin)
            ->from('/admin/security?section=users')
            ->put(route('admin.security.users.permissions', $target), [
                'scope_type' => 'store',
                'store_id' => $storeId,
                'permission_ids' => [$systemUpdate->id],
            ])
            ->assertRedirect('/admin/security?section=users')
            ->assertSessionHasErrors('permission_ids');

        $this->assertFalse($target->fresh()->hasPermission('system.update', $storeId));
        $this->assertDatabaseMissing('user_permission_overrides', [
            'user_id' => $target->id,
            'permission_id' => $systemUpdate->id,
            'scope_key' => 'store:'.$storeId,
        ]);
    }

    public function test_delegated_security_operator_cannot_add_user_permission_above_own_authority(): void
    {
        $operator = User::factory()->create(['is_active' => true]);
        $operatorRole = Role::query()->create([
            'code' => 'USER_PERMISSION_OPERATOR',
            'name' => 'User Permission Operator',
            'scope' => 'global',
            'is_system' => false,
            'is_active' => true,
        ]);
        $operatorRole->permissions()->attach([
            Permission::query()->where('code', 'security.view')->value('id'),
            Permission::query()->where('code', 'users.roles.manage')->value('id'),
        ]);
        $operator->roles()->attach($operatorRole);

        $target = $this->userWithGlobalRole('CUSTOMER_SERVICE_AGENT');
        $platformManage = Permission::query()->where('code', 'platform.manage')->firstOrFail();

        $this->actingAs($operator)
            ->from('/admin/security?section=users')
            ->put(route('admin.security.users.permissions', $target), [
                'scope_type' => 'global',
                'permission_ids' => [$platformManage->id],
            ])
            ->assertRedirect('/admin/security?section=users')
            ->assertSessionHasErrors('permission_ids');

        $this->assertFalse($target->fresh()->hasPermission('platform.manage'));
        $this->assertDatabaseMissing('user_permission_overrides', [
            'user_id' => $target->id,
            'permission_id' => $platformManage->id,
        ]);
    }

    public function test_security_users_surface_exposes_individual_permission_editor(): void
    {
        $admin = $this->userWithGlobalRole('SUPER_ADMIN', 'en');
        $target = $this->userWithGlobalRole('CUSTOMER_SERVICE_SUPERVISOR', 'en');

        $this->actingAs($admin)
            ->get('/admin/security?section=users&q='.urlencode($target->email))
            ->assertOk()
            ->assertSee('Individual user permissions')
            ->assertSee('Global user permissions')
            ->assertSee('orders.dispatch')
            ->assertSee('data-user-permission-overrides', false)
            ->assertSee(route('admin.security.users.permissions', $target), false);
    }

    public function test_delegated_operator_cannot_grant_permissions_above_own_authority(): void
    {
        $operator = User::factory()->create(['is_active' => true]);
        $operatorRole = Role::query()->create([
            'code' => 'ROLE_OPERATOR',
            'name' => 'Role Operator',
            'scope' => 'global',
            'is_system' => false,
            'is_active' => true,
        ]);
        $operatorRole->permissions()->attach([
            Permission::query()->where('code', 'roles.manage')->value('id'),
            Permission::query()->where('code', 'users.roles.manage')->value('id'),
        ]);
        $operator->roles()->attach($operatorRole);

        $powerfulRole = Role::query()->create([
            'code' => 'POWERFUL',
            'name' => 'Powerful',
            'scope' => 'global',
            'is_system' => false,
            'is_active' => true,
        ]);
        $powerfulRole->permissions()->attach(Permission::query()->where('code', 'platform.manage')->value('id'));
        $target = User::factory()->create();

        $this->expectException(ValidationException::class);

        app(RbacManager::class)->replaceUserRoles(
            $operator,
            $target,
            [(int) $powerfulRole->id],
            [],
            Request::create('/test', 'PUT'),
        );
    }

    public function test_security_api_enforces_permission_and_exposes_effective_permissions(): void
    {
        $denied = $this->userWithGlobalRole('B2B_ADMIN');
        Sanctum::actingAs($denied);
        $this->getJson('/api/v1/admin/security/permissions')->assertForbidden();

        $superAdmin = $this->userWithGlobalRole('SUPER_ADMIN');
        Sanctum::actingAs($superAdmin);

        $response = $this->getJson("/api/v1/admin/security/users/{$superAdmin->id}")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->assertContains('security.view', $response->json('data.effective_permissions'));
    }

    public function test_system_roles_are_seeded_active_with_expected_scope(): void
    {
        $this->assertDatabaseHas('roles', [
            'code' => 'SUPER_ADMIN',
            'scope' => 'global',
            'is_system' => true,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('roles', [
            'code' => 'B2C_STORE_ADMIN',
            'scope' => 'store',
            'is_system' => true,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('permissions', ['code' => 'users.status.manage']);
        $this->assertDatabaseHas('permissions', ['code' => 'reports.export']);
    }

    private function userWithGlobalRole(string $roleCode, string $locale = 'en'): User
    {
        $user = User::factory()->create(['locale' => $locale, 'is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    private function createB2cStore(string $code): int
    {
        $storeTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
