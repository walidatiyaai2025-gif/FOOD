<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P013: menu projection is never a substitute for an effective HTTP decision.
 */
final class V2RouteAuthorizationDecisionParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_roleless_actor_has_neither_erp_navigation_nor_direct_hub_access(): void
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'ar']);
        $navigation = app(AdminNavigation::class);

        $this->assertFalse($navigation->canAccessPlatformErp($actor));
        $this->assertNotContains(
            'erp',
            collect($navigation->groupsFor($actor))->pluck('key')->all(),
        );

        $this->actingAs($actor)
            ->get(route('admin.erp.index'))
            ->assertForbidden();
    }

    public function test_inactive_global_role_does_not_authorize_erp_group_or_direct_hub(): void
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $role = $this->newGlobalRole('P2758_INACTIVE', false);
        $actor->roles()->attach($role);
        $navigation = app(AdminNavigation::class);

        $this->assertFalse($navigation->canAccessPlatformErp($actor));
        $this->assertNotContains(
            'erp',
            collect($navigation->groupsFor($actor))->pluck('key')->all(),
        );

        $this->actingAs($actor)
            ->get(route('admin.erp.index'))
            ->assertForbidden();
    }

    public function test_multiple_unrelated_global_permissions_do_not_unlock_operational_erp_modules(): void
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $reportsRole = $this->newGlobalRole('P2758_REPORTS', true);
        $reportsRole->permissions()->attach(
            Permission::query()->where('code', 'reports.view')->firstOrFail(),
        );
        $rolesRole = $this->newGlobalRole('P2758_ROLES', true);
        $rolesRole->permissions()->attach(
            Permission::query()->where('code', 'roles.manage')->firstOrFail(),
        );
        $actor->roles()->attach([$reportsRole->getKey(), $rolesRole->getKey()]);

        $navigation = app(AdminNavigation::class);
        $this->assertTrue($navigation->canAccessPlatformErp($actor));
        $groups = collect($navigation->groupsFor($actor));
        $erp = $groups->firstWhere('key', 'erp');

        $this->assertIsArray($erp);
        $keys = collect($erp['children'])->pluck('key')->all();
        $this->assertContains('erp_center', $keys);
        $this->assertNotContains('erp_finance', $keys);
        $this->assertNotContains('erp_treasury', $keys);
        $this->assertNotContains('erp_wms', $keys);

        $administration = $groups->firstWhere('key', 'administration');
        $this->assertIsArray($administration);
        $this->assertNotContains(
            'platform_owner_modules',
            collect($administration['children'])->pluck('key')->all(),
        );

        // Navigation admission alone must not bypass finance module authorization.
        $this->actingAs($actor)
            ->get(route('admin.erp.finance.index'))
            ->assertForbidden();
    }

    private function newGlobalRole(string $code, bool $active): Role
    {
        return Role::query()->create([
            'code' => $code,
            'name' => $code,
            'scope' => 'global',
            'is_system' => false,
            'is_active' => $active,
        ]);
    }
}
