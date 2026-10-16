<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Finance\AccountsPayableService;
use App\Enterprise\Finance\AccountsReceivableService;
use App\Enterprise\Finance\FinanceApprovalService;
use App\Enterprise\Finance\FinanceFoundationService;
use App\Enterprise\Finance\GeneralLedgerService;
use App\Enterprise\Finance\OpeningBalanceService;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\ResolvedTenantContext;
use App\Support\TenantContextResolver;
use Carbon\CarbonImmutable;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class EnterpriseFinanceApprovalFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_gl_approval_limit_enforces_maker_checker_poster_and_retry_safety(): void
    {
        $fixture = $this->fixture('FIN-SOD');
        $approvals = app(FinanceApprovalService::class);
        $gl = app(GeneralLedgerService::class);

        $limit = $approvals->defineLimit(
            $fixture['maker'],
            $fixture['maker_context'],
            [
                'policy_key' => 'gl-checker-250',
                'role_id' => $fixture['checker_role']->id,
                'action' => FinanceApprovalService::ACTION_GL_POST,
                'max_amount' => '250.000',
                'currency_code' => null,
                'effective_from' => now()->subMinute(),
            ],
        );
        $limitRetry = $approvals->defineLimit(
            $fixture['maker'],
            $fixture['maker_context'],
            [
                'policy_key' => 'gl-checker-250',
                'role_id' => $fixture['checker_role']->id,
                'action' => FinanceApprovalService::ACTION_GL_POST,
                'max_amount' => '250.000',
                'currency_code' => null,
                'effective_from' => $limit->effective_from,
            ],
        );
        $this->assertSame($limit->id, $limitRetry->id);

        $journal = $gl->createDraft(
            $fixture['maker'],
            $fixture['maker_context'],
            [
                'idempotency_key' => 'gl-sod-001',
                'entry_date' => '2027-01-10',
                'description' => 'Approval-controlled journal',
                'lines' => [
                    [
                        'account_id' => $fixture['cash_account_id'],
                        'debit' => '100.000',
                        'credit' => '0.000',
                    ],
                    [
                        'account_id' => $fixture['revenue_account_id'],
                        'debit' => '0.000',
                        'credit' => '100.000',
                    ],
                ],
            ],
        );

        $request = $approvals->requestApproval(
            $fixture['maker'],
            $fixture['maker_context'],
            FinanceApprovalService::ACTION_GL_POST,
            'enterprise_finance_journal',
            $journal->id,
            '100.000',
        );
        $requestRetry = $approvals->requestApproval(
            $fixture['maker'],
            $fixture['maker_context'],
            FinanceApprovalService::ACTION_GL_POST,
            'enterprise_finance_journal',
            $journal->id,
            '100.000',
        );
        $this->assertSame($request->id, $requestRetry->id);

        try {
            $gl->post($fixture['poster'], $fixture['poster_context'], $journal->id);
            $this->fail('Posting must fail until checker approval is complete.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('approval', $exception->errors());
        }

        try {
            $approvals->approve(
                $fixture['maker'],
                $fixture['maker_context'],
                $request->id,
            );
            $this->fail('The Finance maker must not self-check.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('approval_limit', $exception->errors());
        }

        $approved = $approvals->approve(
            $fixture['checker'],
            $fixture['checker_context'],
            $request->id,
            'Checked within delegated responsibility.',
        );
        $approvedRetry = $approvals->approve(
            $fixture['checker'],
            $fixture['checker_context'],
            $request->id,
        );
        $this->assertSame($approved->id, $approvedRetry->id);
        $this->assertSame('APPROVED', $approved->status->value);

        try {
            $gl->post($fixture['checker'], $fixture['checker_context'], $journal->id);
            $this->fail('The Finance checker must not post the same transaction.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('poster', $exception->errors());
        }

        $posted = $gl->post(
            $fixture['poster'],
            $fixture['poster_context'],
            $journal->id,
        );
        $postedRetry = $gl->post(
            $fixture['poster'],
            $fixture['poster_context'],
            $journal->id,
        );

        $this->assertSame('posted', $posted->status);
        $this->assertSame($posted->id, $postedRetry->id);
        $this->assertDatabaseCount('enterprise_finance_ledger_entries', 2);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.finance.approval_authorized',
            'auditable_id' => $request->id,
        ]);
    }

    public function test_limits_are_server_authoritative_and_cross_scope_approval_fails_closed(): void
    {
        $fixture = $this->fixture('FIN-LIMIT');
        $approvals = app(FinanceApprovalService::class);
        $gl = app(GeneralLedgerService::class);

        $approvals->defineLimit(
            $fixture['maker'],
            $fixture['maker_context'],
            [
                'policy_key' => 'gl-checker-50',
                'role_id' => $fixture['checker_role']->id,
                'action' => FinanceApprovalService::ACTION_GL_POST,
                'max_amount' => '50.000',
                'effective_from' => now()->subMinute(),
            ],
        );

        $journal = $gl->createDraft(
            $fixture['maker'],
            $fixture['maker_context'],
            [
                'idempotency_key' => 'gl-limit-001',
                'entry_date' => '2027-01-11',
                'lines' => [
                    [
                        'account_id' => $fixture['cash_account_id'],
                        'debit' => '75.000',
                        'credit' => '0.000',
                    ],
                    [
                        'account_id' => $fixture['revenue_account_id'],
                        'debit' => '0.000',
                        'credit' => '75.000',
                    ],
                ],
            ],
        );

        $request = $approvals->requestApproval(
            $fixture['maker'],
            $fixture['maker_context'],
            FinanceApprovalService::ACTION_GL_POST,
            'enterprise_finance_journal',
            $journal->id,
            '75.000',
        );

        try {
            $approvals->approve(
                $fixture['checker'],
                $fixture['checker_context'],
                $request->id,
            );
            $this->fail('A checker cannot approve above the server-side limit.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('approval_limit', $exception->errors());
        }

        $otherStore = $this->createStore('FIN-OTHER');
        $otherChecker = $this->userForStore(
            $otherStore,
            ['finance.view', 'finance.approve'],
            'FIN-OTHER-CHECKER',
        );
        $otherContext = app(TenantContextResolver::class)->retail(
            $otherChecker['user'],
            $otherStore->id,
        );

        try {
            $approvals->approve(
                $otherChecker['user'],
                $otherContext,
                $request->id,
            );
            $this->fail('Cross-legal-entity approval must fail closed.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_expired_delegation_is_rejected_and_active_delegation_preserves_provenance(): void
    {
        $fixture = $this->fixture('FIN-DEL');
        $approvals = app(FinanceApprovalService::class);
        $gl = app(GeneralLedgerService::class);

        $delegator = $this->userForStore(
            $fixture['store'],
            ['finance.view', 'finance.approve'],
            'FIN-DEL-DELEGATOR',
        );
        $delegate = $this->userForStore(
            $fixture['store'],
            ['finance.view'],
            'FIN-DEL-DELEGATE',
        );
        $expiredDelegate = $this->userForStore(
            $fixture['store'],
            ['finance.view'],
            'FIN-DEL-EXPIRED',
        );

        $limit = $approvals->defineLimit(
            $fixture['maker'],
            $fixture['maker_context'],
            [
                'policy_key' => 'gl-delegator-500',
                'role_id' => $delegator['role']->id,
                'action' => FinanceApprovalService::ACTION_GL_POST,
                'max_amount' => '500.000',
                'effective_from' => now()->subDay(),
            ],
        );

        $delegatorContext = app(TenantContextResolver::class)->retail(
            $delegator['user'],
            $fixture['store']->id,
        );
        $delegateContext = app(TenantContextResolver::class)->retail(
            $delegate['user'],
            $fixture['store']->id,
        );
        $expiredContext = app(TenantContextResolver::class)->retail(
            $expiredDelegate['user'],
            $fixture['store']->id,
        );

        $activeDelegation = $approvals->delegateLimit(
            $delegator['user'],
            $delegatorContext,
            $limit->id,
            [
                'delegation_key' => 'active-delegation',
                'delegate_user_id' => $delegate['user']->id,
                'starts_at' => now()->subHour(),
                'ends_at' => now()->addHour(),
            ],
        );
        $approvals->delegateLimit(
            $delegator['user'],
            $delegatorContext,
            $limit->id,
            [
                'delegation_key' => 'expired-delegation',
                'delegate_user_id' => $expiredDelegate['user']->id,
                'starts_at' => now()->subDays(2),
                'ends_at' => now()->subDay(),
            ],
        );

        $journal = $gl->createDraft(
            $fixture['maker'],
            $fixture['maker_context'],
            [
                'idempotency_key' => 'gl-delegation-001',
                'entry_date' => '2027-01-12',
                'lines' => [
                    [
                        'account_id' => $fixture['cash_account_id'],
                        'debit' => '125.000',
                        'credit' => '0.000',
                    ],
                    [
                        'account_id' => $fixture['revenue_account_id'],
                        'debit' => '0.000',
                        'credit' => '125.000',
                    ],
                ],
            ],
        );
        $request = $approvals->requestApproval(
            $fixture['maker'],
            $fixture['maker_context'],
            FinanceApprovalService::ACTION_GL_POST,
            'enterprise_finance_journal',
            $journal->id,
            '125.000',
        );

        try {
            $approvals->approve(
                $expiredDelegate['user'],
                $expiredContext,
                $request->id,
            );
            $this->fail('Expired delegation must not authorize approval.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('approval_limit', $exception->errors());
        }

        $approved = $approvals->approve(
            $delegate['user'],
            $delegateContext,
            $request->id,
        );

        $this->assertSame(
            $activeDelegation->id,
            $approved->context['approval_delegation_id'],
        );
        $this->assertSame($limit->id, $approved->context['approval_limit_id']);
    }

    public function test_opening_balance_ar_and_ap_post_only_after_their_own_checker_workflow(): void
    {
        $fixture = $this->fixture('FIN-DOMAINS');
        $approvals = app(FinanceApprovalService::class);

        foreach ([
            FinanceApprovalService::ACTION_OPENING_BALANCE_POST,
            FinanceApprovalService::ACTION_AR_POST,
            FinanceApprovalService::ACTION_AP_POST,
        ] as $action) {
            $approvals->defineLimit(
                $fixture['maker'],
                $fixture['maker_context'],
                [
                    'policy_key' => str_replace('.', '-', $action),
                    'role_id' => $fixture['checker_role']->id,
                    'action' => $action,
                    'max_amount' => '1000.000',
                    'effective_from' => now()->subMinute(),
                ],
            );
        }

        $opening = app(OpeningBalanceService::class)->createDraft(
            $fixture['maker'],
            $fixture['maker_context'],
            [
                'idempotency_key' => 'opening-approved',
                'fiscal_period_id' => $fixture['period_id'],
                'entry_date' => '2027-01-01',
                'description' => 'Controlled opening balance',
                'lines' => [
                    [
                        'account_id' => $fixture['cash_account_id'],
                        'debit' => '300.000',
                        'credit' => '0.000',
                    ],
                    [
                        'account_id' => $fixture['equity_account_id'],
                        'debit' => '0.000',
                        'credit' => '300.000',
                    ],
                ],
            ],
        );
        $this->approveEntity(
            $approvals,
            $fixture,
            FinanceApprovalService::ACTION_OPENING_BALANCE_POST,
            'enterprise_finance_opening_balance',
            $opening->id,
            (string) $opening->total_debit,
            null,
        );
        $postedOpening = app(OpeningBalanceService::class)->post(
            $fixture['poster'],
            $fixture['poster_context'],
            $opening->id,
        );
        $this->assertSame('posted', $postedOpening->status);

        $ar = app(AccountsReceivableService::class)->createDraft(
            $fixture['maker'],
            $fixture['maker_context'],
            [
                'idempotency_key' => 'ar-approved',
                'store_id' => $fixture['store']->id,
                'customer_id' => $fixture['customer']->id,
                'entry_date' => '2027-01-15',
                'due_date' => '2027-02-15',
                'amount' => '80.000',
                'currency_code' => 'KWD',
                'receivable_account_id' => $fixture['receivable_account_id'],
                'offset_account_id' => $fixture['revenue_account_id'],
            ],
        );
        $this->approveEntity(
            $approvals,
            $fixture,
            FinanceApprovalService::ACTION_AR_POST,
            'enterprise_ar_document',
            $ar->id,
            (string) $ar->amount,
            (string) $ar->currency_code,
        );
        $postedAr = app(AccountsReceivableService::class)->post(
            $fixture['poster'],
            $fixture['poster_context'],
            $ar->id,
        );
        $this->assertSame('posted', $postedAr->status);

        $ap = app(AccountsPayableService::class)->createDraft(
            $fixture['maker'],
            $fixture['maker_context'],
            [
                'idempotency_key' => 'ap-approved',
                'store_id' => $fixture['store']->id,
                'supplier_reference' => 'SUP-APPROVED',
                'supplier_name' => 'Approved Supplier',
                'entry_date' => '2027-01-16',
                'due_date' => '2027-02-16',
                'amount' => '60.000',
                'currency_code' => 'KWD',
                'payable_account_id' => $fixture['payable_account_id'],
                'offset_account_id' => $fixture['expense_account_id'],
            ],
        );
        $this->approveEntity(
            $approvals,
            $fixture,
            FinanceApprovalService::ACTION_AP_POST,
            'enterprise_ap_document',
            $ap->id,
            (string) $ap->amount,
            (string) $ap->currency_code,
        );
        $postedAp = app(AccountsPayableService::class)->post(
            $fixture['poster'],
            $fixture['poster_context'],
            $ap->id,
        );
        $this->assertSame('posted', $postedAp->status);
    }

    public function test_finance_approval_schema_is_additive_and_reversible(): void
    {
        $migration = require database_path(
            'migrations/2026_10_16_093000_create_enterprise_finance_approval_controls.php',
        );

        $migration->down();
        $this->assertFalse(Schema::hasTable('enterprise_finance_approval_delegations'));
        $this->assertFalse(Schema::hasTable('enterprise_finance_approval_limits'));
        $this->assertTrue(Schema::hasTable('enterprise_workflow_requests'));
        $this->assertTrue(Schema::hasTable('enterprise_finance_journals'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('enterprise_finance_approval_limits'));
        $this->assertTrue(Schema::hasTable('enterprise_finance_approval_delegations'));
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function approveEntity(
        FinanceApprovalService $approvals,
        array $fixture,
        string $action,
        string $entityType,
        int $entityId,
        string $amount,
        ?string $currency,
    ): void {
        $request = $approvals->requestApproval(
            $fixture['maker'],
            $fixture['maker_context'],
            $action,
            $entityType,
            $entityId,
            $amount,
            $currency,
        );
        $approvals->approve(
            $fixture['checker'],
            $fixture['checker_context'],
            $request->id,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $code): array
    {
        $store = $this->createStore($code);
        $maker = $this->userForStore(
            $store,
            ['finance.view', 'finance.manage'],
            $code.'-MAKER',
        );
        $checker = $this->userForStore(
            $store,
            ['finance.view', 'finance.approve'],
            $code.'-CHECKER',
        );
        $poster = $this->userForStore(
            $store,
            ['finance.view', 'finance.post'],
            $code.'-POSTER',
        );

        $makerContext = app(TenantContextResolver::class)->retail($maker['user'], $store->id);
        $checkerContext = app(TenantContextResolver::class)->retail($checker['user'], $store->id);
        $posterContext = app(TenantContextResolver::class)->retail($poster['user'], $store->id);
        $this->enableFinance($maker['user'], $makerContext);

        $finance = app(FinanceFoundationService::class);
        $cash = $finance->createAccount(
            $maker['user'],
            $makerContext,
            [
                'code' => '1100',
                'name' => 'Cash',
                'type' => 'asset',
                'normal_balance' => 'debit',
                'allow_posting' => true,
            ],
        );
        $receivable = $finance->createAccount(
            $maker['user'],
            $makerContext,
            [
                'code' => '1200',
                'name' => 'Accounts Receivable',
                'type' => 'asset',
                'normal_balance' => 'debit',
                'allow_posting' => true,
            ],
        );
        $payable = $finance->createAccount(
            $maker['user'],
            $makerContext,
            [
                'code' => '2100',
                'name' => 'Accounts Payable',
                'type' => 'liability',
                'normal_balance' => 'credit',
                'allow_posting' => true,
            ],
        );
        $equity = $finance->createAccount(
            $maker['user'],
            $makerContext,
            [
                'code' => '3000',
                'name' => 'Opening Equity',
                'type' => 'equity',
                'normal_balance' => 'credit',
                'allow_posting' => true,
            ],
        );
        $revenue = $finance->createAccount(
            $maker['user'],
            $makerContext,
            [
                'code' => '4000',
                'name' => 'Sales Revenue',
                'type' => 'revenue',
                'normal_balance' => 'credit',
                'allow_posting' => true,
            ],
        );
        $expense = $finance->createAccount(
            $maker['user'],
            $makerContext,
            [
                'code' => '5000',
                'name' => 'Purchases',
                'type' => 'expense',
                'normal_balance' => 'debit',
                'allow_posting' => true,
            ],
        );

        $year = $finance->createFiscalYear(
            $maker['user'],
            $makerContext,
            [
                'code' => 'FY2027',
                'name' => 'Fiscal Year 2027',
                'starts_on' => '2027-01-01',
                'ends_on' => '2027-12-31',
            ],
        );
        $period = $finance->createFiscalPeriod(
            $maker['user'],
            $makerContext,
            $year->id,
            [
                'sequence' => 1,
                'code' => 'P01',
                'name' => 'January 2027',
                'starts_on' => '2027-01-01',
                'ends_on' => '2027-01-31',
            ],
        );

        $customer = Customer::query()->create([
            'type' => 'b2b',
            'name' => $code.' Customer',
            'email' => strtolower($code).'@example.test',
        ]);

        return [
            'store' => $store,
            'customer' => $customer,
            'maker' => $maker['user'],
            'checker' => $checker['user'],
            'checker_role' => $checker['role'],
            'poster' => $poster['user'],
            'maker_context' => $makerContext,
            'checker_context' => $checkerContext,
            'poster_context' => $posterContext,
            'cash_account_id' => $cash->id,
            'receivable_account_id' => $receivable->id,
            'payable_account_id' => $payable->id,
            'equity_account_id' => $equity->id,
            'revenue_account_id' => $revenue->id,
            'expense_account_id' => $expense->id,
            'period_id' => $period->id,
        ];
    }

    private function enableFinance(User $actor, ResolvedTenantContext $context): void
    {
        $scope = $context->enterpriseScope;
        $this->assertNotNull($scope);

        $registry = app(ModuleRegistry::class);
        if (! $registry->isEnabled(FinanceFoundationService::MODULE)) {
            $lifecycle = app(ModuleLifecycleManager::class);
            $lifecycle->transition(
                FinanceFoundationService::MODULE,
                ModuleState::ENABLING,
                $actor,
            );
            $lifecycle->transition(
                FinanceFoundationService::MODULE,
                ModuleState::ENABLED,
                $actor,
            );
        }

        app(ModuleEntitlementManager::class)->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(
                EntitlementScope::LEGAL_ENTITY,
                $scope->legalEntityId,
            ),
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
                'finance.ar.read',
                'finance.ar.manage',
                'finance.ar.post',
                'finance.ap.read',
                'finance.ap.manage',
                'finance.ap.post',
                'finance.approvals.manage',
                'finance.approvals.approve',
            ],
            $actor,
        );
    }

    private function createStore(string $code): Store
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $store = Store::query()->create([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    /**
     * @param list<string> $permissions
     * @return array{user:User,role:Role}
     */
    private function userForStore(
        Store $store,
        array $permissions,
        string $code,
    ): array {
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

        return ['user' => $user, 'role' => $role];
    }
}
