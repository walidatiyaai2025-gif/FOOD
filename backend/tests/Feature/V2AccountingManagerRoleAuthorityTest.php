<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Finance\FinanceApprovalService;
use App\Enterprise\Finance\FinanceFoundationService;
use App\Enterprise\Finance\GeneralLedgerService;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\BuiltInJobRoleCatalog;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * V2 #2694 / T122R14: the Accounting Manager has a scoped and
 * operationally usable Wholesale checker persona, not blanket admin rights.
 */
final class V2AccountingManagerRoleAuthorityTest extends TestCase
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

    public function test_accounting_manager_is_gated_by_finance_module_and_never_inherits_retail_security_or_procurement_roles(): void
    {
        $store = $this->wholesaleStore();
        $manager = $this->accountantManager();
        $catalog = app(BuiltInJobRoleCatalog::class);
        $definition = $catalog->definition('ACCOUNTING_MANAGER');

        $this->assertSame('finance', $definition['domain']);
        $this->assertSame('global', $definition['scope']);
        $this->assertSame('tenant', $definition['business_scope']);
        $this->assertSame('manager', $definition['level']);
        $this->assertContains('finance', $definition['required_modules']);
        $this->assertNotContains('ACCOUNTING_MANAGER', config('admin.channels.b2c.global_roles'));

        $role = Role::query()->where('code', 'ACCOUNTING_MANAGER')->firstOrFail();
        $this->assertTrue((bool) $role->is_system);
        $grants = $role->permissions()->pluck('code')->all();
        foreach (['finance.view', 'finance.manage', 'finance.post', 'finance.approve', 'reports.view'] as $ability) {
            $this->assertContains($ability, $grants);
        }
        foreach (['security.approve', 'users.delete', 'roles.manage', 'procurement.purchase_orders.issue'] as $ability) {
            $this->assertNotContains($ability, $grants);
        }

        $this->assertFalse($catalog->isAvailable('ACCOUNTING_MANAGER'));
        $this->assertFalse($manager->fresh()->hasPermission('finance.view'));
        $this->assertFalse($manager->fresh()->hasPermission('finance.approve'));

        $this->enableFinance($manager);
        $this->assertTrue($catalog->isAvailable('ACCOUNTING_MANAGER'));
        $this->assertTrue($manager->fresh()->hasPermission('finance.view'));
        $this->assertTrue($manager->fresh()->hasPermission('finance.approve'));

        // A single native Accounting Manager must reach the B2B workspace
        // without a fake extra FINANCE/SUPER_ADMIN assignment.
        $this->assertContains('ACCOUNTING_MANAGER', config('admin.channels.b2b.global_roles'));
        $this->assertTrue(app(AdminNavigation::class)->canAccess($manager->fresh(), 'b2b'));
        $this->assertFalse(app(AdminNavigation::class)->canAccess($manager->fresh(), 'b2c'));
        $this->assertTrue($manager->fresh()->hasPermission('finance.view', $store->id));
    }

    public function test_real_accounting_manager_finance_http_requires_entitlement_and_stays_wholesale_only_in_both_locales(): void
    {
        $store = $this->wholesaleStore();
        $manager = $this->accountantManager();
        $url = route('admin.erp.finance.index', ['store_id' => $store->id, 'tab' => 'accounts']);

        $this->actingAs($manager)->get($url)->assertForbidden();
        $this->enableFinance($manager);
        $this->actingAs($manager)->get($url)->assertForbidden();

        $this->entitle($manager, $store, [
            'finance.foundation.read', 'finance.gl.read',
        ]);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $manager->forceFill(['locale' => $locale])->save();
            $this->actingAs($manager->fresh())
                ->get($url)
                ->assertOk()
                ->assertSee('data-finance-workbench="true"', false)
                ->assertSee('dir="'.$direction.'"', false);
        }

        $retail = $this->retailStore();
        $this->actingAs($manager->fresh())
            ->get(route('admin.erp.finance.index', ['store_id' => $retail->id, 'tab' => 'accounts']))
            ->assertNotFound();

        // A Finance checker still cannot bypass module capability limits.
        $this->actingAs($manager->fresh())
            ->post(route('admin.erp.finance.accounts.store'), [
                'store_id' => $store->id,
                'code' => 'V2-2694-UNENTITLED',
                'name' => 'Not allowed',
                'type' => 'asset',
                'normal_balance' => 'debit',
                'allow_posting' => '1',
            ])
            ->assertForbidden();
        $this->assertDatabaseMissing('enterprise_finance_accounts', ['code' => 'V2-2694-UNENTITLED']);
    }

    public function test_actual_accounting_manager_checker_requires_separate_maker_approver_and_poster(): void
    {
        $store = $this->wholesaleStore();
        $maker = $this->accountantManager();
        $checker = $this->accountantManager();
        $poster = $this->accountantManager();
        $this->enableFinance($maker);
        $this->entitle($maker, $store, [
            'finance.foundation.read', 'finance.foundation.manage',
            'finance.gl.read', 'finance.gl.manage', 'finance.gl.post',
            'finance.approvals.manage', 'finance.approvals.approve',
        ]);

        $resolver = app(TenantContextResolver::class);
        $makerContext = $resolver->wholesale($maker, $store->id);
        $checkerContext = $resolver->wholesale($checker, $store->id);
        $posterContext = $resolver->wholesale($poster, $store->id);

        $finance = app(FinanceFoundationService::class);
        $cash = $finance->createAccount($maker, $makerContext, [
            'code' => '11002694', 'name' => 'Cash', 'type' => 'asset',
            'normal_balance' => 'debit', 'allow_posting' => true,
        ]);
        $revenue = $finance->createAccount($maker, $makerContext, [
            'code' => '40002694', 'name' => 'Revenue', 'type' => 'revenue',
            'normal_balance' => 'credit', 'allow_posting' => true,
        ]);
        $year = $finance->createFiscalYear($maker, $makerContext, [
            'code' => 'FY2694', 'name' => 'FY 2694',
            'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31',
        ]);
        $finance->createFiscalPeriod($maker, $makerContext, $year->id, [
            'sequence' => 1, 'code' => 'JAN',
            'name' => 'January 2027',
            'starts_on' => '2027-01-01', 'ends_on' => '2027-01-31',
        ]);

        $approvals = app(FinanceApprovalService::class);
        $approvals->defineLimit($maker, $makerContext, [
            'policy_key' => 'v2-2694-accounting-checker',
            'role_id' => Role::query()->where('code', 'ACCOUNTING_MANAGER')->firstOrFail()->id,
            'action' => FinanceApprovalService::ACTION_GL_POST,
            'max_amount' => '300.000',
            'effective_from' => now()->subMinute(),
        ]);

        $ledger = app(GeneralLedgerService::class);
        $journal = $ledger->createDraft($maker, $makerContext, [
            'idempotency_key' => 'v2-2694-journal',
            'entry_date' => '2027-01-12',
            'description' => 'Independent manager approval boundary',
            'lines' => [
                ['account_id' => $cash->id, 'debit' => '100.000', 'credit' => '0.000'],
                ['account_id' => $revenue->id, 'debit' => '0.000', 'credit' => '100.000'],
            ],
        ]);

        $approval = $approvals->requestApproval(
            $maker, $makerContext, FinanceApprovalService::ACTION_GL_POST,
            'enterprise_finance_journal', $journal->id, '100.000',
        );

        $this->assertForbidden(fn () => $approvals->approve($maker, $makerContext, $approval->id));
        $approved = $approvals->approve($checker, $checkerContext, $approval->id);
        $this->assertSame('APPROVED', $approved->status->value);

        $this->assertValidationError('poster', fn () => $ledger->post($maker, $makerContext, $journal->id));
        $this->assertValidationError('poster', fn () => $ledger->post($checker, $checkerContext, $journal->id));

        $posted = $ledger->post($poster, $posterContext, $journal->id);
        $this->assertSame('posted', $posted->status);
        $this->assertSame($poster->id, $posted->posted_by);
        $this->assertDatabaseCount('enterprise_finance_ledger_entries', 2);
        $this->assertDatabaseHas('audit_logs', ['event' => 'enterprise.finance.journal_posted', 'auditable_id' => $journal->id]);
    }

    private function accountantManager(): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', 'ACCOUNTING_MANAGER')->firstOrFail());
        return $user->fresh();
    }

    private function wholesaleStore(): Store
    {
        $store = Store::query()->findOrFail(app(WholesalePrincipal::class)->storeId());
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);
        return $store;
    }

    private function retailStore(): Store
    {
        $store = Store::query()->create([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'V2-2694-FOREIGN-RETAIL', 'name' => 'Foreign Retail', 'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);
        return $store;
    }

    private function enableFinance(User $actor): void
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

    private function assertForbidden(callable $callback): void
    {
        try {
            $callback();
            $this->fail('A workflow requester cannot approve their own Finance transaction.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    private function assertValidationError(string $field, callable $callback): void
    {
        try {
            $callback();
            $this->fail("Expected Finance SoD rejection: {$field}");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }
}
