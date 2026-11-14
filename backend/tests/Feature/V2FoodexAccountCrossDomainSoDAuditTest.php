<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Finance\FinanceApprovalService;
use App\Enterprise\Finance\FinanceFoundationService;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * V2 #2768 / T019R02: synthetic procurement + accounting + treasury
 * SoD regression; never use the historical exported account identifier.
 */
final class V2FoodexAccountCrossDomainSoDAuditTest extends TestCase
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

    public function test_combined_procurement_and_finance_roles_do_not_override_finance_readiness_or_retail_scope(): void
    {
        $actor = $this->crossDomainActor();
        $store = $this->wholesaleStore();

        $this->assertTrue($actor->hasRole('PROCUREMENT_MANAGER'));
        $this->assertTrue($actor->hasRole('ACCOUNTING_MANAGER'));
        $this->assertTrue($actor->hasRole('TREASURY_OFFICER'));
        $this->assertFalse($actor->hasRole('SUPER_ADMIN'));
        $this->assertFalse($actor->hasPermission('users.delete'));
        $this->assertFalse($actor->hasPermission('roles.manage'));
        $this->assertFalse($actor->hasPermission('finance.approve', $store->id));

        $url = route('admin.erp.finance.index', ['store_id' => $store->id, 'tab' => 'accounts']);
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $this->actingAs($actor->fresh())->get($url)->assertForbidden();
            $this->actingAs($actor->fresh())->get(route('admin.sidebar-access.roles', [], false))
                ->assertForbidden();
            $this->assertFalse(app(AdminNavigation::class)->canAccess($actor->fresh(), 'b2c'));
        }

        $this->enableFinance($actor);
        $this->assertTrue($actor->fresh()->hasPermission('finance.approve', $store->id));
        // The module alone does not grant the finance read capability.
        $this->actingAs($actor->fresh())->get($url)->assertForbidden();

        $this->entitleFinance($actor, $store);
        $this->actingAs($actor->fresh())->get($url)->assertOk();

        $retail = Store::query()->create([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'V2-2768-RETAIL',
            'name' => 'Unrelated Retail Store',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($retail);
        $this->actingAs($actor->fresh())
            ->get(route('admin.erp.finance.index', ['store_id' => $retail->id, 'tab' => 'accounts']))
            ->assertNotFound();
    }

    public function test_multi_role_finance_maker_cannot_approve_own_request_even_with_treasury_and_procurement_roles(): void
    {
        $store = $this->wholesaleStore();
        $maker = $this->crossDomainActor();
        $checker = $this->accountingChecker();
        $this->enableFinance($maker);
        $this->entitleFinance($maker, $store);

        $this->assertTrue($maker->hasRole('PROCUREMENT_MANAGER'));
        $this->assertTrue($maker->hasRole('TREASURY_OFFICER'));
        $this->assertTrue($maker->fresh()->hasPermission('finance.approve', $store->id));

        $tenant = app(TenantContextResolver::class);
        $makerContext = $tenant->wholesale($maker, $store->id);
        $checkerContext = $tenant->wholesale($checker, $store->id);
        $approval = app(FinanceApprovalService::class);
        $approval->defineLimit($maker, $makerContext, [
            'policy_key' => 'v2-2768-cross-domain-sod',
            'role_id' => Role::query()->where('code', 'ACCOUNTING_MANAGER')->firstOrFail()->id,
            'action' => FinanceApprovalService::ACTION_GL_POST,
            'max_amount' => '500.000',
            'effective_from' => now()->subMinute(),
        ]);
        $request = $approval->requestApproval(
            $maker,
            $makerContext,
            FinanceApprovalService::ACTION_GL_POST,
            'v2-2768-synthetic-transaction',
            'cross-domain-check',
            '100.000',
        );

        try {
            $approval->approve($maker, $makerContext, $request->id);
            $this->fail('A cross-domain maker must not approve their own transaction.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'enterprise.finance.approval_authorized',
            'auditable_id' => $request->id,
        ]);

        $approved = $approval->approve($checker, $checkerContext, $request->id);
        $this->assertSame('APPROVED', $approved->status->value);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.finance.approval_authorized',
            'auditable_id' => $request->id,
        ]);
    }

    private function crossDomainActor(): User
    {
        $codes = ['INVENTORY', 'PROCUREMENT_MANAGER', 'ACCOUNTING_MANAGER', 'TREASURY_OFFICER'];
        $roles = Role::query()->whereIn('code', $codes)->get();
        $this->assertCount(count($codes), $roles);

        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->sync($roles->modelKeys());

        return $actor->fresh();
    }

    private function accountingChecker(): User
    {
        $checker = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $checker->roles()->attach(Role::query()->where('code', 'ACCOUNTING_MANAGER')->firstOrFail());

        return $checker->fresh();
    }

    private function wholesaleStore(): Store
    {
        $store = Store::query()->findOrFail(app(WholesalePrincipal::class)->storeId());
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    private function enableFinance(User $actor): void
    {
        $lifecycle = app(ModuleLifecycleManager::class);
        $lifecycle->transition(FinanceFoundationService::MODULE, ModuleState::ENABLING, $actor);
        $lifecycle->transition(FinanceFoundationService::MODULE, ModuleState::ENABLED, $actor);
    }

    private function entitleFinance(User $actor, Store $store): void
    {
        $scope = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertNotNull($scope);
        app(ModuleEntitlementManager::class)->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope['legal_entity_id']),
            true,
            [
                'finance.foundation.read',
                'finance.foundation.manage',
                'finance.gl.read',
                'finance.gl.manage',
                'finance.gl.post',
                'finance.approvals.manage',
                'finance.approvals.approve',
            ],
            $actor,
        );
    }
}
