<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2SecurityAdministratorSidebarPersonaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_security_administrator_discovers_real_administration_and_security_workflow_without_super_admin(): void
    {
        $actor = $this->securityAdministrator('ar');

        $this->assertTrue($actor->hasPermission('security.view'));
        $this->assertTrue($actor->hasPermission('roles.manage'));
        $this->assertTrue($actor->hasPermission('security.approve'));
        $this->assertFalse($actor->hasRole('SUPER_ADMIN'));
        $this->assertFalse($actor->hasPermission('platform.manage'));

        $navigation = app(AdminNavigation::class);
        $this->assertSame([], array_keys($navigation->for($actor)),
            'Security administrators have no implicit B2B or B2C store-channel role.');

        $groups = collect($navigation->groupsFor($actor));
        $administration = $groups->firstWhere('key', 'administration');
        $this->assertIsArray($administration);
        $items = collect($administration['children'])->keyBy('key');

        foreach (['administration_hub', 'sidebar_role_access', 'sidebar_layout'] as $key) {
            $this->assertTrue($items->has($key), "Authorized security workflow is missing {$key}");
        }

        $this->assertSame('admin.administration.index', $items['administration_hub']['route']);
        $this->assertSame('admin.sidebar-access.roles', $items['sidebar_role_access']['route']);

        $this->actingAs($actor)->get(route('admin.administration.index'))
            ->assertOk()
            ->assertViewHas('canSecurity', true)
            ->assertViewHas('canPlatformManage', false)
            ->assertSee('dir="rtl"', false);

        foreach (['users', 'roles', 'governance', 'audit'] as $section) {
            $this->actingAs($actor)
                ->get(route('admin.security.index', ['section' => $section]))
                ->assertOk()
                ->assertSee('dir="rtl"', false);
        }
    }

    public function test_security_admin_english_security_center_uses_existing_ltr_workbench(): void
    {
        $actor = $this->securityAdministrator('en');

        $this->actingAs($actor)
            ->get(route('admin.security.index', ['section' => 'audit']))
            ->assertOk()
            ->assertSee('dir="ltr"', false);

        $this->actingAs($actor)
            ->get(route('admin.sidebar-access.roles'))
            ->assertOk();
    }

    public function test_security_role_does_not_inherit_business_channel_or_financial_authority(): void
    {
        $actor = $this->securityAdministrator('en');
        $nav = app(AdminNavigation::class);
        $items = collect($nav->groupsFor($actor))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->pluck('key');

        $this->assertFalse($nav->canAccess($actor, 'b2b'));
        $this->assertFalse($nav->canAccess($actor, 'b2c'));
        foreach (['b2b_dashboard', 'b2c_dashboard', 'b2b_orders', 'b2c_orders',
            'erp_finance', 'erp_treasury', 'erp_procurement', 'erp_wms',
            'b2c_inventory', 'b2c_customers', 'commercial_sales_control'] as $key) {
            $this->assertNotContains($key, $items->all());
        }

        foreach (['finance.view', 'finance.post', 'finance.approve', 'orders.manage',
            'inventory.adjust', 'system.update', 'deliveries.b2b.execute',
            'deliveries.b2c.execute', 'van.login'] as $code) {
            $this->assertFalse($actor->hasPermission($code), "Security persona gained {$code}");
        }

        $this->actingAs($actor)
            ->get(route('admin.b2b.dashboard'))
            ->assertForbidden();
        $this->actingAs($actor)
            ->get(route('admin.b2c.dashboard'))
            ->assertForbidden();
    }

    public function test_delegated_security_operator_cannot_grant_platform_authority_to_another_user(): void
    {
        $actor = $this->securityAdministrator('en');
        $target = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $target->roles()->attach(Role::query()->where('code', 'CUSTOMER_SERVICE_AGENT')->firstOrFail());
        $protected = Permission::query()->where('code', 'platform.manage')->firstOrFail();

        $this->actingAs($actor)
            ->from('/admin/security?section=users')
            ->put(route('admin.security.users.permissions', $target), [
                'scope_type' => 'global',
                'permission_ids' => [$protected->id],
            ])
            ->assertRedirect('/admin/security?section=users')
            ->assertSessionHasErrors('permission_ids');

        $this->assertFalse($target->fresh()->hasPermission('platform.manage'));
        $this->assertDatabaseMissing('user_permission_overrides', [
            'user_id' => $target->id,
            'permission_id' => $protected->id,
            'effect' => 'allow',
        ]);
    }

    public function test_explicit_security_view_deny_disables_security_center_without_removing_role(): void
    {
        $actor = $this->securityAdministrator('en');
        $permission = Permission::query()->where('code', 'security.view')->firstOrFail();

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => $permission->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        $fresh = $actor->fresh();
        $this->assertTrue($fresh->hasRole('SECURITY_ADMINISTRATOR'));
        $this->assertFalse($fresh->hasPermission('security.view'));
        $this->assertTrue($fresh->hasPermission('roles.manage'));

        $this->actingAs($fresh)->get(route('admin.security.index'))->assertForbidden();
        $this->actingAs($fresh)->get(route('admin.administration.index'))
            ->assertOk()
            ->assertViewHas('canSecurity', false);
    }

    public function test_inactive_and_roleless_users_cannot_open_security_routes(): void
    {
        $inactive = $this->securityAdministrator('ar');
        $inactive->forceFill(['is_active' => false])->save();
        $roleless = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        foreach ([$inactive, $roleless] as $actor) {
            $this->assertSame([], app(AdminNavigation::class)->groupsFor($actor));
            $this->actingAs($actor)->get(route('admin.security.index'))->assertForbidden();
        }
    }

    private function securityAdministrator(string $locale): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => $locale]);
        $actor->roles()->attach(Role::query()->where('code', 'SECURITY_ADMINISTRATOR')->firstOrFail());

        return $actor;
    }
}
