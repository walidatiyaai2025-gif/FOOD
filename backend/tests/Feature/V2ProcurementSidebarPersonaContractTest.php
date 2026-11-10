<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Enums\StoreBusinessProfile;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyntheticErpWorkflowEvidence;
use Tests\TestCase;

/**
 * V2 #2712 / T353R3: Procurement is an authorized persona, not a Sidebar grant.
 */
final class V2ProcurementSidebarPersonaContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        config()->set('enterprise_module_readiness.certified_versions', []);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_procurement_role_exposes_only_existing_scoped_informational_navigation_while_uncertified(): void
    {
        $manager = $this->userWithRoles(['PROCUREMENT_MANAGER']);

        // Built-in Procurement permissions fail closed until its required module is enabled.
        $this->assertFalse($manager->hasPermission('procurement.suppliers.view'));
        $this->assertFalse($manager->hasPermission('reports.view'));
        $this->assertFalse($manager->hasPermission('inventory.view'));
        $this->assertFalse($manager->hasPermission('finance.view'));

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $manager->forceFill(['locale' => $locale])->save();

            $items = $this->navigationItems($manager->fresh());

            $this->assertArrayHasKey('enterprise_action_center', $items);
            $this->assertArrayHasKey('erp_center', $items);
            $this->assertArrayNotHasKey('reports_center', $items);
            $this->assertArrayNotHasKey('erp_procurement', $items);
            $this->assertArrayNotHasKey('erp_wms', $items);
            $this->assertArrayNotHasKey('erp_finance', $items);
            $this->assertArrayNotHasKey('b2c_inventory', $items);
            $this->assertArrayNotHasKey('b2c_products', $items);

            $this->actingAs($manager->fresh())
                ->get(route('admin.erp.index'))
                ->assertOk()
                ->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false);

            $this->actingAs($manager->fresh())
                ->get(route('admin.erp.procurement.index'))
                ->assertForbidden();
        }
    }

    public function test_procurement_link_requires_exact_certified_healthy_module_entitlement_and_real_permission(): void
    {
        // A legitimate Wholesale procurement specialist needs no blanket B2B_ADMIN role.
        $actor = $this->userWithRoles(['PROCUREMENT_MANAGER']);
        $denied = $this->userWithRoles(['FINANCE']);
        $store = $this->wholesaleStore();

        app(ModuleRegistry::class)->syncManifest('procurement');
        app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::ENABLING);
        app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::ENABLED);

        $this->assertArrayNotHasKey('erp_procurement', $this->navigationItems($actor->fresh()));

        app(ModuleEntitlementManager::class)->set(
            'procurement',
            EntitlementScope::platform(),
            true,
        );

        $this->assertArrayNotHasKey('erp_procurement', $this->navigationItems($actor->fresh()));
        $version = app(ModuleRegistry::class)->manifest('procurement')->version;
        config()->set('enterprise_module_readiness.certified_versions.procurement', $version + 1);
        $this->assertArrayNotHasKey('erp_procurement', $this->navigationItems($actor->fresh()));

        config()->set('enterprise_module_readiness.certified_versions.procurement', $version);
        // Exact manifest version alone is still insufficient after P135.
        $this->assertArrayNotHasKey('erp_procurement', $this->navigationItems($actor->fresh()));
        SyntheticErpWorkflowEvidence::install();
        $this->assertTrue($actor->hasPermission('procurement.suppliers.view', $store));
        $this->assertTrue($actor->hasPermission('reports.view', $store));
        $this->assertFalse($actor->hasPermission('inventory.view', $store));
        $this->assertFalse($actor->hasPermission('finance.view', $store));

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $denied->forceFill(['locale' => $locale])->save();

            $item = $this->navigationItems($actor->fresh())['erp_procurement'] ?? null;
            $this->assertNotNull($item);
            $this->assertSame('admin.erp.procurement.index', $item['route']);
            $this->assertSame([], $item['params']);
            $this->assertArrayNotHasKey('erp_procurement', $this->navigationItems($denied->fresh()));
            $this->assertArrayHasKey('reports_center', $this->navigationItems($actor->fresh()));

            $this->actingAs($actor->fresh())
                ->get(route('admin.erp.procurement.index', ['store_id' => $store, 'tab' => 'suppliers']))
                ->assertOk()
                ->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false);

            $this->actingAs($denied->fresh())
                ->get(route('admin.erp.procurement.index', ['store_id' => $store]))
                ->assertForbidden();
        }
    }

    public function test_wholesale_buyer_and_approver_have_distinct_module_gated_permissions_without_escalation(): void
    {
        $store = $this->wholesaleStore();
        $buyer = $this->userWithRoles(['PROCUREMENT_BUYER']);
        $approver = $this->userWithRoles(['PROCUREMENT_APPROVER']);

        app(ModuleRegistry::class)->syncManifest('procurement');
        app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::ENABLING);
        app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::ENABLED);
        app(ModuleEntitlementManager::class)->set('procurement', EntitlementScope::platform(), true);
        config()->set(
            'enterprise_module_readiness.certified_versions.procurement',
            app(ModuleRegistry::class)->manifest('procurement')->version,
        );

        // Positive navigation scenarios need explicit synthetic workflow proof.
        SyntheticErpWorkflowEvidence::install();
        $this->assertTrue($buyer->hasPermission('procurement.suppliers.view', $store));
        $this->assertTrue($buyer->hasPermission('procurement.purchase_requests.manage', $store));
        $this->assertFalse($buyer->hasPermission('procurement.purchase_requests.approve', $store));
        $this->assertFalse($buyer->hasPermission('procurement.purchase_orders.issue', $store));
        $this->assertFalse($buyer->hasPermission('inventory.view', $store));

        $this->assertTrue($approver->hasPermission('procurement.suppliers.view', $store));
        $this->assertTrue($approver->hasPermission('procurement.purchase_requests.approve', $store));
        $this->assertTrue($approver->hasPermission('procurement.purchase_orders.issue', $store));
        $this->assertFalse($approver->hasPermission('procurement.suppliers.manage', $store));
        $this->assertFalse($approver->hasPermission('finance.view', $store));

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);

            foreach ([$buyer, $approver] as $actor) {
                $actor->forceFill(['locale' => $locale])->save();

                $this->assertArrayHasKey('erp_procurement', $this->navigationItems($actor->fresh()));
                $this->actingAs($actor->fresh())
                    ->get(route('admin.erp.procurement.index', [
                        'store_id' => $store,
                        'tab' => 'suppliers',
                    ]))
                    ->assertOk();
            }
        }
    }

    public function test_retail_only_role_cannot_inherit_procurement_or_wholesale_navigation(): void
    {
        [$retail, $ownStore] = $this->retailUser();
        $foreignStore = $this->retailStore('V2-2712-FOREIGN');
        $manager = $this->userWithRoles(['PROCUREMENT_MANAGER']);

        foreach ([$retail, $manager] as $actor) {
            $this->assertArrayNotHasKey('erp_procurement', $this->navigationItems($actor->fresh()));
        }

        $this->assertArrayNotHasKey('b2b_products', $this->navigationItems($retail->fresh()));
        $this->assertArrayHasKey('b2c_products', $this->navigationItems($retail->fresh()));
        $this->assertArrayNotHasKey('b2c_products', $this->navigationItems($manager->fresh()));

        // Even a fully certified and entitled Wholesale Procurement module
        // cannot grant a Retail-only actor the Procurement route.
        app(ModuleRegistry::class)->syncManifest('procurement');
        app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::ENABLING);
        app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::ENABLED);
        app(ModuleEntitlementManager::class)->set('procurement', EntitlementScope::platform(), true);
        config()->set(
            'enterprise_module_readiness.certified_versions.procurement',
            app(ModuleRegistry::class)->manifest('procurement')->version,
        );

        // Keep this evidence test-only; Retail still must not inherit B2B routes.
        SyntheticErpWorkflowEvidence::install();
        $this->assertArrayHasKey('erp_procurement', $this->navigationItems($manager->fresh()));
        $this->assertArrayNotHasKey('erp_procurement', $this->navigationItems($retail->fresh()));

        $this->actingAs($retail->fresh())
            ->get(route('admin.erp.procurement.index', ['store_id' => $ownStore]))
            ->assertForbidden();

        $this->actingAs($retail->fresh())
            ->get(route('admin.b2c.module', ['module' => 'products', 'store_id' => $ownStore]))
            ->assertOk();
        $this->actingAs($retail->fresh())
            ->get(route('admin.b2c.module', ['module' => 'products', 'store_id' => $foreignStore]))
            ->assertNotFound();
        $this->actingAs($retail->fresh())
            ->get(route('admin.erp.procurement.index'))
            ->assertForbidden();
    }

    /** @return array<string, array<string, mixed>> */
    private function navigationItems(User $user): array
    {
        return collect(app(AdminNavigation::class)->groupsFor($user))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->keyBy('key')
            ->all();
    }

    /** @param list<string> $roleCodes */
    private function userWithRoles(array $roleCodes): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(
            Role::query()->whereIn('code', $roleCodes)->get()->modelKeys(),
        );

        return $user;
    }

    /** @return array{User, int} */
    private function retailUser(): array
    {
        $storeId = $this->retailStore('V2-2712-OWNED');
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->getKey(),
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$user, $storeId];
    }

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function wholesaleStore(): int
    {
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2B')->value('id'),
            'code' => 'V2-2712-WHOLESALE',
            'name' => 'V2 Procurement Persona Wholesale',
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
