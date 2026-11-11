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
use App\Support\ResolvedTenantContext;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P309: financial reporting reuses the real finance workbench with legal
 * entity, store, permission and module-entitlement restrictions.
 */
final class V2FinancialReportsSidebarAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
        config()->set('security.mfa.enforce_privileged', false);
        config()->set(
            'enterprise_module_readiness.certified_versions.finance',
            app(ModuleRegistry::class)->manifest('finance')->version,
        );
    }

    public function test_financial_reports_are_forbidden_while_finance_module_is_disabled(): void
    {
        [$actor, $store] = $this->operator('P309-DISABLED');

        $this->actingAs($actor)->get($this->url($store))->assertForbidden();
    }

    public function test_scoped_financial_reports_and_statements_are_localized_and_reject_foreign_store(): void
    {
        [$actor, $store, $context] = $this->operator('P309-OWN');
        $this->enableFinance($actor, $context, ['finance.foundation.read', 'finance.gl.read']);
        $foreign = $this->store('P309-FOREIGN');

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $this->actingAs($actor->fresh())
                ->get($this->url($store))
                ->assertOk()
                ->assertSee('data-finance-workbench="true"', false)
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__('finance_workbench.reports.title'));

            $this->actingAs($actor->fresh())
                ->get(route('admin.erp.finance.index', ['store_id' => $store->id, 'tab' => 'statements']))
                ->assertOk()
                ->assertSee(__('finance_workbench.tabs.statements'));

            $this->actingAs($actor->fresh())->get($this->url($foreign))->assertNotFound();
        }
    }

    public function test_gl_read_does_not_grant_foundation_financial_reports_without_entitlement(): void
    {
        [$actor, $store, $context] = $this->operator('P309-ENTITLEMENT');
        $this->enableFinance($actor, $context, ['finance.gl.read']);

        $this->actingAs($actor->fresh())->get($this->url($store))->assertForbidden();

        $this->enableFinance($actor, $context, ['finance.foundation.read', 'finance.gl.read']);
        $this->actingAs($actor->fresh())->get($this->url($store))->assertOk();
    }

    public function test_explicit_finance_deny_revokes_reports_but_keeps_store_role(): void
    {
        [$actor, $store, $context] = $this->operator('P309-DENY');
        $this->enableFinance($actor, $context, ['finance.foundation.read']);

        $this->actingAs($actor)->get($this->url($store))->assertOk();

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', 'finance.view')->firstOrFail()->id,
            'scope_key' => 'store:'.$store->id,
            'store_id' => $store->id,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        $this->actingAs($actor->fresh())->get($this->url($store))->assertForbidden();
        $this->assertDatabaseHas('user_store_roles', [
            'user_id' => $actor->id,
            'store_id' => $store->id,
        ]);
    }

    /** @return array{User, Store, ResolvedTenantContext} */
    private function operator(string $code): array
    {
        $store = $this->store($code);
        $role = Role::query()
            ->where('code', 'B2C_STORE_ADMIN')
            ->where('scope', 'store')
            ->where('is_active', true)
            ->firstOrFail();
        $role->permissions()->syncWithoutDetaching([
            Permission::query()->where('code', 'finance.view')->firstOrFail()->id,
        ]);

        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
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

    private function store(string $code): Store
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

    private function url(Store $store): string
    {
        return route('admin.erp.finance.index', ['store_id' => $store->id, 'tab' => 'reports']);
    }

    /** @param list<string> $capabilities */
    private function enableFinance(User $actor, ResolvedTenantContext $context, array $capabilities): void
    {
        $scope = $context->enterpriseScope;
        $this->assertNotNull($scope);

        if (! app(ModuleRegistry::class)->isEnabled(FinanceFoundationService::MODULE)) {
            $lifecycle = app(ModuleLifecycleManager::class);
            $lifecycle->transition(FinanceFoundationService::MODULE, ModuleState::ENABLING, $actor);
            $lifecycle->transition(FinanceFoundationService::MODULE, ModuleState::ENABLED, $actor);
        }

        app(ModuleEntitlementManager::class)->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope->legalEntityId),
            true,
            $capabilities,
            $actor,
        );
    }
}
