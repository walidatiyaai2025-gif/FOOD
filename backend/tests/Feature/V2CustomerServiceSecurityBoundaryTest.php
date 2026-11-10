<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\BuiltInJobRoleCatalog;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * V2 T087R06: service staff never inherit user/role administration authority
 * from the Sidebar, their job profile or a direct HTTP request.
 */
final class V2CustomerServiceSecurityBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private const SERVICE_ROLES = [
        'CUSTOMER_SERVICE_MANAGER',
        'CUSTOMER_SERVICE_SUPERVISOR',
        'CUSTOMER_SERVICE_AGENT',
        'CUSTOMER_SUPPORT',
    ];

    private const FORBIDDEN_PERMISSIONS = [
        'security.view',
        'roles.manage',
        'users.view',
        'users.roles.manage',
        'users.status.manage',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        app(WholesalePrincipal::class)->storeId();
    }

    public function test_customer_service_baselines_exclude_user_and_role_administration_but_keep_order_visibility(): void
    {
        $catalog = app(BuiltInJobRoleCatalog::class);

        foreach (self::SERVICE_ROLES as $code) {
            $role = Role::query()->where('code', $code)->firstOrFail();
            $definition = $catalog->definition($code);
            $this->assertNotNull($definition, $code);

            foreach (self::FORBIDDEN_PERMISSIONS as $permission) {
                $this->assertNotContains($permission, $definition['permissions'], $code.' catalog');
                $this->assertFalse($role->permissions()->where('code', $permission)->exists(), $code.' seeded grant: '.$permission);
            }

            $this->assertContains('orders.view', $definition['permissions'], $code.' operational baseline');
            $this->assertTrue($role->permissions()->where('code', 'orders.view')->exists());
        }
    }

    public function test_customer_service_security_links_are_absent_and_real_read_and_write_routes_deny_in_both_languages(): void
    {
        $securityRole = Role::query()->where('code', 'CUSTOMER_SERVICE_AGENT')->firstOrFail();

        foreach (array_slice(self::SERVICE_ROLES, 0, 3) as $code) {
            $actor = $this->userWithRole($code);
            $target = $this->userWithRole('CUSTOMER_SERVICE_AGENT');

            foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
                $actor->forceFill(['locale' => $locale])->save();
                app()->setLocale($locale);
                app()->forgetInstance(AdminNavigation::class);

                $navigation = app(AdminNavigation::class);
                $this->assertTrue($navigation->usesCustomerServiceDashboardProfile($actor));
                $keys = collect($navigation->groupsFor($actor))
                    ->flatMap(static fn (array $group): array => $group['children'])
                    ->pluck('key')->all();

                foreach (['sidebar_role_access', 'sidebar_layout', 'administration_hub', 'platform_owner_modules'] as $item) {
                    $this->assertNotContains($item, $keys, $code.' leaked '.$item);
                }

                $this->assertContains('erp_customer_service', $keys);
                $this->assertTrue($actor->hasPermission('orders.view'), $code);
                $this->actingAs($actor)
                    ->get(route('admin.operations.orders.index', ['channel' => 'b2b']))
                    ->assertOk()
                    ->assertSee('dir="'.$direction.'"', false)
                    ->assertDontSee('href="'.route('admin.sidebar-access.roles').'"', false);

                $this->actingAs($actor)->get(route('admin.security.index'))->assertForbidden();
                $this->actingAs($actor)->get(route('admin.sidebar-access.roles'))->assertForbidden();
                $this->actingAs($actor)->get(route('admin.sidebar-layout.index'))->assertForbidden();

                $this->actingAs($actor)
                    ->put(route('admin.security.users.permissions', $target), [
                        'scope_type' => 'global',
                        'permission_ids' => [],
                    ])->assertForbidden();

                $this->actingAs($actor)
                    ->put(route('admin.security.users.roles', $target), [
                        'global_role_ids' => [$securityRole->id],
                    ])->assertForbidden();

                $this->actingAs($actor)
                    ->patch(route('admin.security.users.status', $target), [
                        'is_active' => false,
                    ])->assertForbidden();

                $this->actingAs($actor)
                    ->put(route('admin.sidebar-access.roles.update', $securityRole), [
                        'item_keys' => [],
                    ])->assertForbidden();

                $this->assertTrue($target->fresh()->is_active);
                $this->assertTrue($target->fresh()->hasRole('CUSTOMER_SERVICE_AGENT'));
                $this->assertDatabaseMissing('audit_logs', [
                    'event' => 'admin.sidebar.role_visibility.updated',
                    'user_id' => $actor->id,
                ]);
                $this->assertDatabaseMissing('user_permission_overrides', [
                    'user_id' => $target->id,
                ]);
            }
        }
    }

    public function test_reconciliation_removes_stale_security_grants_without_disabling_customer_service(): void
    {
        $actor = $this->userWithRole('CUSTOMER_SERVICE_MANAGER');
        $role = Role::query()->where('code', 'CUSTOMER_SERVICE_MANAGER')->firstOrFail();
        $adminPermissions = Permission::query()
            ->whereIn('code', ['security.view', 'roles.manage'])
            ->pluck('id')->all();

        $role->permissions()->syncWithoutDetaching($adminPermissions);
        $this->assertTrue($actor->fresh()->hasPermission('roles.manage'));

        app(BuiltInJobRoleCatalog::class)->reconcile();

        $this->assertFalse($actor->fresh()->hasPermission('roles.manage'));
        $this->assertFalse($actor->fresh()->hasPermission('security.view'));
        $this->assertTrue($actor->fresh()->hasPermission('orders.view'));

        foreach ($adminPermissions as $permissionId) {
            $this->assertDatabaseMissing('permission_role', [
                'role_id' => $role->id,
                'permission_id' => $permissionId,
            ]);
        }
    }

    private function userWithRole(string $code): User
    {
        $user = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', $code)->firstOrFail());

        return $user;
    }
}
