<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\BuiltInJobRoleCatalog;
use App\Services\OperationalTenantScope;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 #2687 / T122R7: Customer Service Agent is a wholesale tenant operator,
 * not a finance maker/checker, platform administrator or retail store user.
 */
final class V2CustomerServiceAgentRoleAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_seeded_agent_role_has_exact_operational_baseline_and_localized_identity(): void
    {
        $catalog = app(BuiltInJobRoleCatalog::class);
        $definition = $catalog->definition('CUSTOMER_SERVICE_AGENT');

        $this->assertIsArray($definition);
        $this->assertSame('customer_service', $definition['domain']);
        $this->assertSame('global', $definition['scope']);
        $this->assertSame('tenant', $definition['business_scope']);
        $this->assertSame('operator', $definition['level']);

        $expected = [
            'whatsapp.inbox.view', 'whatsapp.conversations.view',
            'whatsapp.conversations.reply', 'whatsapp.conversations.assign',
            'whatsapp.internal_notes.manage', 'stores.view', 'customers.view',
            'orders.view', 'orders.create', 'orders.dispatch',
        ];

        $this->assertEqualsCanonicalizing($expected, $definition['permissions']);
        $role = Role::query()->where('code', 'CUSTOMER_SERVICE_AGENT')->firstOrFail();
        $this->assertTrue((bool) $role->is_system);
        $this->assertEqualsCanonicalizing($expected, $role->permissions()->pluck('code')->all());

        $actor = $this->agent();
        $this->assertEqualsCanonicalizing($expected, $actor->effectivePermissionCodes());
        foreach ([
            'orders.manage', 'orders.edit', 'orders.approve', 'customers.manage',
            'customer_service.team.manage', 'whatsapp.customer_service.supervise',
            'whatsapp.conversations.reassign', 'reports.view', 'reports.export',
            'finance.view', 'finance.manage', 'finance.approve', 'roles.manage',
            'users.roles.manage', 'users.delete', 'security.view', 'platform.manage',
            'drivers.tracking.view', 'drivers.b2b.manage',
        ] as $permission) {
            $this->assertFalse($actor->hasPermission($permission), $permission);
        }

        $names = [];
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $presentation = $catalog->presentation('CUSTOMER_SERVICE_AGENT');
            $this->assertNotEmpty(trim((string) $presentation['name']));
            $this->assertNotEmpty(trim((string) $presentation['description']));
            $names[$locale] = $presentation['name'];
        }
        $this->assertNotSame($names['ar'], $names['en']);
    }

    public function test_agent_can_discover_and_open_wholesale_work_but_not_retail_finance_or_security(): void
    {
        $wholesale = app(WholesalePrincipal::class)->storeId();
        $retail = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'V2-2687-RETAIL',
            'name' => 'V2 2687 Retail',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor = $this->agent();
        $scope = app(OperationalTenantScope::class);

        foreach (['orders.view', 'orders.create', 'orders.dispatch', 'customers.view'] as $ability) {
            $this->assertTrue($actor->hasPermission($ability, $wholesale), $ability);
            $this->assertFalse($actor->hasPermission($ability, $retail), $ability);
            $this->assertContains($wholesale, $scope->allowedStoreIds($actor, $ability, 'b2b'));
            $this->assertSame([], $scope->allowedStoreIds($actor, $ability, 'b2c'));
        }

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $actor = $actor->fresh();
            app()->forgetInstance(AdminNavigation::class);

            $navigation = app(AdminNavigation::class);
            $this->assertTrue($navigation->usesCustomerServiceDashboardProfile($actor));
            $items = collect($navigation->groupsFor($actor))
                ->flatMap(static fn (array $group): array => $group['children'])
                ->keyBy('key');

            foreach (['erp_customer_service', 'customer_360', 'order_operations'] as $key) {
                $this->assertTrue($items->has($key), $locale.' missing '.$key);
            }
            foreach (['administration_hub', 'sidebar_role_access', 'reports_center', 'driver_live_tracking', 'b2c_orders'] as $key) {
                $this->assertFalse($items->has($key), $locale.' exposed '.$key);
            }

            foreach ([
                route('admin.erp.customer-service', [], false),
                route('admin.customer-360.index', [], false),
                route('admin.operations.orders.index', ['channel' => 'b2b'], false),
            ] as $url) {
                $this->actingAs($actor)->get($url)->assertOk()->assertSee('dir="'.$direction.'"', false);
            }

            $this->actingAs($actor)
                ->get(route('admin.operations.orders.index', ['channel' => 'b2c', 'store_id' => $retail], false))
                ->assertForbidden();
            $this->actingAs($actor)->get(route('admin.security.index', [], false))->assertForbidden();
            $this->actingAs($actor)->get(route('admin.reports.index', [], false))->assertForbidden();
        }
    }

    public function test_explicit_order_read_deny_and_inactive_status_fail_closed_without_changing_role(): void
    {
        $wholesale = app(WholesalePrincipal::class)->storeId();
        $actor = $this->agent();
        $role = Role::query()->where('code', 'CUSTOMER_SERVICE_AGENT')->firstOrFail();
        $baselineCount = $role->permissions()->count();

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', 'orders.view')->firstOrFail()->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        $this->assertFalse($actor->fresh()->hasPermission('orders.view', $wholesale));
        $this->assertSame(
            [],
            app(OperationalTenantScope::class)->allowedStoreIds($actor->fresh(), 'orders.view', 'b2b'),
        );
        $this->assertTrue($actor->fresh()->hasPermission('orders.create', $wholesale));

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $this->actingAs($actor->fresh())
                ->get(route('admin.operations.orders.index', ['channel' => 'b2b'], false))
                ->assertForbidden();
        }
        $this->assertSame($baselineCount, $role->fresh()->permissions()->count());

        $actor->forceFill(['is_active' => false])->save();
        $this->assertSame([], app(AdminNavigation::class)->groupsFor($actor->fresh()));
        $this->assertSame(
            [],
            app(OperationalTenantScope::class)->allowedStoreIds($actor->fresh(), 'orders.create', 'b2b'),
        );
        $this->actingAs($actor->fresh())
            ->get(route('admin.erp.customer-service', [], false))
            ->assertForbidden();
    }

    private function agent(): User
    {
        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'CUSTOMER_SERVICE_AGENT')->firstOrFail());

        return $actor->fresh();
    }
}
