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
 * P288: receivables aging is a real, protected tab in the existing Finance
 * workbench, not a second route or a visibility-only Sidebar grant.
 */
final class V2ReceivablesAgingSidebarContractTest extends TestCase
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

    public function test_receivables_aging_is_not_available_while_finance_module_is_disabled(): void
    {
        [$actor, $store] = $this->operator('P288-DISABLED');

        $this->actingAs($actor)
            ->get(route('admin.erp.finance.index', ['store_id' => $store->id, 'tab' => 'receivables']))
            ->assertForbidden();
    }

    public function test_authorized_retail_finance_user_sees_canonical_aging_tab_in_both_locales_but_not_another_store(): void
    {
        [$actor, $store, $context] = $this->operator('P288-OWN');
        $this->enableFinance($actor, $context, true);
        $foreign = $this->retailStore('P288-FOREIGN');

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();

            $this->actingAs($actor->fresh())
                ->get(route('admin.erp.finance.index', [
                    'store_id' => $store->id,
                    'tab' => 'receivables',
                ]))
                ->assertOk()
                ->assertSee('data-finance-workbench="true"', false)
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__('finance_workbench.common.aging_bucket'));

            // Explicitly selecting another Retail store never broadens the
            // legal-entity AR aging query or returns its documents.
            $this->actingAs($actor->fresh())
                ->get(route('admin.erp.finance.index', [
                    'store_id' => $foreign->id,
                    'tab' => 'receivables',
                ]))
                ->assertNotFound();
        }
    }

    public function test_foundation_entitlement_alone_does_not_expose_ar_aging_and_explicit_deny_revokes_it(): void
    {
        [$actor, $store, $context] = $this->operator('P288-ENTITLEMENT');
        $this->enableFinance($actor, $context, false);

        $url = route('admin.erp.finance.index', ['store_id' => $store->id, 'tab' => 'receivables']);
        $this->actingAs($actor)->get($url)->assertForbidden();

        $this->enableFinance($actor, $context, true);
        $this->actingAs($actor->fresh())->get($url)->assertOk();

        $permission = Permission::query()->where('code', 'finance.view')->firstOrFail();
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => $permission->id,
            'scope_key' => 'store:'.$store->id,
            'store_id' => $store->id,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        $this->actingAs($actor->fresh())->get($url)->assertForbidden();
    }

    /** @return array{User, Store, ResolvedTenantContext} */
    private function operator(string $code): array
    {
        $store = $this->retailStore($code);
        $role = Role::query()
            ->where('code', 'B2C_STORE_ADMIN')
            ->where('scope', 'store')
            ->where('is_active', true)
            ->firstOrFail();
        $permission = Permission::query()->where('code', 'finance.view')->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$permission->id]);

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

    private function enableFinance(User $actor, ResolvedTenantContext $context, bool $ar): void
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
            $ar ? ['finance.foundation.read', 'finance.ar.read'] : ['finance.foundation.read'],
            $actor,
        );
    }
}
