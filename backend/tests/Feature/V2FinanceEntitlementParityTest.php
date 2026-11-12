<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Finance\FinanceFoundationService;
use App\Enterprise\Finance\FinanceWorkbenchAccess;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\AdminNavigation;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 #3269: Finance Sidebar and real HTTP access share store-effective
 * permissions AND legal-entity capabilities. Store DENY wins over inheritance.
 */
final class V2FinanceEntitlementParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
        config()->set(
            'enterprise_module_readiness.certified_versions.finance',
            app(ModuleRegistry::class)->manifest(FinanceFoundationService::MODULE)->version,
        );
    }

    public function test_wholesale_legal_entity_inheritance_is_visible_and_http_authorized_in_both_locales(): void
    {
        [$actor, $store] = $this->financeActor('V2-P280-3269-WHOLESALE');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $this->assertTrue($this->financeEntryVisible($actor), $locale.' Finance should be discoverable');
            $this->actingAs($actor->fresh())
                ->get(route('admin.erp.finance.index', ['store_id' => $store->id], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-finance-workbench="true"', false);
        }
    }

    public function test_retail_finance_reader_without_global_erp_role_has_matching_sidebar_and_http_access(): void
    {
        $store = Store::query()->create([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'V2-P280-3269-RETAIL',
            'name' => 'Retail Finance Scope',
            'business_profile' => 'RETAIL_STORE',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->where('scope', 'store')->firstOrFail();
        $role->permissions()->syncWithoutDetaching(
            Permission::query()->where('code', 'finance.view')->pluck('id'),
        );
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertFalse(app(AdminNavigation::class)->canAccessPlatformErp($actor));

        $context = app(TenantContextResolver::class)->retail($actor, $store->id);
        $this->assertNotNull($context->enterpriseScope);
        $registry = app(ModuleRegistry::class);
        if (! $registry->isEnabled(FinanceFoundationService::MODULE)) {
            $lifecycle = app(ModuleLifecycleManager::class);
            $lifecycle->transition(FinanceFoundationService::MODULE, ModuleState::ENABLING, $actor);
            $lifecycle->transition(FinanceFoundationService::MODULE, ModuleState::ENABLED, $actor);
        }
        $this->entitlementManager()->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $context->enterpriseScope->legalEntityId),
            true,
            ['finance.foundation.read'],
            $actor,
        );

        // A scoped Retail role and a valid ledger entitlement do not
        // independently grant platform ERP admission.
        $this->assertFalse($this->financeEntryVisible($actor));
        $this->actingAs($actor->fresh())
            ->get(route('admin.erp.finance.index', ['store_id' => $store->id], false))
            ->assertForbidden();

        $this->grantPlatformErpAccess($actor);
        $this->assertTrue(app(AdminNavigation::class)->canAccessPlatformErp($actor->fresh()));
        $this->assertTrue($this->financeEntryVisible($actor->fresh()));
        $this->actingAs($actor->fresh())
            ->get(route('admin.erp.finance.index', ['store_id' => $store->id], false))
            ->assertOk();
    }

    public function test_store_specific_deny_hides_finance_and_blocks_both_read_and_write_without_changes(): void
    {
        [$actor, $store] = $this->financeActor('V2-P280-3269-DENIED');
        $this->assertTrue($this->financeEntryVisible($actor));

        $this->entitlementManager()->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            false,
            null,
            $actor,
        );

        $this->assertFalse($this->financeEntryVisible($actor));
        $this->actingAs($actor->fresh())
            ->get(route('admin.erp.finance.index', ['store_id' => $store->id], false))
            ->assertForbidden();

        $before = DB::table('enterprise_finance_accounts')->count();
        $this->actingAs($actor->fresh())
            ->post(route('admin.erp.finance.accounts.store', [], false), [
                'store_id' => $store->id,
                'code' => 'V2-P280-3269-DENIED',
                'name' => 'Blocked finance account',
                'type' => 'asset',
                'normal_balance' => 'debit',
            ])
            ->assertForbidden();
        $this->assertSame($before, DB::table('enterprise_finance_accounts')->count());
    }

    public function test_store_read_only_capability_keeps_ledger_visible_but_denies_manage_and_preserves_data(): void
    {
        [$actor, $store] = $this->financeActor('V2-P280-3269-READONLY');
        $this->entitlementManager()->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            true,
            ['finance.foundation.read'],
            $actor,
        );

        $this->assertTrue($this->financeEntryVisible($actor));

        // Legal-entity AR/AP grants cannot advertise tabs denied by a
        // narrower Finance STORE read-only capability subset.
        app()->forgetInstance(AdminNavigation::class);
        $groups = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()));
        $this->assertNull($groups->firstWhere('key', 'receivables'));

        $this->actingAs($actor->fresh())
            ->get(route('admin.erp.finance.index', ['store_id' => $store->id], false))
            ->assertOk();
        $this->actingAs($actor->fresh())
            ->get(route('admin.erp.finance.index', ['store_id' => $store->id, 'tab' => 'receivables'], false))
            ->assertForbidden();

        $before = DB::table('enterprise_finance_accounts')->count();
        $this->actingAs($actor->fresh())
            ->post(route('admin.erp.finance.accounts.store', [], false), [
                'store_id' => $store->id,
                'code' => 'V2-P280-3269-READONLY',
                'name' => 'Should not be created',
                'type' => 'asset',
                'normal_balance' => 'debit',
            ])
            ->assertForbidden();
        $this->assertSame($before, DB::table('enterprise_finance_accounts')->count());
    }

    public function test_store_only_finance_entitlement_never_bypasses_missing_legal_entity_grant(): void
    {
        [$actor, $store, $legalEntityId] = $this->financeActor('V2-P280-3269-STOREONLY');
        $this->entitlementManager()->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $legalEntityId),
            false,
            null,
            $actor,
        );
        $this->entitlementManager()->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            true,
            ['finance.foundation.read', 'finance.foundation.manage'],
            $actor,
        );

        $this->assertFalse(app(FinanceWorkbenchAccess::class)->canRead($actor->fresh(), $store->id));
        $this->assertFalse($this->financeEntryVisible($actor));
        $this->actingAs($actor->fresh())
            ->get(route('admin.erp.finance.index', ['store_id' => $store->id], false))
            ->assertForbidden();
        $this->actingAs($actor->fresh())
            ->post(route('admin.erp.finance.accounts.store', [], false), [
                'store_id' => $store->id,
                'code' => 'V2-P280-3269-STOREONLY',
            ])
            ->assertForbidden();
    }

    public function test_uncertified_finance_module_is_denied_by_existing_http_readiness_middleware(): void
    {
        [$actor, $store] = $this->financeActor('V2-P280-3269-UNCERTIFIED');
        config()->set('enterprise_module_readiness.certified_versions.finance', null);

        $this->assertFalse($this->financeEntryVisible($actor));
        $this->actingAs($actor->fresh())
            ->get(route('admin.erp.finance.index', ['store_id' => $store->id], false))
            ->assertForbidden();
        $this->actingAs($actor->fresh())
            ->post(route('admin.erp.finance.accounts.store', [], false), ['store_id' => $store->id])
            ->assertForbidden();
    }

    /**
     * @return array{User, Store, int}
     */
    private function financeActor(string $code): array
    {
        $store = Store::query()->create([
            'store_type_id' => DB::table('store_types')->where('code', 'B2B')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->grantPlatformErpAccess($actor);
        $financeRole = Role::query()->where('code', 'FINANCE')->where('scope', 'global')->firstOrFail();
        $financeRole->permissions()->syncWithoutDetaching(
            Permission::query()->whereIn('code', ['finance.view', 'finance.manage'])->pluck('id'),
        );
        $actor->roles()->syncWithoutDetaching([$financeRole->id]);

        $context = app(TenantContextResolver::class)->wholesale($actor, $store->id);
        $this->assertNotNull($context->enterpriseScope);

        $registry = app(ModuleRegistry::class);
        if (! $registry->isEnabled(FinanceFoundationService::MODULE)) {
            $lifecycle = app(ModuleLifecycleManager::class);
            $lifecycle->transition(FinanceFoundationService::MODULE, ModuleState::ENABLING, $actor);
            $lifecycle->transition(FinanceFoundationService::MODULE, ModuleState::ENABLED, $actor);
        }

        $legalEntityId = (int) $context->enterpriseScope->legalEntityId;
        $this->entitlementManager()->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $legalEntityId),
            true,
            ['finance.foundation.read', 'finance.foundation.manage', 'finance.gl.read', 'finance.ar.read', 'finance.ap.read'],
            $actor,
        );

        return [$actor, $store, $legalEntityId];
    }

    private function entitlementManager(): ModuleEntitlementManager
    {
        return app(ModuleEntitlementManager::class);
    }

    private function financeEntryVisible(User $actor): bool
    {
        app()->forgetInstance(AdminNavigation::class);

        return collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->contains('key', 'erp_finance');
    }
}
