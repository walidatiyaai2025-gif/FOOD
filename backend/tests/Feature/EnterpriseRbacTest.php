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
        $operator = User::factory()->create();
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

    public function test_delegated_operator_cannot_grant_permissions_above_own_authority(): void
    {
        $operator = User::factory()->create();
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
