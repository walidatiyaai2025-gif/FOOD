<?php

namespace Tests\Feature;

use App\Domain\RestaurantSaaS\RestaurantSaasStoreAccess;
use App\Enterprise\Entitlements\EntitlementScope;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class V2RestaurantAnalyticsSidebarAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_p328_analytics_reuses_the_authorized_restaurant_workspace_in_both_locales(): void
    {
        $storeId = $this->retailStore('V2-P328-ALLOWED');
        $this->enableRestaurantSaasForTestStore($storeId);
        $user = $this->retailOperator([$storeId]);

        $this->assertTrue(app(RestaurantSaasStoreAccess::class)->canNavigateToStore(
            $user, $storeId, 'smart_ordering.analytics.view'
        ));

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $user->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $this->actingAs($user)
                ->get(route('admin.smart-ordering.analytics.index', ['store_id' => $storeId]))
                ->assertOk()
                ->assertSee('data-smart-ordering-analytics="true"', false)
                ->assertSee('data-restaurant-saas-workspace-nav', false)
                ->assertSee('data-workspace-item="analytics"', false)
                ->assertSee('dir="'.$direction.'"', false);
        }
    }

    public function test_p328_other_store_and_disabled_restaurant_entitlement_never_create_dead_analytics_links(): void
    {
        $allowedId = $this->retailStore('V2-P328-OWNED');
        $unassignedId = $this->retailStore('V2-P328-OTHER');
        $disabledId = $this->retailStore('V2-P328-DISABLED');
        foreach ([$allowedId, $unassignedId, $disabledId] as $id) {
            $this->enableRestaurantSaasForTestStore($id);
        }

        $user = $this->retailOperator([$allowedId, $disabledId]);
        $this->actingAs($user);

        $this->assertTrue(app(RestaurantSaasStoreAccess::class)->canNavigateToStore(
            $user, $allowedId, 'smart_ordering.analytics.view'
        ));
        $this->assertFalse(app(RestaurantSaasStoreAccess::class)->canNavigateToStore(
            $user, $unassignedId, 'smart_ordering.analytics.view'
        ));
        $this->get(route('admin.smart-ordering.analytics.index', ['store_id' => $unassignedId]))
            ->assertNotFound();
        $this->assertStringNotContainsString('data-workspace-item="analytics"', $this->workspaceNav($user, $unassignedId));

        DB::table('enterprise_module_entitlements')
            ->where('module_code', 'smart_ordering')
            ->where('scope_type', EntitlementScope::STORE)
            ->where('scope_id', $disabledId)
            ->update(['is_enabled' => false, 'updated_at' => now()]);

        $this->assertFalse(app(RestaurantSaasStoreAccess::class)->canNavigateToStore(
            $user, $disabledId, 'smart_ordering.analytics.view'
        ));
        $this->get(route('admin.smart-ordering.analytics.index', ['store_id' => $disabledId]))
            ->assertNotFound();
        $this->assertStringNotContainsString('data-workspace-item="analytics"', $this->workspaceNav($user, $disabledId));
        $this->assertStringContainsString('data-workspace-item="analytics"', $this->workspaceNav($user, $allowedId));
    }

    public function test_p328_super_admin_support_access_is_explicit_and_not_a_presentation_grant(): void
    {
        $storeId = $this->retailStore('V2-P328-SUPPORT');
        $this->enableRestaurantSaasForTestStore($storeId);
        $user = User::query()->create([
            'name' => 'V2 Restaurant Analytics Support',
            'email' => 'v2-p328-support@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $access = app(RestaurantSaasStoreAccess::class);
        $this->assertFalse($access->canNavigateToStore($user, $storeId, 'smart_ordering.analytics.view'));
        $this->assertTrue($access->canNavigateToStore($user, $storeId, 'smart_ordering.analytics.view', true));

        $this->actingAs($user)
            ->get(route('admin.smart-ordering.analytics.index', ['store_id' => $storeId]))
            ->assertForbidden();
        $this->actingAs($user)
            ->get(route('admin.smart-ordering.analytics.index', ['store_id' => $storeId, 'support_access' => 1]))
            ->assertOk();
    }

    public function test_p328_explicit_store_deny_wins_over_assigned_role_and_other_allowed_store(): void
    {
        $deniedId = $this->retailStore('V2-P328-DENIED');
        $allowedId = $this->retailStore('V2-P328-SECOND');
        $this->enableRestaurantSaasForTestStore($deniedId);
        $this->enableRestaurantSaasForTestStore($allowedId);
        $user = $this->retailOperator([$deniedId, $allowedId]);

        DB::table('user_permission_overrides')->insert([
            'user_id' => $user->id,
            'permission_id' => (int) DB::table('permissions')
                ->where('code', 'smart_ordering.analytics.view')
                ->value('id'),
            'scope_key' => 'store:'.$deniedId,
            'store_id' => $deniedId,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user->forceFill(['has_permission_overrides' => true])->save();

        $access = app(RestaurantSaasStoreAccess::class);
        $this->assertFalse($access->canNavigateToStore($user, $deniedId, 'smart_ordering.analytics.view'));
        $this->assertTrue($access->canNavigateToStore($user, $allowedId, 'smart_ordering.analytics.view'));

        $this->actingAs($user)
            ->get(route('admin.smart-ordering.analytics.index', ['store_id' => $deniedId]))
            ->assertNotFound();
        $this->assertStringNotContainsString('data-workspace-item="analytics"', $this->workspaceNav($user, $deniedId));
        $this->assertStringContainsString('data-workspace-item="analytics"', $this->workspaceNav($user, $allowedId));
    }

    private function workspaceNav(User $user, int $storeId): string
    {
        request()->query->set('store_id', (string) $storeId);

        return view('admin._smart-ordering-workspace-nav', ['user' => $user])->render();
    }

    private function retailStore(string $code): int
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param list<int> $storeIds */
    private function retailOperator(array $storeIds): User
    {
        $user = User::query()->create([
            'name' => 'V2 Restaurant Analytics Operator',
            'email' => 'v2-p328-operator@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();

        foreach ($storeIds as $storeId) {
            DB::table('user_store_roles')->insert([
                'user_id' => $user->id,
                'store_id' => $storeId,
                'role_id' => $role->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $user;
    }
}
