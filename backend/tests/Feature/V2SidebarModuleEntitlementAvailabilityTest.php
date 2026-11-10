<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;
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

final class V2SidebarModuleEntitlementAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
        // Explicit test-only approval of the exact ERP module version; never a runtime default.
        config()->set('enterprise_module_readiness.certified_versions.procurement', app(ModuleRegistry::class)->manifest('procurement')->version);
    }

    public function test_module_disabled_hides_sidebar_entry_and_direct_procurement_route_fails_closed_in_both_locales(): void
    {
        $actor = $this->procurementUser();
        $registry = app(ModuleRegistry::class);
        $registry->syncManifest('procurement');

        $this->assertFalse($registry->isEnabled('procurement'));
        $this->assertTrue($actor->hasPermission('procurement.suppliers.view'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $this->assertNotContains('erp_procurement', $this->sidebarKeys($actor->fresh()));

            $this->actingAs($actor->fresh())
                ->get(route('admin.erp.procurement.index'))
                ->assertForbidden();

            $this->actingAs($actor->fresh())
                ->get(route('admin.b2b.module', ['module' => 'dashboard']))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);
        }

        $this->assertDatabaseMissing('audit_logs', [
            'user_id' => $actor->getKey(),
            'event' => 'enterprise.module.state_changed',
        ]);
    }

    public function test_enabled_but_unentitled_module_stays_hidden_despite_active_permissions(): void
    {
        $actor = $this->procurementUser();
        $this->enableProcurement();

        $this->assertTrue(app(ModuleRegistry::class)->isEnabled('procurement'));
        $this->assertTrue($actor->hasPermission('procurement.suppliers.view'));
        $this->assertFalse(app(ModuleEntitlementResolver::class)->allows(
            'procurement',
            EntitlementScope::platform(),
        ));
        $this->assertNotContains('erp_procurement', $this->sidebarKeys($actor));

        app(ModuleEntitlementManager::class)->set(
            'procurement',
            EntitlementScope::platform(),
            false,
        );

        $this->assertFalse(app(ModuleEntitlementResolver::class)->allows(
            'procurement',
            EntitlementScope::platform(),
        ));
        $this->assertNotContains('erp_procurement', $this->sidebarKeys($actor));
    }

    public function test_enabled_entitled_module_exposes_real_navigation_route_and_preserves_http_permission_gate(): void
    {
        $storeId = $this->wholesaleStore();
        $actor = $this->procurementUser();
        $denied = $this->financeUser();
        $this->enableProcurement();

        app(ModuleEntitlementManager::class)->set(
            'procurement',
            EntitlementScope::platform(),
            true,
        );

        $this->assertTrue(app(ModuleEntitlementResolver::class)->allows(
            'procurement',
            EntitlementScope::platform(),
        ));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $items = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
                ->flatMap(fn (array $group): array => $group['children'])
                ->keyBy('key');

            $this->assertTrue($items->has('erp_procurement'));
            $this->assertSame('admin.erp.procurement.index', $items->get('erp_procurement')['route']);

            $this->actingAs($actor->fresh())
                ->get(route('admin.erp.procurement.index', [
                    'store_id' => $storeId,
                    'tab' => 'suppliers',
                ]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            $this->assertNotContains('erp_procurement', $this->sidebarKeys($denied));

            $this->actingAs($denied)
                ->get(route('admin.erp.procurement.index', [
                    'store_id' => $storeId,
                    'tab' => 'suppliers',
                ]))
                ->assertForbidden();
        }

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.module.entitlement_changed',
        ]);
    }

    /** @return list<string> */
    private function sidebarKeys(User $actor): array
    {
        return collect(app(AdminNavigation::class)->groupsFor($actor))
            ->flatMap(fn (array $group): array => $group['children'])
            ->pluck('key')
            ->values()
            ->all();
    }

    private function procurementUser(): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach([
            Role::query()->where('code', 'B2B_ADMIN')->firstOrFail()->getKey(),
            Role::query()->where('code', 'PROCUREMENT_MANAGER')->firstOrFail()->getKey(),
        ]);

        // A real global override is allowed only in the actor's B2B channel.
        // Keep it when the module is disabled so its runtime 404 remains
        // authoritative; when enabled, the same grant is evaluated per store.
        DB::table('user_permission_overrides')->insert([
            'user_id' => $user->getKey(),
            'permission_id' => Permission::query()
                ->where('code', 'procurement.suppliers.view')
                ->firstOrFail()
                ->getKey(),
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'allow',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user->forceFill(['has_permission_overrides' => true])->save();

        return $user->fresh();
    }

    private function financeUser(): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', 'FINANCE')->firstOrFail());

        return $user;
    }

    private function enableProcurement(): void
    {
        $registry = app(ModuleRegistry::class);
        $registry->syncManifest('procurement');

        app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::ENABLING);
        app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::ENABLED);
    }

    private function wholesaleStore(): int
    {
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2B')->value('id'),
            'code' => 'V2-2796-WHOLESALE',
            'name' => 'Module Availability Wholesale Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore(
            Store::query()->findOrFail($storeId),
        );

        return $storeId;
    }
}
