<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\RbacManager;
use App\Services\SensitivePermissionOverridePolicy;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class V2SensitiveFunctionExceptionPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_sensitive_user_allow_is_rejected_at_actual_admin_http_boundary_without_mutating_access(): void
    {
        $admin = $this->withRole('SUPER_ADMIN');
        $target = $this->withRole('CUSTOMER_SERVICE_SUPERVISOR');
        $financePost = Permission::query()->where('code', 'finance.post')->firstOrFail();

        $selected = Permission::query()
            ->whereIn('code', $target->inheritedPermissionCodes())
            ->pluck('id')
            ->push($financePost->id)
            ->unique()
            ->values()
            ->all();

        $this->actingAs($admin)
            ->from('/admin/security?section=users')
            ->put(route('admin.security.users.permissions', $target), [
                'change_reason' => 'Regression fixture approved RBAC access change',
                'scope_type' => 'global',
                'permission_ids' => $selected,
            ])
            ->assertRedirect('/admin/security?section=users')
            ->assertSessionHasErrors('permission_ids');

        $this->assertFalse($target->fresh()->hasPermission('finance.post'));
        $this->assertDatabaseMissing('user_permission_overrides', [
            'user_id' => $target->id,
            'permission_id' => $financePost->id,
            'effect' => 'allow',
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'user.permissions_changed',
            'auditable_id' => $target->id,
        ]);
    }

    public function test_legacy_sensitive_allow_rows_cannot_grant_route_or_effective_permission(): void
    {
        $target = $this->withRole('CUSTOMER_SERVICE_SUPERVISOR');
        $codes = ['security.view', 'security.approve', 'finance.post', 'orders.manage'];
        $now = now();

        foreach ($codes as $code) {
            DB::table('user_permission_overrides')->insert([
                'user_id' => $target->id,
                'permission_id' => Permission::query()->where('code', $code)->firstOrFail()->id,
                'scope_key' => 'global',
                'store_id' => null,
                'effect' => 'allow',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $target->forceFill(['has_permission_overrides' => true])->save();

        $fresh = $target->fresh();
        foreach (['security.view', 'security.approve', 'finance.post'] as $protected) {
            $this->assertTrue(SensitivePermissionOverridePolicy::requiresIndependentApproval($protected));
            $this->assertFalse($fresh->hasPermission($protected));
            $this->assertNotContains($protected, $fresh->effectivePermissionCodes());
        }

        // Non-sensitive individual exceptions remain supported.
        $this->assertTrue($fresh->hasPermission('orders.manage'));
        $this->assertContains('orders.manage', $fresh->effectivePermissionCodes());

        // The actual Dashboard route must not treat a legacy user ALLOW as security.view.
        $this->actingAs($fresh)
            ->get('/admin/security?section=users')
            ->assertForbidden();
    }

    public function test_sensitive_role_inheritance_remains_valid_and_user_deny_still_wins(): void
    {
        $admin = $this->withRole('SUPER_ADMIN');
        $role = Role::query()->create([
            'code' => 'V2_APPROVED_FINANCE_POSTING',
            'name' => 'V2 approved finance posting',
            'scope' => 'global',
            'is_system' => false,
            'is_active' => true,
        ]);
        $financePost = Permission::query()->where('code', 'finance.post')->firstOrFail();
        $role->permissions()->attach($financePost->id);

        $target = User::factory()->create(['is_active' => true]);
        $target->roles()->attach($role);

        $this->assertTrue($target->fresh()->hasPermission('finance.post'));

        app(RbacManager::class)->replaceUserPermissions(
            $admin,
            $target,
            [],
            'global',
            null,
            Request::create('/test/v2-sensitive-deny', 'PUT'),
        );

        $fresh = $target->fresh();
        $this->assertFalse($fresh->hasPermission('finance.post'));
        $this->assertNotContains('finance.post', $fresh->effectivePermissionCodes());
        $this->assertDatabaseHas('user_permission_overrides', [
            'user_id' => $target->id,
            'permission_id' => $financePost->id,
            'scope_key' => 'global',
            'effect' => 'deny',
        ]);
    }

    public function test_sensitive_capabilities_are_explicit_not_all_write_permissions(): void
    {
        $this->assertTrue(SensitivePermissionOverridePolicy::requiresIndependentApproval('system.update'));
        $this->assertTrue(SensitivePermissionOverridePolicy::requiresIndependentApproval('security.approve'));
        $this->assertTrue(SensitivePermissionOverridePolicy::requiresIndependentApproval('users.delete'));
        $this->assertTrue(SensitivePermissionOverridePolicy::requiresIndependentApproval('finance.approve'));
        $this->assertFalse(SensitivePermissionOverridePolicy::requiresIndependentApproval('orders.manage'));
        $this->assertFalse(SensitivePermissionOverridePolicy::requiresIndependentApproval('catalog.edit'));
    }

    private function withRole(string $code): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', $code)->firstOrFail());

        return $user;
    }
}
