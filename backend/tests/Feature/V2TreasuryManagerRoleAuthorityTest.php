<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\EnterpriseCashbox;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\BuiltInJobRoleCatalog;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 #2698 / T122R18: Treasury Manager is an actual scoped Wholesale persona,
 * not a role-name-only Sidebar grant or a bypass of Treasury entitlements/SoD.
 */
final class V2TreasuryManagerRoleAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
        config()->set(
            'enterprise_module_readiness.certified_versions.treasury',
            app(ModuleRegistry::class)->manifest('treasury')->version,
        );
    }

    public function test_manager_role_is_wholesale_only_and_separated_from_finance_posting_and_security(): void
    {
        $catalog = app(BuiltInJobRoleCatalog::class);
        $definition = $catalog->definition('TREASURY_MANAGER');

        $this->assertNotNull($definition);
        $this->assertSame('treasury', $definition['domain']);
        $this->assertSame('global', $definition['scope']);
        $this->assertSame('tenant', $definition['business_scope']);
        $this->assertContains('treasury', $definition['required_modules']);
        $this->assertContains('TREASURY_MANAGER', config('admin.channels.b2b.global_roles'));
        $this->assertNotContains('TREASURY_MANAGER', config('admin.channels.b2c.global_roles'));

        $role = Role::query()->where('code', 'TREASURY_MANAGER')->firstOrFail();
        $this->assertTrue((bool) $role->is_system);
        $grants = $role->permissions()->pluck('code')->all();
        foreach (['finance.view', 'finance.manage', 'finance.approve', 'reports.view'] as $permission) {
            $this->assertContains($permission, $grants);
        }
        foreach (['finance.post', 'security.approve', 'users.delete',
            'procurement.purchase_requests.approve', 'procurement.purchase_orders.issue'] as $permission) {
            $this->assertNotContains($permission, $grants);
        }

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $presentation = $catalog->presentation('TREASURY_MANAGER');
            $this->assertNotSame('', trim((string) $presentation['name']));
            $this->assertNotSame('', trim((string) $presentation['scope_label']));
        }
    }

    public function test_manager_is_not_operational_while_module_disabled_or_unentitled(): void
    {
        $store = $this->wholesaleStore();
        $manager = $this->manager();

        $this->assertFalse(app(BuiltInJobRoleCatalog::class)->isAvailable('TREASURY_MANAGER'));
        $this->assertFalse($manager->fresh()->hasPermission('finance.view'));
        $this->actingAs($manager)
            ->get(route('admin.erp.treasury.index', ['store_id' => $store->id]))
            ->assertForbidden();

        $this->enableModule($manager);
        $this->assertTrue($manager->fresh()->hasPermission('finance.view'));
        $this->assertFalse($manager->fresh()->hasPermission('finance.post'));
        $this->assertFalse($manager->fresh()->hasPermission('security.approve'));

        // Enabled is not a per-store Treasury entitlement.
        $navigation = app(AdminNavigation::class)->groupsFor($manager->fresh());
        $keys = collect($navigation)
            ->flatMap(static fn (array $group): array => $group['children'])
            ->pluck('key')
            ->all();
        $this->assertNotContains('erp_treasury', $keys);
    }

    public function test_entitled_manager_can_open_bilingual_wholesale_treasury_but_not_retail_or_unentitled_mutations(): void
    {
        $store = $this->wholesaleStore();
        $manager = $this->manager();
        $this->enableModule($manager);
        $this->entitle($manager, $store, ['treasury.receipts.read']);

        $navigation = app(AdminNavigation::class)->groupsFor($manager->fresh());
        $keys = collect($navigation)
            ->flatMap(static fn (array $group): array => $group['children'])
            ->pluck('key')
            ->all();
        $this->assertContains('erp_treasury', $keys);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $manager->forceFill(['locale' => $locale])->save();

            $this->actingAs($manager->fresh())
                ->get(route('admin.erp.treasury.index', [
                    'store_id' => $store->id,
                    'tab' => 'receipts',
                ]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-erp-treasury-workbench="true"', false)
                ->assertDontSee('action="'.route('admin.erp.treasury.receipts.store').'"', false);
        }

        $retail = Store::query()->create([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'V2-2698-RETAIL',
            'name' => 'Foreign Retail',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($retail);

        $this->actingAs($manager->fresh())
            ->get(route('admin.erp.treasury.index', ['store_id' => $retail->id, 'tab' => 'receipts']))
            ->assertForbidden();

        $scope = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertNotNull($scope);
        $cashbox = EnterpriseCashbox::query()->create([
            'company_id' => $scope['company_id'],
            'store_id' => $store->id,
            'code' => 'V2-2698-CASH',
            'name' => 'Treasury Manager Cashbox',
            'currency_code' => 'KWD',
            'operator_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'approval_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'is_active' => true,
        ]);

        // The broad finance.manage grant cannot override a read-only Treasury capability.
        $this->actingAs($manager->fresh())
            ->post(route('admin.erp.treasury.receipts.store'), [
                'store_id' => $store->id,
                '_operation_key' => 'v2-2698-no-manage',
                'target_key' => 'cashbox:'.$cashbox->id,
                'amount' => '5.000',
                'value_date' => '2026-11-13',
                'reference' => 'V2-2698-DENIED',
            ])
            ->assertForbidden();
        $this->assertDatabaseCount('enterprise_treasury_receipts', 0);
    }

    private function manager(): User
    {
        $manager = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $manager->roles()->attach(Role::query()->where('code', 'TREASURY_MANAGER')->firstOrFail());

        return $manager;
    }

    private function wholesaleStore(): Store
    {
        $store = Store::query()->findOrFail(app(WholesalePrincipal::class)->storeId());
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    private function enableModule(User $actor): void
    {
        $lifecycle = app(ModuleLifecycleManager::class);
        $lifecycle->transition('treasury', ModuleState::ENABLING, $actor);
        $lifecycle->transition('treasury', ModuleState::ENABLED, $actor);
    }

    /** @param list<string> $capabilities */
    private function entitle(User $actor, Store $store, array $capabilities): void
    {
        app(ModuleEntitlementManager::class)->set(
            'treasury',
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            true,
            $capabilities,
            $actor,
        );
    }
}
