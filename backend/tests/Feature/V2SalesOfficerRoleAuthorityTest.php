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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 #2684 / T122R04 — Sales Officer is a wholesale sales operator,
 * not a Retail operator, approver, dispatcher or financial administrator.
 */
final class V2SalesOfficerRoleAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_sales_officer_has_the_canonical_bilingual_least_privilege_bundle(): void
    {
        $catalog = app(BuiltInJobRoleCatalog::class);
        $definition = $catalog->definition('SALES_OFFICER');

        $this->assertIsArray($definition);
        $this->assertSame('sales', $definition['domain']);
        $this->assertSame('tenant', $definition['business_scope']);
        $this->assertSame('global', $definition['scope']);
        $this->assertSame('operator', $definition['level']);
        $this->assertSame([], $definition['required_modules']);

        $expected = [
            'b2b.accounts.view',
            'b2b.pricing.view',
            'orders.view',
            'orders.edit',
            'customers.view',
            'customers.edit',
        ];

        $this->assertEqualsCanonicalizing($expected, $definition['permissions']);

        $role = Role::query()->where('code', 'SALES_OFFICER')->firstOrFail();
        $this->assertTrue((bool) $role->is_system);
        $this->assertEqualsCanonicalizing($expected, $role->permissions()->pluck('code')->all());

        foreach (['ar' => 'مسؤول المبيعات', 'en' => 'Sales Officer'] as $locale => $name) {
            app()->setLocale($locale);
            $presentation = $catalog->presentation('SALES_OFFICER');

            $this->assertSame($name, $presentation['name']);
            $this->assertNotEmpty($presentation['description']);
            $this->assertNotEmpty($presentation['access_boundaries']);
        }
    }

    public function test_sales_officer_has_wholesale_sales_entry_points_but_no_retail_or_privileged_access(): void
    {
        $actor = $this->salesOfficer();

        foreach ([
            'b2b.accounts.view',
            'b2b.pricing.view',
            'orders.view',
            'orders.edit',
            'customers.view',
            'customers.edit',
        ] as $permission) {
            $this->assertTrue($actor->hasPermission($permission), $permission);
        }

        foreach ([
            'orders.create',
            'orders.approve',
            'orders.manage',
            'orders.dispatch',
            'finance.view',
            'finance.manage',
            'finance.post',
            'roles.manage',
            'users.delete',
            'drivers.b2b.manage',
            'drivers.b2c.manage',
        ] as $permission) {
            $this->assertFalse($actor->hasPermission($permission), $permission);
        }

        $navigation = app(AdminNavigation::class);
        $this->assertFalse($navigation->canAccess($actor, 'b2c'));
        $this->assertTrue($navigation->canAccess($actor, 'b2b'), 'SALES_OFFICER is not admitted to the B2B channel by admin.channels.b2b.global_roles');

        $items = collect($navigation->groupsFor($actor))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->keyBy('key');

        $this->assertTrue($items->has('order_operations'));
        $this->assertSame('admin.operations.orders.index', $items['order_operations']['route']);
        $this->assertTrue($items->has('customer_360'));
        $this->assertFalse($items->has('b2c_orders'));
        $this->assertFalse($items->has('treasury_master'));
        $this->assertFalse($items->has('sidebar_role_access'));

        $this->actingAs($actor)->get(route('admin.operations.orders.index', ['channel' => 'b2b'], false))->assertOk();
        $this->actingAs($actor)->get(route('admin.operations.orders.index', ['channel' => 'b2c'], false))->assertForbidden();
        $this->actingAs($actor)->get(route('admin.security.index', [], false))->assertForbidden();
    }

    public function test_explicit_denies_and_deactivation_cannot_be_bypassed_by_sales_sidebar_links(): void
    {
        $actor = $this->salesOfficer();

        // Customer 360 also permits an independent B2B accounts read grant.
        // Deny both alternatives before asserting that its entry disappears.
        foreach (['orders.view', 'customers.view', 'b2b.accounts.view'] as $permission) {
            DB::table('user_permission_overrides')->insert([
                'user_id' => $actor->id,
                'permission_id' => Permission::query()->where('code', $permission)->firstOrFail()->id,
                'scope_key' => 'global',
                'store_id' => null,
                'effect' => 'deny',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $actor->forceFill(['has_permission_overrides' => true])->save();
        $this->assertFalse($actor->fresh()->hasPermission('orders.view'));
        $this->assertFalse($actor->fresh()->hasPermission('customers.view'));
        $this->assertFalse($actor->fresh()->hasPermission('b2b.accounts.view'));

        app()->forgetInstance(AdminNavigation::class);
        $keys = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->pluck('key');

        $this->assertNotContains('order_operations', $keys->all());
        $this->assertNotContains('customer_360', $keys->all());
        $this->actingAs($actor->fresh())
            ->get(route('admin.operations.orders.index', ['channel' => 'b2b'], false))
            ->assertForbidden();

        $actor->forceFill(['is_active' => false])->save();

        $this->assertFalse($actor->fresh()->hasPermission('orders.edit'));
        app()->forgetInstance(AdminNavigation::class);
        $this->assertSame([], app(AdminNavigation::class)->groupsFor($actor->fresh()));
    }

    public function test_sales_officer_real_wholesale_orders_are_bilingual_and_remain_store_scoped(): void
    {
        $actor = $this->salesOfficer();
        $wholesaleStoreId = app(WholesalePrincipal::class)->storeId();

        $this->assertContains('SALES_OFFICER', config('admin.channels.b2b.global_roles'));
        $this->assertNotContains('SALES_OFFICER', config('admin.channels.b2c.global_roles'));
        $this->assertTrue($actor->hasPermission('orders.view', $wholesaleStoreId));
        $this->assertTrue($actor->hasPermission('orders.edit', $wholesaleStoreId));
        $this->assertFalse($actor->hasPermission('orders.approve', $wholesaleStoreId));
        $this->assertFalse($actor->hasPermission('orders.dispatch', $wholesaleStoreId));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $this->actingAs($actor->fresh())
                ->get(route('admin.operations.orders.index', ['channel' => 'b2b'], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);
            $this->actingAs($actor->fresh())
                ->get(route('admin.operations.orders.index', ['channel' => 'b2c'], false))
                ->assertForbidden();
        }
    }

    public function test_inactive_global_roles_cannot_obtain_effective_permissions_even_when_assignments_remain(): void
    {
        $adminRole = Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail();
        $admin = User::factory()->create(['is_active' => true]);
        $admin->roles()->attach($adminRole);

        $this->assertTrue($admin->fresh()->hasPermission('roles.manage'));
        $admin->forceFill(['is_active' => false])->save();

        // Historical role assignment is retained for audit; effective
        // authorization must not inherit even a SUPER_ADMIN bypass.
        $this->assertDatabaseHas('role_user', [
            'user_id' => $admin->id,
            'role_id' => $adminRole->id,
        ]);
        $this->assertFalse($admin->fresh()->hasPermission('roles.manage'));

        $officer = $this->salesOfficer();
        $this->assertTrue($officer->fresh()->hasPermission('orders.edit'));
        $officer->forceFill(['is_active' => false])->save();

        $this->assertFalse($officer->fresh()->hasPermission('orders.edit'));
        $this->assertFalse($officer->fresh()->hasPermission('customers.view'));
    }

    private function salesOfficer(): User
    {
        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'SALES_OFFICER')->firstOrFail());

        return $actor->fresh();
    }
}
