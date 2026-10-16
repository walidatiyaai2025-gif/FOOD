<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Finance\FinanceFoundationService;
use App\Enterprise\Finance\FinancialStatementService;
use App\Enterprise\Finance\GeneralLedgerService;
use App\Enterprise\Finance\OpeningBalanceService;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\EnterpriseBusinessUnit;
use App\Models\EnterpriseCompany;
use App\Models\EnterpriseFinanceAccount;
use App\Models\EnterpriseFiscalPeriod;
use App\Models\EnterpriseLegalEntity;
use App\Models\EnterpriseOrganization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\ResolvedTenantContext;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class EnterpriseFinancialStatementsFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_statements_reconcile_posted_gl_roll_up_hierarchy_and_expose_period_state(): void
    {
        $fixture = $this->fixture('FS-CORE');
        $service = app(FinancialStatementService::class);

        $this->postJournal(
            $fixture,
            'jan-sale',
            '2027-01-05',
            [
                ['account_id' => $fixture['cash']->id, 'debit' => '100.000'],
                ['account_id' => $fixture['revenue']->id, 'credit' => '100.000'],
            ],
        );
        $this->postJournal(
            $fixture,
            'jan-expense',
            '2027-01-10',
            [
                ['account_id' => $fixture['expense']->id, 'debit' => '30.000'],
                ['account_id' => $fixture['cash']->id, 'credit' => '30.000'],
            ],
        );

        $trial = $service->trialBalance(
            $fixture['maker'],
            $fixture['maker_context'],
            $fixture['jan']->id,
        );
        $this->assertTrue($trial['reconciled']);
        $this->assertSame(130000, $trial['totals']['debit_mills']);
        $this->assertSame(130000, $trial['totals']['credit_mills']);
        $this->assertSame('open', $trial['period']['status']);

        $profitAndLoss = $service->profitAndLoss(
            $fixture['maker'],
            $fixture['maker_context'],
            $fixture['jan']->id,
        );
        $this->assertSame(100000, $profitAndLoss['totals']['revenue_mills']);
        $this->assertSame(30000, $profitAndLoss['totals']['expense_mills']);
        $this->assertSame(70000, $profitAndLoss['totals']['profit_mills']);

        $revenueParent = collect($profitAndLoss['lines'])
            ->firstWhere('account_id', $fixture['revenue_parent']->id);
        $expenseParent = collect($profitAndLoss['lines'])
            ->firstWhere('account_id', $fixture['expense_parent']->id);
        $this->assertNotNull($revenueParent);
        $this->assertNotNull($expenseParent);
        $this->assertSame(100000, $revenueParent['balance_mills']);
        $this->assertSame(30000, $expenseParent['balance_mills']);
        $this->assertSame(0, $revenueParent['direct_credit_mills']);
        $this->assertSame(0, $expenseParent['direct_debit_mills']);

        app(FinanceFoundationService::class)->closeFiscalPeriod(
            $fixture['maker'],
            $fixture['maker_context'],
            $fixture['jan']->id,
        );

        $closed = $service->trialBalance(
            $fixture['maker'],
            $fixture['maker_context'],
            $fixture['jan']->id,
        );
        $this->assertSame('closed', $closed['period']['status']);
        $this->assertSame($trial['totals'], $closed['totals']);
    }

    public function test_profit_and_loss_supports_explicit_cost_center_provenance_without_inference(): void
    {
        $fixture = $this->fixture('FS-COST');
        $costCenter = app(FinanceFoundationService::class)->createCostCenter(
            $fixture['maker'],
            $fixture['maker_context'],
            ['code' => 'CC-OPS', 'name' => 'Operations'],
        );

        $this->postJournal(
            $fixture,
            'unassigned-sale',
            '2027-01-03',
            [
                ['account_id' => $fixture['cash']->id, 'debit' => '20.000'],
                ['account_id' => $fixture['revenue']->id, 'credit' => '20.000'],
            ],
        );

        $opening = app(OpeningBalanceService::class);
        $batch = $opening->createDraft(
            $fixture['maker'],
            $fixture['maker_context'],
            [
                'idempotency_key' => 'cost-center-provenance',
                'fiscal_period_id' => $fixture['jan']->id,
                'entry_date' => '2027-01-02',
                'description' => 'Explicit cost center provenance',
                'lines' => [
                    [
                        'account_id' => $fixture['expense']->id,
                        'cost_center_id' => $costCenter->id,
                        'debit' => '10.000',
                    ],
                    [
                        'account_id' => $fixture['revenue']->id,
                        'cost_center_id' => $costCenter->id,
                        'credit' => '10.000',
                    ],
                ],
            ],
        );
        $opening->post(
            $fixture['poster'],
            $fixture['poster_context'],
            $batch->id,
        );

        $service = app(FinancialStatementService::class);
        $all = $service->profitAndLoss(
            $fixture['maker'],
            $fixture['maker_context'],
            $fixture['jan']->id,
        );
        $scoped = $service->profitAndLoss(
            $fixture['maker'],
            $fixture['maker_context'],
            $fixture['jan']->id,
            null,
            $costCenter->id,
        );

        $this->assertSame(30000, $all['totals']['revenue_mills']);
        $this->assertSame(10000, $all['totals']['expense_mills']);
        $this->assertSame(20000, $all['totals']['profit_mills']);
        $this->assertSame(10000, $scoped['totals']['revenue_mills']);
        $this->assertSame(10000, $scoped['totals']['expense_mills']);
        $this->assertSame(0, $scoped['totals']['profit_mills']);
        $this->assertSame($costCenter->id, $scoped['cost_center']['id']);

        $drillDown = $service->paginateDrillDown(
            $fixture['maker'],
            $fixture['maker_context'],
            $fixture['jan']->id,
            $fixture['revenue']->id,
            $costCenter->id,
            500,
        );
        $this->assertSame(100, $drillDown->perPage());
        $this->assertSame(1, $drillDown->total());
        $this->assertNotNull($drillDown->items()[0]->journal_id);
        $this->assertNotNull($drillDown->items()[0]->journal_line_id);
        $this->assertNotSame('', $drillDown->items()[0]->journal_number);
    }

    public function test_balance_sheet_is_fiscal_year_bounded_and_comparison_is_single_period_bounded(): void
    {
        $fixture = $this->fixture('FS-BS');
        $service = app(FinancialStatementService::class);

        $this->postJournal(
            $fixture,
            'jan-sale',
            '2027-01-15',
            [
                ['account_id' => $fixture['cash']->id, 'debit' => '40.000'],
                ['account_id' => $fixture['revenue']->id, 'credit' => '40.000'],
            ],
        );
        $this->postJournal(
            $fixture,
            'feb-sale',
            '2027-02-15',
            [
                ['account_id' => $fixture['cash']->id, 'debit' => '15.000'],
                ['account_id' => $fixture['revenue']->id, 'credit' => '15.000'],
            ],
        );

        $balance = $service->balanceSheet(
            $fixture['maker'],
            $fixture['maker_context'],
            $fixture['feb']->id,
            $fixture['jan']->id,
        );
        $this->assertSame('2027-01-01', $balance['window_start']);
        $this->assertSame('2027-02-28', $balance['as_of']);
        $this->assertSame(55000, $balance['totals']['asset_mills']);
        $this->assertSame(55000, $balance['totals']['current_earnings_mills']);
        $this->assertSame(0, $balance['totals']['net_mills']);
        $this->assertSame('2027-01-31', $balance['comparative_as_of']);
        $this->assertSame(40000, $balance['comparative_totals']['asset_mills']);
        $this->assertSame(40000, $balance['comparative_totals']['current_earnings_mills']);
        $this->assertSame(0, $balance['comparative_totals']['net_mills']);

        $pnl = $service->profitAndLoss(
            $fixture['maker'],
            $fixture['maker_context'],
            $fixture['feb']->id,
            $fixture['jan']->id,
        );
        $this->assertSame(15000, $pnl['totals']['revenue_mills']);
        $this->assertSame(40000, $pnl['comparative_totals']['revenue_mills']);
        $revenue = collect($pnl['lines'])
            ->firstWhere('account_id', $fixture['revenue']->id);
        $this->assertSame(15000, $revenue['balance_mills']);
        $this->assertSame(40000, $revenue['comparative_balance_mills']);
        $this->assertSame(-25000, $revenue['variance_mills']);
    }

    public function test_cross_legal_entity_access_fails_closed_and_statement_queries_do_not_scale_per_account(): void
    {
        $fixture = $this->fixture('FS-ISO');
        $other = $this->fixture('FS-OTHER', true);
        $service = app(FinancialStatementService::class);

        try {
            $service->trialBalance(
                $fixture['maker'],
                $fixture['maker_context'],
                $other['jan']->id,
            );
            $this->fail('A period from another legal entity must not be readable.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }

        $this->postJournal(
            $fixture,
            'query-shape',
            '2027-01-06',
            [
                ['account_id' => $fixture['cash']->id, 'debit' => '25.000'],
                ['account_id' => $fixture['revenue']->id, 'credit' => '25.000'],
            ],
        );

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $sql = strtolower((string) $query->sql);
            if (
                str_contains($sql, 'enterprise_finance_accounts')
                || str_contains($sql, 'enterprise_finance_ledger_entries')
                || str_contains($sql, 'enterprise_fiscal_periods')
                || str_contains($sql, 'enterprise_fiscal_years')
            ) {
                $queries[] = $sql;
            }
        });

        $service->profitAndLoss(
            $fixture['maker'],
            $fixture['maker_context'],
            $fixture['jan']->id,
        );

        $this->assertLessThanOrEqual(5, count($queries));
    }

    public function test_disabled_or_unentitled_finance_never_reaches_ledger_statement_queries(): void
    {
        $store = $this->createStore('FS-NO-ENT');
        $actor = $this->userForStore($store, ['finance.view'], 'FS-NO-ENT-USER');
        $context = app(TenantContextResolver::class)->retail($actor, $store->id);

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower((string) $query->sql);
        });

        try {
            app(FinancialStatementService::class)->trialBalance($actor, $context, 1);
            $this->fail('Financial statements require Finance GL entitlement.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertFalse(collect($queries)->contains(
            static fn (string $sql): bool => str_contains($sql, 'enterprise_finance_ledger_entries'),
        ));
    }

    /**
     * @return array{
     *   store:Store,
     *   maker:User,
     *   poster:User,
     *   maker_context:ResolvedTenantContext,
     *   poster_context:ResolvedTenantContext,
     *   jan:EnterpriseFiscalPeriod,
     *   feb:EnterpriseFiscalPeriod,
     *   cash:EnterpriseFinanceAccount,
     *   revenue_parent:EnterpriseFinanceAccount,
     *   revenue:EnterpriseFinanceAccount,
     *   expense_parent:EnterpriseFinanceAccount,
     *   expense:EnterpriseFinanceAccount
     * }
     */
    private function fixture(string $code, bool $newOrganization = false): array
    {
        $store = $this->createStore($code, ! $newOrganization);
        if ($newOrganization) {
            $this->assignStoreToNewOrganization($store, $code);
        }

        $maker = $this->userForStore(
            $store,
            ['finance.view', 'finance.manage', 'finance.post'],
            $code.'-MAKER',
        );
        $poster = $this->userForStore(
            $store,
            ['finance.view', 'finance.post'],
            $code.'-POSTER',
        );
        $makerContext = app(TenantContextResolver::class)->retail($maker, $store->id);
        $posterContext = app(TenantContextResolver::class)->retail($poster, $store->id);
        $this->enableFinance($maker, $makerContext);

        $finance = app(FinanceFoundationService::class);
        $cash = $finance->createAccount(
            $maker,
            $makerContext,
            [
                'code' => '1100-'.$code,
                'name' => 'Cash',
                'type' => 'asset',
                'normal_balance' => 'debit',
                'allow_posting' => true,
            ],
        );
        $revenueParent = $finance->createAccount(
            $maker,
            $makerContext,
            [
                'code' => '4000-'.$code,
                'name' => 'Revenue',
                'type' => 'revenue',
                'normal_balance' => 'credit',
                'is_control' => true,
                'allow_posting' => false,
            ],
        );
        $revenue = $finance->createAccount(
            $maker,
            $makerContext,
            [
                'code' => '4010-'.$code,
                'name' => 'Sales Revenue',
                'type' => 'revenue',
                'normal_balance' => 'credit',
                'parent_id' => $revenueParent->id,
                'allow_posting' => true,
            ],
        );
        $expenseParent = $finance->createAccount(
            $maker,
            $makerContext,
            [
                'code' => '5000-'.$code,
                'name' => 'Expenses',
                'type' => 'expense',
                'normal_balance' => 'debit',
                'is_control' => true,
                'allow_posting' => false,
            ],
        );
        $expense = $finance->createAccount(
            $maker,
            $makerContext,
            [
                'code' => '5010-'.$code,
                'name' => 'Operating Expense',
                'type' => 'expense',
                'normal_balance' => 'debit',
                'parent_id' => $expenseParent->id,
                'allow_posting' => true,
            ],
        );

        $year = $finance->createFiscalYear(
            $maker,
            $makerContext,
            [
                'code' => 'FY2027-'.$code,
                'name' => 'Fiscal Year 2027',
                'starts_on' => '2027-01-01',
                'ends_on' => '2027-12-31',
            ],
        );
        $jan = $finance->createFiscalPeriod(
            $maker,
            $makerContext,
            $year->id,
            [
                'sequence' => 1,
                'code' => 'P01-'.$code,
                'name' => 'January 2027',
                'starts_on' => '2027-01-01',
                'ends_on' => '2027-01-31',
            ],
        );
        $feb = $finance->createFiscalPeriod(
            $maker,
            $makerContext,
            $year->id,
            [
                'sequence' => 2,
                'code' => 'P02-'.$code,
                'name' => 'February 2027',
                'starts_on' => '2027-02-01',
                'ends_on' => '2027-02-28',
            ],
        );

        return [
            'store' => $store,
            'maker' => $maker,
            'poster' => $poster,
            'maker_context' => $makerContext,
            'poster_context' => $posterContext,
            'jan' => $jan,
            'feb' => $feb,
            'cash' => $cash,
            'revenue_parent' => $revenueParent,
            'revenue' => $revenue,
            'expense_parent' => $expenseParent,
            'expense' => $expense,
        ];
    }

    /** @param array<string,mixed> $fixture @param list<array<string,mixed>> $lines */
    private function postJournal(array $fixture, string $key, string $entryDate, array $lines): void
    {
        $ledger = app(GeneralLedgerService::class);
        $journal = $ledger->createDraft(
            $fixture['maker'],
            $fixture['maker_context'],
            [
                'idempotency_key' => $key,
                'entry_date' => $entryDate,
                'description' => $key,
                'lines' => $lines,
            ],
        );
        $ledger->post(
            $fixture['poster'],
            $fixture['poster_context'],
            $journal->id,
        );
    }

    private function enableFinance(User $actor, ResolvedTenantContext $context): void
    {
        $scope = $context->enterpriseScope;
        $this->assertNotNull($scope);

        $registry = app(ModuleRegistry::class);
        if (! $registry->isEnabled(FinanceFoundationService::MODULE)) {
            $lifecycle = app(ModuleLifecycleManager::class);
            $lifecycle->transition(FinanceFoundationService::MODULE, ModuleState::ENABLING, $actor);
            $lifecycle->transition(FinanceFoundationService::MODULE, ModuleState::ENABLED, $actor);
        }

        app(ModuleEntitlementManager::class)->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope->legalEntityId),
            true,
            [
                'finance.foundation.read',
                'finance.foundation.manage',
                'finance.gl.read',
                'finance.gl.manage',
                'finance.gl.post',
                'finance.opening_balances.read',
                'finance.opening_balances.manage',
                'finance.opening_balances.post',
            ],
            $actor,
        );
    }

    private function createStore(string $code, bool $defaultScope = true): Store
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $store = Store::query()->create([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);

        if ($defaultScope) {
            app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);
        }

        return $store;
    }

    private function assignStoreToNewOrganization(Store $store, string $code): void
    {
        $suffix = strtolower(str_replace('-', '_', $code));
        $organization = EnterpriseOrganization::query()->create([
            'code' => 'fs_org_'.$suffix,
            'name' => $code.' Organization',
            'is_active' => true,
        ]);
        $company = EnterpriseCompany::query()->create([
            'organization_id' => $organization->id,
            'code' => 'fs_company_'.$suffix,
            'name' => $code.' Company',
            'is_active' => true,
        ]);
        $legalEntity = EnterpriseLegalEntity::query()->create([
            'company_id' => $company->id,
            'code' => 'fs_legal_'.$suffix,
            'name' => $code.' Legal Entity',
            'is_active' => true,
        ]);
        $businessUnit = EnterpriseBusinessUnit::query()->create([
            'legal_entity_id' => $legalEntity->id,
            'code' => 'fs_unit_'.$suffix,
            'name' => $code.' Business Unit',
            'is_active' => true,
        ]);

        app(OrganizationHierarchyService::class)->assignStore($store, $businessUnit);
    }

    /** @param list<string> $permissions */
    private function userForStore(Store $store, array $permissions, string $code): User
    {
        $role = Role::query()->create([
            'code' => 'ROLE_'.str_replace('-', '_', $code),
            'name' => $code,
            'scope' => 'store',
            'is_active' => true,
            'is_system' => false,
        ]);

        foreach ($permissions as $permissionCode) {
            $permission = Permission::query()->firstOrCreate(
                ['code' => $permissionCode],
                ['name' => $permissionCode],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user = User::factory()->create(['is_active' => true]);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }
}
