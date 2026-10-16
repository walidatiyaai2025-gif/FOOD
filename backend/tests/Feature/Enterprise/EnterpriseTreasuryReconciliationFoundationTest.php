<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Enterprise\Treasury\TreasuryIntegrationEventPublisher;
use App\Enterprise\Treasury\TreasuryPaymentService;
use App\Enterprise\Treasury\TreasuryReceiptService;
use App\Enterprise\Treasury\TreasuryReconciliationService;
use App\Models\EnterpriseBankAccount;
use App\Models\EnterpriseCashbox;
use App\Models\EnterpriseTreasuryPayment;
use App\Models\EnterpriseTreasuryReceipt;
use App\Models\EnterpriseTreasuryReconciliation;
use App\Models\EnterpriseTreasuryReconciliationLine;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\ResolvedTenantContext;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class EnterpriseTreasuryReconciliationFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_bank_reconciliation_matches_only_posted_canonical_treasury_activity(): void
    {
        [$store, $maker, $makerContext] = $this->fixture('REC-LIFE', 'rec-maker@example.test');
        $approver = $this->financeActor('rec-approver@example.test', $store);
        $approverContext = $this->context($approver, $store);
        $this->enableTreasury($maker, $makerContext);
        $this->entitleTreasury($approver, $approverContext);

        $bank = $this->bankAccount($store);
        $receipt = $this->postedReceipt(
            $maker,
            $makerContext,
            $approver,
            $approverContext,
            EnterpriseTreasuryReceipt::TARGET_BANK_ACCOUNT,
            $bank->id,
            '125.000',
            '2026-10-15',
            'BANK-R-001',
        );
        $payment = $this->postedPayment(
            $maker,
            $makerContext,
            $approver,
            $approverContext,
            EnterpriseTreasuryPayment::TARGET_BANK_ACCOUNT,
            $bank->id,
            '40.000',
            '2026-10-16',
            'BANK-P-001',
        );

        $service = app(TreasuryReconciliationService::class);
        $draft = $service->createReconciliationDraft($maker, $makerContext, [
            'idempotency_key' => 'reconciliation-001',
            'bank_account_id' => $bank->id,
            'period_from' => '2026-10-01',
            'period_to' => '2026-10-16',
            'statement_receipts_total' => '125.000',
            'statement_payments_total' => '40.000',
        ]);
        $retry = $service->createReconciliationDraft($maker, $makerContext, [
            'idempotency_key' => 'reconciliation-001',
            'bank_account_id' => $bank->id,
            'period_from' => '2026-10-01',
            'period_to' => '2026-10-16',
            'statement_receipts_total' => '125.000',
            'statement_payments_total' => '40.000',
        ]);

        $this->assertSame($draft->id, $retry->id);
        $this->assertDatabaseCount('enterprise_treasury_reconciliations', 1);

        $receiptLine = $service->addMatch(
            $maker,
            $makerContext,
            $draft->id,
            EnterpriseTreasuryReconciliationLine::TYPE_RECEIPT,
            $receipt->id,
        );
        $receiptRetry = $service->addMatch(
            $maker,
            $makerContext,
            $draft->id,
            EnterpriseTreasuryReconciliationLine::TYPE_RECEIPT,
            $receipt->id,
        );
        $paymentLine = $service->addMatch(
            $maker,
            $makerContext,
            $draft->id,
            EnterpriseTreasuryReconciliationLine::TYPE_PAYMENT,
            $payment->id,
        );

        $this->assertSame($receiptLine->id, $receiptRetry->id);
        $this->assertSame('125.000', $receiptLine->amount);
        $this->assertSame('40.000', $paymentLine->amount);
        $this->assertDatabaseCount('enterprise_treasury_reconciliation_lines', 2);

        try {
            $service->closeReconciliation($maker, $makerContext, $draft->id);
            $this->fail('Reconciliation maker must not close the same reconciliation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('approver', $exception->errors());
        }

        $closed = $service->closeReconciliation($approver, $approverContext, $draft->id);
        $closedRetry = $service->closeReconciliation($approver, $approverContext, $draft->id);

        $this->assertSame(EnterpriseTreasuryReconciliation::STATUS_CLOSED, $closed->status);
        $this->assertSame($closed->id, $closedRetry->id);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.treasury.reconciliation_closed',
            'auditable_id' => $closed->id,
        ]);
        $this->assertSame(
            1,
            DB::table('enterprise_outbox_messages')
                ->where('event_type', TreasuryIntegrationEventPublisher::RECONCILIATION_CLOSED)
                ->where('event_version', 1)
                ->count(),
        );
        $this->assertDatabaseHas('enterprise_treasury_integration_events', [
            'source_type' => 'reconciliation',
            'source_id' => $closed->id,
            'event_type' => TreasuryIntegrationEventPublisher::RECONCILIATION_CLOSED,
            'event_version' => 1,
            'currency_code' => 'KWD',
        ]);

        try {
            $service->addMatch(
                $maker,
                $makerContext,
                $closed->id,
                EnterpriseTreasuryReconciliationLine::TYPE_RECEIPT,
                $receipt->id,
            );
            $this->fail('Closed reconciliation must reject new matches.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }
    }

    public function test_reconciliation_rejects_mismatched_statement_totals_and_cross_store_access(): void
    {
        [$store, $maker, $makerContext] = $this->fixture('REC-MISMATCH', 'mismatch-maker@example.test');
        $approver = $this->financeActor('mismatch-approver@example.test', $store);
        $approverContext = $this->context($approver, $store);
        $this->enableTreasury($maker, $makerContext);
        $this->entitleTreasury($approver, $approverContext);

        $bank = $this->bankAccount($store);
        $receipt = $this->postedReceipt(
            $maker,
            $makerContext,
            $approver,
            $approverContext,
            EnterpriseTreasuryReceipt::TARGET_BANK_ACCOUNT,
            $bank->id,
            '10.000',
            '2026-10-16',
            'BANK-R-MISMATCH',
        );

        $service = app(TreasuryReconciliationService::class);
        $draft = $service->createReconciliationDraft($maker, $makerContext, [
            'idempotency_key' => 'reconciliation-mismatch',
            'bank_account_id' => $bank->id,
            'period_from' => '2026-10-16',
            'period_to' => '2026-10-16',
            'statement_receipts_total' => '11.000',
            'statement_payments_total' => '0.000',
        ]);
        $service->addMatch(
            $maker,
            $makerContext,
            $draft->id,
            EnterpriseTreasuryReconciliationLine::TYPE_RECEIPT,
            $receipt->id,
        );

        try {
            $service->closeReconciliation($approver, $approverContext, $draft->id);
            $this->fail('Mismatched statement totals must prevent close.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('statement_totals', $exception->errors());
        }

        [$otherStore, $otherActor, $otherContext] = $this->fixture(
            'REC-OTHER',
            'other-store@example.test',
        );
        $this->enableTreasury($otherActor, $otherContext);
        $this->assertNotSame($store->id, $otherStore->id);

        $this->expectException(ModelNotFoundException::class);
        $service->closeReconciliation($otherActor, $otherContext, $draft->id);
    }

    public function test_daily_cash_close_recomputes_posted_activity_and_requires_balanced_declared_cash(): void
    {
        [$store, $maker, $makerContext] = $this->fixture('CASH-CLOSE', 'cash-maker@example.test');
        $approver = $this->financeActor('cash-approver@example.test', $store);
        $approverContext = $this->context($approver, $store);
        $this->enableTreasury($maker, $makerContext);
        $this->entitleTreasury($approver, $approverContext);

        $cashbox = $this->cashbox($store);
        $this->postedReceipt(
            $maker,
            $makerContext,
            $approver,
            $approverContext,
            EnterpriseTreasuryReceipt::TARGET_CASHBOX,
            $cashbox->id,
            '100.000',
            '2026-10-16',
            'CASH-R-001',
        );
        $this->postedPayment(
            $maker,
            $makerContext,
            $approver,
            $approverContext,
            EnterpriseTreasuryPayment::TARGET_CASHBOX,
            $cashbox->id,
            '30.000',
            '2026-10-16',
            'CASH-P-001',
        );

        $service = app(TreasuryReconciliationService::class);
        $draft = $service->createCashClosingDraft($maker, $makerContext, [
            'idempotency_key' => 'cash-close-001',
            'cashbox_id' => $cashbox->id,
            'business_date' => '2026-10-16',
            'opening_amount' => '50.000',
            'declared_closing_amount' => '120.000',
        ]);
        $retry = $service->createCashClosingDraft($maker, $makerContext, [
            'idempotency_key' => 'cash-close-001',
            'cashbox_id' => $cashbox->id,
            'business_date' => '2026-10-16',
            'opening_amount' => '50.000',
            'declared_closing_amount' => '120.000',
        ]);

        $this->assertSame($draft->id, $retry->id);
        $this->assertSame('100.000', $draft->receipt_total);
        $this->assertSame('30.000', $draft->payment_total);
        $this->assertSame('120.000', $draft->expected_closing_amount);

        try {
            $service->closeCashClosing($maker, $makerContext, $draft->id);
            $this->fail('Cash-closing maker must not close the same cashbox day.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('approver', $exception->errors());
        }

        $closed = $service->closeCashClosing($approver, $approverContext, $draft->id);
        $closedRetry = $service->closeCashClosing($approver, $approverContext, $draft->id);
        $this->assertSame('closed', $closed->status);
        $this->assertSame($closed->id, $closedRetry->id);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.treasury.cash_closing_closed',
            'auditable_id' => $closed->id,
        ]);
        $this->assertSame(
            1,
            DB::table('enterprise_outbox_messages')
                ->where('event_type', TreasuryIntegrationEventPublisher::CASH_CLOSING_CLOSED)
                ->where('event_version', 1)
                ->count(),
        );
        $this->assertDatabaseHas('enterprise_treasury_integration_events', [
            'source_type' => 'cash_closing',
            'source_id' => $closed->id,
            'event_type' => TreasuryIntegrationEventPublisher::CASH_CLOSING_CLOSED,
            'event_version' => 1,
            'currency_code' => 'KWD',
        ]);

        $otherCashbox = EnterpriseCashbox::query()->create([
            'company_id' => $cashbox->company_id,
            'store_id' => $store->id,
            'code' => 'CASH-BAD-'.$store->id,
            'name' => 'Unbalanced Cashbox',
            'currency_code' => 'KWD',
            'operator_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'approval_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'is_active' => true,
        ]);
        $bad = $service->createCashClosingDraft($maker, $makerContext, [
            'idempotency_key' => 'cash-close-bad',
            'cashbox_id' => $otherCashbox->id,
            'business_date' => '2026-10-16',
            'opening_amount' => '10.000',
            'declared_closing_amount' => '11.000',
        ]);

        try {
            $service->closeCashClosing($approver, $approverContext, $bad->id);
            $this->fail('Unbalanced declared cash must not close.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('declared_closing_amount', $exception->errors());
        }
    }

    public function test_reconciliation_bounds_double_assignment_and_same_day_cash_close_retry(): void
    {
        [$store, $maker, $makerContext] = $this->fixture('REC-BOUNDS', 'bounds-maker@example.test');
        $approver = $this->financeActor('bounds-approver@example.test', $store);
        $approverContext = $this->context($approver, $store);
        $this->enableTreasury($maker, $makerContext);
        $this->entitleTreasury($approver, $approverContext);

        $bank = $this->bankAccount($store);
        $receipt = $this->postedReceipt(
            $maker,
            $makerContext,
            $approver,
            $approverContext,
            EnterpriseTreasuryReceipt::TARGET_BANK_ACCOUNT,
            $bank->id,
            '25.000',
            '2026-10-16',
            'BANK-R-DOUBLE',
        );

        $service = app(TreasuryReconciliationService::class);
        $first = $service->createReconciliationDraft($maker, $makerContext, [
            'idempotency_key' => 'reconciliation-double-1',
            'bank_account_id' => $bank->id,
            'period_from' => '2026-10-16',
            'period_to' => '2026-10-16',
            'statement_receipts_total' => '25.000',
            'statement_payments_total' => '0.000',
        ]);
        $second = $service->createReconciliationDraft($maker, $makerContext, [
            'idempotency_key' => 'reconciliation-double-2',
            'bank_account_id' => $bank->id,
            'period_from' => '2026-10-16',
            'period_to' => '2026-10-16',
            'statement_receipts_total' => '25.000',
            'statement_payments_total' => '0.000',
        ]);
        $service->addMatch(
            $maker,
            $makerContext,
            $first->id,
            EnterpriseTreasuryReconciliationLine::TYPE_RECEIPT,
            $receipt->id,
        );

        try {
            $service->addMatch(
                $maker,
                $makerContext,
                $second->id,
                EnterpriseTreasuryReconciliationLine::TYPE_RECEIPT,
                $receipt->id,
            );
            $this->fail('A Treasury transaction must not be assigned to two reconciliations.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('transaction_id', $exception->errors());
        }

        try {
            $service->paginateReconciliations($maker, $makerContext, [
                'date_from' => '2024-01-01',
                'date_to' => '2026-10-16',
            ]);
            $this->fail('Reconciliation reads must enforce a bounded date window.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('date_to', $exception->errors());
        }

        try {
            $service->paginateCashClosings($maker, $makerContext, [
                'date_from' => '2024-01-01',
                'date_to' => '2026-10-16',
            ]);
            $this->fail('Cash-closing reads must enforce a bounded date window.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('date_to', $exception->errors());
        }

        $cashbox = $this->cashbox($store);
        $cashClose = $service->createCashClosingDraft($maker, $makerContext, [
            'idempotency_key' => 'cash-close-same-day-1',
            'cashbox_id' => $cashbox->id,
            'business_date' => '2026-10-16',
            'opening_amount' => '10.000',
            'declared_closing_amount' => '10.000',
        ]);
        $sameDayRetry = $service->createCashClosingDraft($maker, $makerContext, [
            'idempotency_key' => 'cash-close-same-day-2',
            'cashbox_id' => $cashbox->id,
            'business_date' => '2026-10-16',
            'opening_amount' => '10.000',
            'declared_closing_amount' => '10.000',
        ]);

        $this->assertSame($cashClose->id, $sameDayRetry->id);
        $this->assertDatabaseCount('enterprise_treasury_cash_closings', 1);
    }

    public function test_disabled_treasury_performs_no_reconciliation_table_query_work(): void
    {
        [$store, $actor, $context] = $this->fixture('REC-OFF', 'rec-off@example.test');
        $queries = [];
        DB::listen(static function ($event) use (&$queries): void {
            $queries[] = strtolower((string) $event->sql);
        });

        try {
            app(TreasuryReconciliationService::class)->paginateReconciliations($actor, $context);
            $this->fail('Disabled Treasury must deny reconciliation reads.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertFalse(collect($queries)->contains(
            static fn (string $sql): bool => str_contains($sql, 'enterprise_treasury_reconciliation')
                || str_contains($sql, 'enterprise_treasury_cash_closings'),
        ));
        $this->assertTrue($store->exists);
    }

    public function test_reconciliation_schema_is_additive_and_reversible(): void
    {
        $migration = require database_path(
            'migrations/2026_10_16_091500_create_enterprise_treasury_reconciliation_foundation.php',
        );

        $migration->down();
        $this->assertFalse(Schema::hasTable('enterprise_treasury_cash_closings'));
        $this->assertFalse(Schema::hasTable('enterprise_treasury_reconciliation_lines'));
        $this->assertFalse(Schema::hasTable('enterprise_treasury_reconciliations'));
        $this->assertTrue(Schema::hasTable('enterprise_treasury_receipts'));
        $this->assertTrue(Schema::hasTable('enterprise_treasury_payments'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('enterprise_treasury_reconciliations'));
        $this->assertTrue(Schema::hasTable('enterprise_treasury_reconciliation_lines'));
        $this->assertTrue(Schema::hasTable('enterprise_treasury_cash_closings'));
    }

    /** @return array{Store,User,ResolvedTenantContext} */
    private function fixture(string $code, string $email): array
    {
        $store = $this->createRetailStore($code);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);
        $actor = $this->financeActor($email, $store);

        return [$store, $actor, $this->context($actor, $store)];
    }

    private function createRetailStore(string $code): Store
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        return Store::query()->create([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
    }

    private function financeActor(string $email, Store $store): User
    {
        $user = User::factory()->create(['email' => $email, 'is_active' => true]);
        $role = Role::query()->where('code', 'RETAIL_FINANCE')->firstOrFail();
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function context(User $actor, Store $store): ResolvedTenantContext
    {
        return app(TenantContextResolver::class)->retail($actor, $store->id);
    }

    private function enableTreasury(User $actor, ResolvedTenantContext $context): void
    {
        $registry = app(ModuleRegistry::class);
        if (! $registry->isEnabled(TreasuryReconciliationService::MODULE)) {
            $lifecycle = app(ModuleLifecycleManager::class);
            $lifecycle->transition(TreasuryReconciliationService::MODULE, ModuleState::ENABLING, $actor);
            $lifecycle->transition(TreasuryReconciliationService::MODULE, ModuleState::ENABLED, $actor);
        }
        $this->entitleTreasury($actor, $context);
    }

    private function entitleTreasury(User $actor, ResolvedTenantContext $context): void
    {
        $scope = $context->enterpriseScope;
        $this->assertNotNull($scope);
        app(ModuleEntitlementManager::class)->set(
            TreasuryReconciliationService::MODULE,
            new EntitlementScope(EntitlementScope::STORE, $scope->storeId),
            true,
            [
                'treasury.receipts.read',
                'treasury.receipts.manage',
                'treasury.receipts.approve',
                'treasury.payments.read',
                'treasury.payments.manage',
                'treasury.payments.approve',
                'treasury.reconciliation.read',
                'treasury.reconciliation.manage',
                'treasury.reconciliation.approve',
                'treasury.cash_closing.read',
                'treasury.cash_closing.manage',
                'treasury.cash_closing.approve',
            ],
            $actor,
        );
    }

    private function bankAccount(Store $store): EnterpriseBankAccount
    {
        $scope = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertNotNull($scope);

        return EnterpriseBankAccount::query()->create([
            'company_id' => $scope['company_id'],
            'store_id' => $store->id,
            'code' => 'REC-BANK-'.$store->id,
            'name' => 'Reconciliation Bank',
            'bank_name' => 'Test Bank',
            'account_name' => 'Operating',
            'account_reference' => 'KW00TEST'.$store->id,
            'account_reference_last4' => str_pad((string) ($store->id % 10000), 4, '0', STR_PAD_LEFT),
            'currency_code' => 'KWD',
            'operator_visibility' => EnterpriseBankAccount::VISIBILITY_STORE,
            'approval_visibility' => EnterpriseBankAccount::VISIBILITY_STORE,
            'is_active' => true,
        ]);
    }

    private function cashbox(Store $store): EnterpriseCashbox
    {
        $scope = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertNotNull($scope);

        return EnterpriseCashbox::query()->create([
            'company_id' => $scope['company_id'],
            'store_id' => $store->id,
            'code' => 'REC-CASH-'.$store->id,
            'name' => 'Reconciliation Cashbox',
            'currency_code' => 'KWD',
            'operator_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'approval_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'is_active' => true,
        ]);
    }

    private function postedReceipt(
        User $maker,
        ResolvedTenantContext $makerContext,
        User $approver,
        ResolvedTenantContext $approverContext,
        string $targetType,
        int $targetId,
        string $amount,
        string $date,
        string $reference,
    ): EnterpriseTreasuryReceipt {
        $service = app(TreasuryReceiptService::class);
        $draft = $service->createDraft($maker, $makerContext, [
            'idempotency_key' => 'receipt-'.strtolower($reference),
            'target_type' => $targetType,
            'target_id' => $targetId,
            'amount' => $amount,
            'value_date' => $date,
            'reference' => $reference,
        ]);

        return $service->post($approver, $approverContext, $draft->id);
    }

    private function postedPayment(
        User $maker,
        ResolvedTenantContext $makerContext,
        User $approver,
        ResolvedTenantContext $approverContext,
        string $targetType,
        int $targetId,
        string $amount,
        string $date,
        string $reference,
    ): EnterpriseTreasuryPayment {
        $service = app(TreasuryPaymentService::class);
        $draft = $service->createDraft($maker, $makerContext, [
            'idempotency_key' => 'payment-'.strtolower($reference),
            'target_type' => $targetType,
            'target_id' => $targetId,
            'amount' => $amount,
            'value_date' => $date,
            'reference' => $reference,
            'beneficiary' => 'Test Beneficiary',
        ]);

        return $service->post($approver, $approverContext, $draft->id);
    }
}
