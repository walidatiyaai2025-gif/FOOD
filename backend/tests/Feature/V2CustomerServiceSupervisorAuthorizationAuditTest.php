<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\BuiltInJobRoleCatalog;
use App\Services\OperationalTenantScope;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 #2770 / #2916: a historical export recorded 37 inherited permissions
 * for one customer-service Supervisor. Only synthetic users are tested here;
 * no exported identity or permission fingerprint is embedded in GitHub.
 */
final class V2CustomerServiceSupervisorAuthorizationAuditTest extends TestCase
{
    use RefreshDatabase;

    private const ROLE_CODE = 'CUSTOMER_SERVICE_SUPERVISOR';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_supervisor_catalog_and_seeded_grants_are_consistent_but_do_not_escalate_to_security_finance_or_driver_administration(): void
    {
        $definition = app(BuiltInJobRoleCatalog::class)->definition(self::ROLE_CODE);
        $this->assertIsArray($definition);
        $this->assertSame('customer_service', $definition['domain']);
        $this->assertSame('global', $definition['scope']);
        $this->assertSame('tenant', $definition['business_scope']);
        $this->assertSame('supervisor', $definition['level']);

        $actor = $this->supervisor();
        $inherited = $actor->inheritedPermissionCodes();
        $configured = array_values(array_unique((array) $definition['permissions']));
        sort($configured);

        $this->assertSame($configured, $inherited);
        $this->assertSame($inherited, $actor->effectivePermissionCodes());

        foreach (['orders.view', 'orders.create', 'orders.dispatch', 'customers.view', 'whatsapp.customer_service.supervise'] as $permission) {
            $this->assertTrue($actor->hasPermission($permission), $permission);
        }

        // Dispatch ability does NOT imply Driver/Van registry management, order
        // approval, platform Security or financial maker/checker privileges.
        foreach ([
            'roles.manage',
            'users.delete',
            'users.roles.manage',
            'security.view',
            'finance.view',
            'finance.manage',
            'orders.manage',
            'orders.approve',
            'orders.edit',
            'drivers.b2b.manage',
            'drivers.b2c.view',
            'drivers.tracking.view',
        ] as $permission) {
            $this->assertFalse($actor->hasPermission($permission), $permission);
        }

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $presentation = app(BuiltInJobRoleCatalog::class)->presentation(self::ROLE_CODE);
            $this->assertNotSame('', trim((string) $presentation['name']));
            $this->assertNotSame(self::ROLE_CODE, (string) $presentation['name']);
        }
    }

    public function test_supervisor_wholesale_scope_cannot_leak_to_retail_or_direct_security_urls_in_ar_and_en(): void
    {
        $wholesale = $this->store('B2B', 'V2-2770-B2B');
        $retail = $this->store('B2C', 'V2-2770-B2C');
        $actor = $this->supervisor();
        $scope = app(OperationalTenantScope::class);

        $this->assertTrue($actor->hasRole(self::ROLE_CODE));
        $this->assertFalse($actor->hasRole('SUPER_ADMIN'));
        $this->assertTrue($actor->hasPermission('orders.view', $wholesale));
        $this->assertFalse($actor->hasPermission('orders.view', $retail));
        $this->assertContains($wholesale, $scope->allowedStoreIds($actor, 'orders.view', 'b2b'));
        $this->assertSame([], $scope->allowedStoreIds($actor, 'orders.view', 'b2c'));

        foreach (['ar', 'en'] as $locale) {
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
                ->get(route('admin.sidebar-access.roles'))
                ->assertForbidden();

            $retailResponse = $this->actingAs($actor)->get(route('admin.b2c.module', [
                'module' => 'inventory',
                'store_id' => $retail,
            ]));
            $this->assertContains($retailResponse->getStatusCode(), [403, 404]);
        }
    }

    public function test_supervisor_global_deny_and_deactivation_fail_closed_without_mutating_role_or_user(): void
    {
        $wholesale = $this->store('B2B', 'V2-2770-DENY');
        $actor = $this->supervisor();
        $role = Role::query()->where('code', self::ROLE_CODE)->firstOrFail();
        $original = $role->permissions()->count();

        $this->assertTrue($actor->hasPermission('inventory.view', $wholesale));

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->getKey(),
            'permission_id' => Permission::query()->where('code', 'inventory.view')->firstOrFail()->getKey(),
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

            $this->assertFalse($actor->hasPermission('inventory.view', $wholesale));
            $this->assertNotContains('inventory.view', $actor->effectivePermissionCodes($wholesale));
            $this->assertSame([], app(OperationalTenantScope::class)->allowedStoreIds($actor, 'inventory.view', 'b2b'));

            $response = $this->actingAs($actor)
                ->get(route('admin.b2b.module', ['module' => 'inventory']));
            $this->assertContains($response->getStatusCode(), [403, 404]);
        }

        $actor->forceFill(['is_active' => false])->save();
        $actor = $actor->fresh();

        $this->assertSame([], app(OperationalTenantScope::class)->allowedStoreIds($actor, 'orders.view', 'b2b'));
        $this->assertSame([], app(AdminNavigation::class)->groupsFor($actor));
        $this->assertDatabaseHas('users', ['id' => $actor->getKey()]);
        $this->assertSame($original, $role->fresh()->permissions()->count());
    }

    private function supervisor(): User
    {
        $role = Role::query()->where('code', self::ROLE_CODE)->firstOrFail();
        $this->assertSame('global', $role->scope);
        $this->assertTrue((bool) $role->is_active);

        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach($role->getKey());

        return $actor->fresh();
    }

    private function store(string $channel, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $channel)->value('id'),
            'code' => $code,
            'name' => $code,
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
