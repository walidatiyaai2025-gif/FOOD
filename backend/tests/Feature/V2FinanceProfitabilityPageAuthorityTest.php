<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Finance\FinanceFoundationService;
use App\Enterprise\Finance\FinancePlanningService;
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
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * V2 #2613 / P283: existing Finance profitability is an authorized
 * profit-center and P&L workflow, not a second commercial BI report.
 */
final class V2FinanceProfitabilityPageAuthorityTest extends TestCase
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

    public function test_existing_profit_center_and_profit_and_loss_are_real_scoped_bilingual_pages(): void
    {
        [$actor, $store, $context] = $this->operator('P283-OWN');
        $this->enableFinance($actor, $context);

        $center = app(FinancePlanningService::class)->createProfitCenter(
            $actor,
            $context,
            ['code' => 'P283-PROFIT', 'name' => 'P283 Retail Profit Center'],
        );
        $this->assertDatabaseHas('enterprise_profit_centers', [
            'id' => $center->id,
            'name' => 'P283 Retail Profit Center',
        ]);

        $finance = app(FinanceFoundationService::class);
        $year = $finance->createFiscalYear($actor, $context, [
            'code' => 'P283FY2027',
            'name' => 'P283 Finance Year',
            'starts_on' => '2027-01-01',
            'ends_on' => '2027-12-31',
        ]);
        $period = $finance->createFiscalPeriod($actor, $context, $year->id, [
            'sequence' => 1,
            'code' => 'P01',
            'name' => 'January 2027',
            'starts_on' => '2027-01-01',
            'ends_on' => '2027-01-31',
        ]);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $this->actingAs($actor->fresh())
                ->get($this->url($store, 'profit_centers'))
                ->assertOk()
                ->assertSee('data-finance-workbench="true"', false)
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__('finance_workbench.profit_centers.title'))
                ->assertSee('P283 Retail Profit Center');

            $this->actingAs($actor->fresh())
                ->get(route('admin.erp.finance.index', [
                    'store_id' => $store->id,
                    'tab' => 'statements',
                    'statement' => 'profit_and_loss',
                    'period_id' => $period->id,
                ]))
                ->assertOk()
                ->assertSee(__('finance_workbench.statements.profit_and_loss'));
        }
    }

    public function test_foreign_retail_store_is_not_an_authorized_profitability_context(): void
    {
        [$actor, $store, $context] = $this->operator('P283-OWN-STORE');
        $this->enableFinance($actor, $context);
        $foreign = $this->createStore('P283-OTHER-STORE');

        $this->actingAs($actor)->get($this->url($store, 'profit_centers'))->assertOk();
        $this->actingAs($actor)->get($this->url($foreign, 'profit_centers'))->assertNotFound();
        $this->actingAs($actor)
            ->get(route('admin.erp.finance.index', [
                'store_id' => $foreign->id,
                'tab' => 'statements',
                'statement' => 'profit_and_loss',
            ]))
            ->assertNotFound();
    }

    public function test_disabled_or_unready_finance_or_missing_capability_denies_direct_profitability(): void
    {
        [$actor, $store, $context] = $this->operator('P283-LIFECYCLE');
        $url = $this->url($store, 'profit_centers');

        $this->actingAs($actor)->get($url)->assertForbidden();

        $this->enableFinance($actor, $context, false);
        $this->actingAs($actor->fresh())->get($url)->assertForbidden();

        $this->enableFinance($actor, $context);
        $this->actingAs($actor->fresh())->get($url)->assertOk();

        config()->set('enterprise_module_readiness.certified_versions.finance', null);
        $this->actingAs($actor->fresh())->get($url)->assertForbidden();
    }

    public function test_read_only_and_multirole_explicit_deny_cannot_write_profit_center(): void
    {
        [$actor, $store, $context] = $this->operator('P283-DENY');
        $this->enableFinance($actor, $context);

        $extra = Role::query()->create([
            'code' => 'P283_FINANCE_READER',
            'name' => 'P283 second finance reader',
            'scope' => 'store',
            'is_active' => true,
            'is_system' => false,
        ]);
        $extra->permissions()->attach(Permission::query()->where('code', 'finance.view')->firstOrFail()->id);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => $extra->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deny($actor, 'finance.manage');
        $this->actingAs($actor->fresh())
            ->get($this->url($store, 'profit_centers'))
            ->assertOk();

        try {
            app(FinancePlanningService::class)->createProfitCenter(
                $actor->fresh(),
                $context,
                ['code' => 'P283-NO-WRITE', 'name' => 'Must not persist'],
            );
            $this->fail('A Finance reader cannot create a profit center.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseMissing('enterprise_profit_centers', ['code' => 'P283-NO-WRITE']);

        $this->deny($actor, 'finance.view');
        $this->actingAs($actor->fresh())
            ->get($this->url($store, 'profit_centers'))
            ->assertForbidden();
    }

    public function test_roleless_and_inactive_users_cannot_open_profitability(): void
    {
        [$actor, $store, $context] = $this->operator('P283-INACTIVE');
        $this->enableFinance($actor, $context);
        $actor->forceFill(['is_active' => false])->save();

        $this->actingAs($actor->fresh())
            ->get($this->url($store, 'profit_centers'))
            ->assertForbidden();

        $roleless = User::factory()->create(['is_active' => true]);
        $this->grantPlatformErpAccess($roleless);
        $this->actingAs($roleless)
            ->get($this->url($store, 'profit_centers'))
            ->assertForbidden();
    }

    /** @return array{User, Store, ResolvedTenantContext} */
    private function operator(string $code): array
    {
        $store = $this->createStore($code);
        $role = Role::query()
            ->where('code', 'B2C_STORE_ADMIN')
            ->where('scope', 'store')
            ->firstOrFail();

        $role->permissions()->syncWithoutDetaching(
            Permission::query()->whereIn('code', ['finance.view', 'finance.manage'])->pluck('id'),
        );

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

    private function createStore(string $code): Store
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

    private function enableFinance(
        User $actor,
        ResolvedTenantContext $context,
        bool $withFoundation = true,
    ): void {
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
            $withFoundation ? [
                'finance.foundation.read',
                'finance.foundation.manage',
                'finance.gl.read',
            ] : ['finance.gl.read'],
            $actor,
        );
    }

    private function deny(User $actor, string $code): void
    {
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', $code)->firstOrFail()->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
    }

    private function url(Store $store, string $tab): string
    {
        return route('admin.erp.finance.index', [
            'store_id' => $store->id,
            'tab' => $tab,
        ]);
    }
}
