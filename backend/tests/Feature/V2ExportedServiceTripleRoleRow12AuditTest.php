<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\WhatsAppModuleState;
use App\Services\CustomerServiceTeamManager;
use App\Services\OperationalTenantScope;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * V2 #2917: a synthetic three-role customer-service account represents the
 * historical T5/R12 overlap without using an exported user's identity.
 *
 * Current-account attestation requires a separate authorized private audit.
 */
final class V2ExportedServiceTripleRoleRow12AuditTest extends TestCase
{
    use RefreshDatabase;

    private const SERVICE_ROLES = [
        'CUSTOMER_SUPPORT',
        'CUSTOMER_SERVICE_AGENT',
        'CUSTOMER_SERVICE_SUPERVISOR',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
        app(WholesalePrincipal::class)->storeId();

        WhatsAppModuleState::query()->findOrFail(1)->forceFill([
            'lifecycle' => 'INTERNAL_TEST',
            'emergency_stop' => true,
            'capabilities' => ['agent_inbox' => true],
            'test_numbers' => [],
        ])->save();
    }

    public function test_triple_customer_service_role_union_keeps_wholesale_workspaces_without_security_or_retail_escalation(): void
    {
        $actor = $this->syntheticTripleRoleActor();
        $roles = Role::query()->whereIn('code', self::SERVICE_ROLES)->get();
        $this->assertCount(count(self::SERVICE_ROLES), $roles);

        $expected = $roles->flatMap(
            static fn (Role $role): array => $role->permissions()->pluck('permissions.code')->all(),
        )->unique()->sort()->values()->all();

        $this->assertSame($expected, $actor->inheritedPermissionCodes());
        $this->assertSame($expected, $actor->effectivePermissionCodes());
        $this->assertSame(count(self::SERVICE_ROLES), $actor->roles()->count());

        foreach (['support.manage', 'orders.create', 'customers.view', 'whatsapp.customer_service.supervise'] as $permission) {
            $this->assertTrue($actor->hasPermission($permission), $permission);
        }

        foreach ([
            'roles.manage',
            'users.delete',
            'users.roles.manage',
            'security.view',
            'finance.approve',
            'finance.post',
            'drivers.b2c.view',
            'customer_service.team.manage',
        ] as $permission) {
            $this->assertFalse($actor->hasPermission($permission), $permission);
        }

        $retailId = $this->retailStore();
        $this->assertFalse($actor->hasRole('SUPER_ADMIN'));
        $this->assertFalse($actor->hasRole('PLATFORM_OWNER'));
        $this->assertFalse($actor->hasPermission('orders.view', $retailId));
        $this->assertSame([], app(OperationalTenantScope::class)->allowedStoreIds($actor, 'orders.view', 'b2c'));
        $this->assertFalse(app(CustomerServiceTeamManager::class)->canManage($actor));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $actor = $actor->fresh();

            $navigation = app(AdminNavigation::class);
            $keys = $this->navigationKeys($navigation->groupsFor($actor));
            $this->assertTrue($navigation->usesCustomerServiceDashboardProfile($actor));
            $this->assertContains('erp_customer_service', $keys);
            $this->assertNotContains('administration_hub', $keys);
            $this->assertNotContains('sidebar_role_access', $keys);
            $this->assertNotContains('b2c_inventory', $keys);

            $this->actingAs($actor)
                ->get(route('admin.erp.customer-service'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            $this->actingAs($actor)
                ->get(route('admin.sidebar-access.roles'))
                ->assertForbidden();

            $this->actingAs($actor)
                ->get(route('admin.b2c.module', ['module' => 'inventory', 'store_id' => $retailId]))
                ->assertNotFound();
        }
    }

    public function test_explicit_deny_cannot_be_bypassed_by_another_customer_service_role_or_supervision_entry_point(): void
    {
        $actor = $this->syntheticTripleRoleActor();
        $permission = Permission::query()->where('code', 'whatsapp.customer_service.supervise')->firstOrFail();

        $this->assertTrue($actor->hasPermission('whatsapp.customer_service.supervise'));
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->getKey(),
            'permission_id' => $permission->getKey(),
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $actor = $actor->fresh();

            $this->assertFalse($actor->hasPermission('whatsapp.customer_service.supervise'));
            $this->assertTrue($actor->hasPermission('whatsapp.conversations.view'));
            $this->assertTrue($actor->hasPermission('support.manage'));
            $this->assertFalse(app(CustomerServiceTeamManager::class)->canManage($actor));

            $this->actingAs($actor)
                ->get(route('admin.whatsapp-supervisor.index'))
                ->assertForbidden();

            $this->actingAs($actor)
                ->get(route('admin.erp.customer-service'))
                ->assertOk();
        }

        $this->assertCount(count(self::SERVICE_ROLES), $actor->roles()->get());

        $actor->forceFill(['is_active' => false])->save();
        $actor = $actor->fresh();

        $this->assertFalse($actor->hasPermission('orders.view'));
        $this->assertSame([], app(AdminNavigation::class)->groupsFor($actor));
        $this->assertSame([], app(OperationalTenantScope::class)->allowedStoreIds($actor, 'orders.view', 'b2b'));
        $this->assertCount(count(self::SERVICE_ROLES), $actor->roles()->get());
        $this->assertDatabaseHas('user_permission_overrides', [
            'user_id' => $actor->getKey(),
            'permission_id' => $permission->getKey(),
            'effect' => 'deny',
        ]);
    }

    public function test_customer_service_dashboard_roles_do_not_become_driver_or_van_app_authority(): void
    {
        $actor = $this->syntheticTripleRoleActor();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $actor = $actor->fresh();

            Sanctum::actingAs($actor, ['app:driver']);
            $this->getJson('/api/v1/driver/wallet')->assertForbidden();
            $this->getJson('/api/v1/van/visits')->assertForbidden();

            Sanctum::actingAs($actor, ['app:van']);
            $this->getJson('/api/v1/van/visits')->assertForbidden();
        }

        $this->assertSame(count(self::SERVICE_ROLES), $actor->fresh()->roles()->count());
        $this->assertTrue((bool) $actor->fresh()->is_active);
    }

    private function syntheticTripleRoleActor(): User
    {
        $roleIds = Role::query()
            ->whereIn('code', self::SERVICE_ROLES)
            ->where('scope', 'global')
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        $this->assertCount(count(self::SERVICE_ROLES), $roleIds);

        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->sync($roleIds);

        return $actor->fresh();
    }

    private function retailStore(): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'V2-2917-OTHER-RETAIL',
            'name' => 'Synthetic unrelated Retail store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
