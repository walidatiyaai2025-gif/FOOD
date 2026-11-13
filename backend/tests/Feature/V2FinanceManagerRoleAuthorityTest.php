<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Finance\FinanceFoundationService;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
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
 * V2 #2693 / T122R13: the existing FINANCE manager operates only within
 * authorized Wholesale finance scopes, and cannot become a checker by title.
 */
final class V2FinanceManagerRoleAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
        config()->set(
            'enterprise_module_readiness.certified_versions.finance',
            app(ModuleRegistry::class)->manifest('finance')->version,
        );
    }

    public function test_builtin_finance_manager_is_wholesale_only_and_not_an_accounting_approver_or_security_admin(): void
    {
        $store = $this->wholesaleStore();
        $retail = $this->retailStore('V2-2693-ROLE-RETAIL');
        $manager = $this->manager();
        $catalog = app(BuiltInJobRoleCatalog::class);
        $definition = $catalog->definition('FINANCE');

        $this->assertNotNull($definition);
        $this->assertSame('finance', $definition['domain']);
        $this->assertSame('manager', $definition['level']);
        $this->assertSame('global', $definition['scope']);
        $this->assertSame('tenant', $definition['business_scope']);
        $this->assertContains('finance', $definition['optional_modules']);
        $this->assertContains('FINANCE', config('admin.channels.b2b.global_roles'));
        $this->assertNotContains('FINANCE', config('admin.channels.b2c.global_roles'));

        $role = Role::query()->where('code', 'FINANCE')->firstOrFail();
        $this->assertTrue((bool) $role->is_system);
        $grants = $role->permissions()->pluck('code')->all();
        foreach (['finance.view', 'finance.manage', 'finance.post', 'reports.view', 'reports.export'] as $code) {
            $this->assertContains($code, $grants);
        }
        foreach ([
            'finance.approve',
            'security.approve',
            'roles.manage',
            'users.delete',
            'procurement.purchase_orders.issue',
            'treasury.reconciliation.approve',
        ] as $code) {
            $this->assertNotContains($code, $grants);
        }

        $this->assertTrue($manager->hasPermission('finance.view', $store->id));
        $this->assertFalse($manager->hasPermission('finance.view', $retail->id));
        $this->assertTrue(app(AdminNavigation::class)->canAccess($manager, 'b2b'));
        $this->assertFalse(app(AdminNavigation::class)->canAccess($manager, 'b2c'));
        $this->assertContains(
            $store->id,
            app(OperationalTenantScope::class)->allowedStoreIds($manager, 'finance.view', 'b2b'),
        );
        $this->assertSame(
            [],
            app(OperationalTenantScope::class)->allowedStoreIds($manager, 'finance.view', 'b2c'),
        );

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $presentation = $catalog->presentation('FINANCE');
            $this->assertNotSame('', trim((string) $presentation['name']));
            $this->assertNotSame('', trim((string) $presentation['scope_label']));
        }
    }

    public function test_disabled_uncertified_and_unentitled_finance_workbench_fails_closed(): void
    {
        $store = $this->wholesaleStore();
        $manager = $this->manager();
        $url = route('admin.erp.finance.index', ['store_id' => $store->id, 'tab' => 'accounts']);

        $this->actingAs($manager)->get($url)->assertForbidden();
        $this->enableModule($manager);
        $this->actingAs($manager)->get($url)->assertForbidden();

        $this->entitle($manager, $store, ['finance.foundation.read']);
        config()->set('enterprise_module_readiness.certified_versions.finance', null);
        $this->actingAs($manager)->get($url)->assertForbidden();

        config()->set(
            'enterprise_module_readiness.certified_versions.finance',
            app(ModuleRegistry::class)->manifest('finance')->version,
        );
        $this->actingAs($manager)->get($url)->assertOk();
    }

    public function test_real_wholesale_finance_manager_can_read_in_both_locales_but_read_only_entitlement_blocks_account_creation(): void
    {
        $store = $this->wholesaleStore();
        $manager = $this->manager();
        $this->enableModule($manager);
        $this->entitle($manager, $store, ['finance.foundation.read', 'finance.gl.read']);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $manager->forceFill(['locale' => $locale])->save();

            $this->actingAs($manager->fresh())
                ->get(route('admin.erp.finance.index', ['store_id' => $store->id, 'tab' => 'accounts']))
                ->assertOk()
                ->assertSee('data-finance-workbench="true"', false)
                ->assertSee('dir="'.$direction.'"', false);
        }

        $this->actingAs($manager->fresh())
            ->post(route('admin.erp.finance.accounts.store'), [
                'store_id' => $store->id,
                'code' => 'V2-2693-READ-ONLY',
                'name' => 'Not authorized to create',
                'type' => 'asset',
                'normal_balance' => 'debit',
                'allow_posting' => '1',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('enterprise_finance_accounts', [
            'code' => 'V2-2693-READ-ONLY',
        ]);
        $this->assertFalse($manager->fresh()->hasPermission('finance.approve', $store->id));
    }

    public function test_store_specific_finance_view_deny_rejects_only_the_denied_wholesale_context(): void
    {
        $owned = $this->wholesaleStore();
        $other = $this->wholesaleStore('V2-2693-WHOLESALE-OTHER');
        $manager = $this->manager();
        $this->enableModule($manager);
        $this->entitle($manager, $owned, ['finance.foundation.read']);
        $this->entitle($manager, $other, ['finance.foundation.read']);

        $ownedUrl = route('admin.erp.finance.index', ['store_id' => $owned->id, 'tab' => 'accounts']);
        $otherUrl = route('admin.erp.finance.index', ['store_id' => $other->id, 'tab' => 'accounts']);
        $this->actingAs($manager)->get($ownedUrl)->assertOk();
        $this->actingAs($manager)->get($otherUrl)->assertOk();

        DB::table('user_permission_overrides')->insert([
            'user_id' => $manager->id,
            'permission_id' => Permission::query()->where('code', 'finance.view')->firstOrFail()->id,
            'scope_key' => 'store:'.$other->id,
            'store_id' => $other->id,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $manager->forceFill(['has_permission_overrides' => true])->save();

        $this->assertFalse($manager->fresh()->hasPermission('finance.view', $other->id));
        $this->assertTrue($manager->fresh()->hasPermission('finance.view', $owned->id));
        $this->actingAs($manager->fresh())->get($otherUrl)->assertNotFound();
        $this->actingAs($manager->fresh())->get($ownedUrl)->assertOk();
    }

    private function manager(): User
    {
        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'FINANCE')->firstOrFail());

        return $actor->fresh();
    }

    private function wholesaleStore(?string $code = null): Store
    {
        if ($code === null) {
            $store = Store::query()->findOrFail(app(WholesalePrincipal::class)->storeId());
        } else {
            $store = Store::query()->create([
                'store_type_id' => DB::table('store_types')->where('code', 'B2B')->value('id'),
                'code' => $code,
                'name' => $code,
                'is_active' => true,
            ]);
        }
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    private function retailStore(string $code): Store
    {
        $store = Store::query()->create([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    private function enableModule(User $actor): void
    {
        $lifecycle = app(ModuleLifecycleManager::class);
        $lifecycle->transition(FinanceFoundationService::MODULE, ModuleState::ENABLING, $actor);
        $lifecycle->transition(FinanceFoundationService::MODULE, ModuleState::ENABLED, $actor);
    }

    /** @param list<string> $capabilities */
    private function entitle(User $actor, Store $store, array $capabilities): void
    {
        $scope = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertNotNull($scope);
        app(ModuleEntitlementManager::class)->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope['legal_entity_id']),
            true,
            $capabilities,
            $actor,
        );
    }
}
