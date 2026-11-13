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
use App\Support\AdminNavigation;
use App\Support\ResolvedTenantContext;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 #2615 / P285: the Receivables Sidebar children project the same
 * store-specific AR/AP capabilities as the canonical Finance HTTP tabs.
 */
final class V2ReceivablesSidebarGroupAuthorityTest extends TestCase
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

    public function test_receivables_group_links_real_finance_tabs_for_authorized_store_in_both_locales(): void
    {
        [$actor, $store, $context] = $this->reader('V2-P285-OWN');
        $this->enableFinance($actor, $context);

        foreach (['ar' => ['الذمم', 'rtl'], 'en' => ['Receivables & Payables', 'ltr']] as $locale => [$heading, $direction]) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $groups = $this->navigationGroups($actor);
            $matches = $groups->where('key', 'receivables');
            $this->assertCount(1, $matches);
            $group = $matches->first();
            $this->assertSame('admin.nav_groups.receivables', $group['label']);
            $this->assertSame($heading, __($group['label']));

            $items = collect($group['children'])->keyBy('key');
            $this->assertSame(['erp_receivables', 'erp_payables'], $items->keys()->all());

            foreach (['erp_receivables' => 'receivables', 'erp_payables' => 'payables'] as $key => $tab) {
                $item = $items[$key];
                $this->assertSame('admin.erp.finance.index', $item['route']);
                $this->assertSame('finance_workbench.tabs.'.$tab, $item['label']);
                $this->assertSame(['store_id' => (string) $store->id, 'tab' => $tab], $item['params']);
                $this->assertNotSame($item['label'], __($item['label']));
                $this->assertSame(
                    1,
                    $groups->flatMap(static fn (array $entry): array => $entry['children'])
                        ->where('key', $key)->count(),
                );

                $this->actingAs($actor->fresh())
                    ->get(route($item['route'], $item['params'], false))
                    ->assertOk()
                    ->assertSee('dir="'.$direction.'"', false);
            }
        }
    }

    public function test_disabled_or_uncertified_finance_hides_receivables_links_and_denies_direct_tab(): void
    {
        [$actor, $store, $context] = $this->reader('V2-P285-DISABLED');

        $this->assertNull($this->navigationGroups($actor)->firstWhere('key', 'receivables'));
        $this->actingAs($actor)->get($this->tabUrl($store, 'receivables'))->assertForbidden();

        $this->enableFinance($actor, $context);
        $this->assertNotNull($this->navigationGroups($actor)->firstWhere('key', 'receivables'));

        config()->set('enterprise_module_readiness.certified_versions.finance', null);
        $this->assertNull($this->navigationGroups($actor)->firstWhere('key', 'receivables'));
    }

    public function test_ar_only_entitlement_hides_ap_link_and_http_rejects_ap_tab(): void
    {
        [$actor, $store, $context] = $this->reader('V2-P285-AR-ONLY');
        $this->enableFinance($actor, $context, ['finance.ar.read']);

        $group = $this->navigationGroups($actor)->firstWhere('key', 'receivables');
        $this->assertIsArray($group);
        $this->assertSame(['erp_receivables'], collect($group['children'])->pluck('key')->all());

        $this->actingAs($actor)->get($this->tabUrl($store, 'receivables'))->assertOk();
        $this->actingAs($actor)->get($this->tabUrl($store, 'payables'))->assertForbidden();
    }

    public function test_cross_store_and_wholesale_contexts_cannot_be_inferred_from_an_authorized_retail_link(): void
    {
        [$actor, $store, $context] = $this->reader('V2-P285-RETAIL');
        $foreignRetail = $this->store('V2-P285-FOREIGN', 'B2C');
        $foreignWholesale = $this->store('V2-P285-WHOLESALE', 'B2B');
        $this->enableFinance($actor, $context);

        $group = $this->navigationGroups($actor)->firstWhere('key', 'receivables');
        $this->assertIsArray($group);
        foreach ($group['children'] as $item) {
            $this->assertSame((string) $store->id, $item['params']['store_id']);
        }

        foreach ([$foreignRetail, $foreignWholesale] as $foreign) {
            $this->actingAs($actor)->get($this->tabUrl($foreign, 'receivables'))->assertNotFound();
            $this->actingAs($actor)->get($this->tabUrl($foreign, 'payables'))->assertNotFound();
        }
    }

    public function test_explicit_finance_deny_and_inactive_accounts_never_expose_receivables(): void
    {
        [$actor, $store, $context] = $this->reader('V2-P285-DENY');
        $this->enableFinance($actor, $context);
        $this->assertNotNull($this->navigationGroups($actor)->firstWhere('key', 'receivables'));

        $permission = Permission::query()->where('code', 'finance.view')->firstOrFail();
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

        $this->assertNull($this->navigationGroups($actor)->firstWhere('key', 'receivables'));
        $this->actingAs($actor->fresh())->get($this->tabUrl($store, 'receivables'))->assertForbidden();

        $actor->forceFill(['is_active' => false])->save();
        $this->assertNull($this->navigationGroups($actor)->firstWhere('key', 'receivables'));

        $roleless = User::factory()->create(['is_active' => true]);
        $this->assertNull($this->navigationGroups($roleless)->firstWhere('key', 'receivables'));
        $this->actingAs($roleless)->get($this->tabUrl($store, 'receivables'))->assertForbidden();
    }

    private function tabUrl(Store $store, string $tab): string
    {
        return route('admin.erp.finance.index', ['store_id' => $store->id, 'tab' => $tab], false);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function navigationGroups(User $actor): Collection
    {
        app()->forgetInstance(AdminNavigation::class);

        return collect(app(AdminNavigation::class)->groupsFor($actor->fresh()));
    }

    /** @return array{User, Store, ResolvedTenantContext} */
    private function reader(string $code): array
    {
        $store = $this->store($code, 'B2C');
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->where('scope', 'store')->firstOrFail();
        $permission = Permission::query()->where('code', 'finance.view')->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$permission->id]);

        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $this->grantPlatformErpAccess($actor);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$actor, $store, app(TenantContextResolver::class)->retail($actor, $store->id)];
    }

    private function store(string $code, string $channel): Store
    {
        $store = Store::query()->create([
            'store_type_id' => DB::table('store_types')->where('code', $channel)->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    /** @param list<string> $capabilities */
    private function enableFinance(
        User $actor,
        ResolvedTenantContext $context,
        array $capabilities = ['finance.foundation.read', 'finance.gl.read', 'finance.ar.read', 'finance.ap.read'],
    ): void {
        $module = FinanceFoundationService::MODULE;
        $registry = app(ModuleRegistry::class);
        if (! $registry->isEnabled($module)) {
            $lifecycle = app(ModuleLifecycleManager::class);
            $lifecycle->transition($module, ModuleState::ENABLING, $actor);
            $lifecycle->transition($module, ModuleState::ENABLED, $actor);
        }

        $scope = $context->enterpriseScope;
        $this->assertNotNull($scope);
        $manager = app(ModuleEntitlementManager::class);
        $manager->set(
            $module,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope->legalEntityId),
            true,
            $capabilities,
            $actor,
        );
        $manager->set(
            $module,
            new EntitlementScope(EntitlementScope::STORE, $context->storeId),
            true,
            null,
            $actor,
        );
    }
}
