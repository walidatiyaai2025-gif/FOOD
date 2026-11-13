<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 #2642 / P312: BI navigation and the real HTTP page must enforce the
 * same global-role, module readiness and effective tenant entitlement.
 */
final class V2BiSidebarReadinessAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
        config()->set(
            'enterprise_module_readiness.certified_versions.enterprise_bi',
            app(ModuleRegistry::class)->manifest('enterprise_bi')->version,
        );
    }

    public function test_entitled_store_analyst_can_find_and_open_the_canonical_bi_page_in_both_locales(): void
    {
        $this->enableBi();
        $store = $this->store('V2-P312-ALLOWED');
        $actor = $this->analyst($store);

        app(ModuleEntitlementManager::class)->set(
            'enterprise_bi',
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            true,
        );

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            app()->forgetInstance(AdminNavigation::class);

            $item = $this->biNavItem($actor->fresh());
            $this->assertNotNull($item);
            $this->assertSame('admin.erp.bi', $item['route']);

            $this->actingAs($actor->fresh())
                ->get(route('admin.erp.bi'))
                ->assertOk()
                ->assertViewIs('admin.enterprise-bi')
                ->assertSee('dir="'.$direction.'"', false);
        }
    }

    public function test_foreign_store_entitlement_cannot_unlock_bi_or_become_a_sidebar_grant(): void
    {
        $this->enableBi();
        $assigned = $this->store('V2-P312-MY-STORE');
        $foreign = $this->store('V2-P312-FOREIGN');
        $actor = $this->analyst($assigned);

        app(ModuleEntitlementManager::class)->set(
            'enterprise_bi',
            new EntitlementScope(EntitlementScope::STORE, $foreign->id),
            true,
        );

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            app()->forgetInstance(AdminNavigation::class);

            $this->assertNull($this->biNavItem($actor->fresh()));
            $this->actingAs($actor->fresh())
                ->get(route('admin.erp.bi', ['scope' => 'store:'.$foreign->id, 'run' => 1]))
                ->assertForbidden();
        }

        // Adding one legitimate assignment makes only that store eligible.
        app(ModuleEntitlementManager::class)->set(
            'enterprise_bi',
            new EntitlementScope(EntitlementScope::STORE, $assigned->id),
            true,
        );
        app()->forgetInstance(AdminNavigation::class);
        $this->assertNotNull($this->biNavItem($actor->fresh()));
        $this->actingAs($actor->fresh())->get(route('admin.erp.bi'))->assertOk();
    }

    public function test_ready_module_without_entitlement_is_denied_even_for_a_privileged_role(): void
    {
        $this->enableBi();
        $actor = $this->superAdmin();

        $this->assertNull($this->biNavItem($actor));
        $this->actingAs($actor)->get(route('admin.erp.bi'))->assertForbidden();

        app(ModuleEntitlementManager::class)->set(
            'enterprise_bi',
            EntitlementScope::platform(),
            true,
        );
        app()->forgetInstance(AdminNavigation::class);

        $this->assertNotNull($this->biNavItem($actor->fresh()));
        $this->actingAs($actor->fresh())->get(route('admin.erp.bi'))->assertOk();
    }

    public function test_uncertified_or_disabled_module_and_explicit_permission_deny_block_direct_http(): void
    {
        $this->enableBi();
        $actor = $this->superAdmin();
        app(ModuleEntitlementManager::class)->set(
            'enterprise_bi',
            EntitlementScope::platform(),
            true,
        );

        config()->set('enterprise_module_readiness.certified_versions.enterprise_bi', 999999);
        app()->forgetInstance(AdminNavigation::class);
        $this->assertNull($this->biNavItem($actor));
        $this->actingAs($actor)->get(route('admin.erp.bi'))->assertForbidden();

        config()->set(
            'enterprise_module_readiness.certified_versions.enterprise_bi',
            app(ModuleRegistry::class)->manifest('enterprise_bi')->version,
        );
        $this->actingAs($actor)->get(route('admin.erp.bi'))->assertOk();

        app(ModuleLifecycleManager::class)->transition('enterprise_bi', ModuleState::DISABLED);
        app()->forgetInstance(AdminNavigation::class);
        $this->assertNull($this->biNavItem($actor->fresh()));
        $this->actingAs($actor->fresh())->get(route('admin.erp.bi'))->assertForbidden();
    }

    public function test_multi_role_reporting_deny_and_roleless_entitled_actor_have_no_bi_access(): void
    {
        $this->enableBi();
        $store = $this->store('V2-P312-MULTI');
        $actor = $this->analyst($store);
        $actor->roles()->attach(Role::query()->where('code', 'OPERATIONS')->firstOrFail());

        app(ModuleEntitlementManager::class)->set(
            'enterprise_bi',
            EntitlementScope::platform(),
            true,
        );

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', 'reports.view')->firstOrFail()->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
        app()->forgetInstance(AdminNavigation::class);

        $this->assertNull($this->biNavItem($actor->fresh()));
        $this->actingAs($actor->fresh())->get(route('admin.erp.bi'))->assertForbidden();

        $roleless = User::factory()->create(['is_active' => true]);
        app()->forgetInstance(AdminNavigation::class);
        $this->assertNull($this->biNavItem($roleless));
        $this->actingAs($roleless)->get(route('admin.erp.bi'))->assertForbidden();
    }

    private function enableBi(): void
    {
        app(ModuleRegistry::class)->syncManifest('enterprise_bi');
        app(ModuleLifecycleManager::class)->transition('enterprise_bi', ModuleState::ENABLING);
        app(ModuleLifecycleManager::class)->transition('enterprise_bi', ModuleState::ENABLED);
    }

    private function superAdmin(): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $actor->fresh();
    }

    private function store(string $code): Store
    {
        $store = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    private function analyst(Store $store): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'BUSINESS_ANALYST')->firstOrFail());
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => Role::query()->where('code', 'RETAIL_OPERATIONS')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor->fresh();
    }

    /** @return array<string, mixed>|null */
    private function biNavItem(User $actor): ?array
    {
        foreach (app(AdminNavigation::class)->groupsFor($actor) as $group) {
            foreach ($group['children'] as $item) {
                if ($item['key'] === 'erp_bi') {
                    return $item;
                }
            }
        }

        return null;
    }
}
