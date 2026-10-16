<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Enterprise\Treasury\TreasuryChequeService;
use App\Enterprise\Treasury\TreasuryPaymentService;
use App\Enterprise\Treasury\TreasuryReceiptService;
use App\Models\EnterpriseBankAccount;
use App\Models\EnterpriseTreasuryCheque;
use App\Models\EnterpriseTreasuryChequeTransition;
use App\Models\EnterpriseTreasuryPayment;
use App\Models\EnterpriseTreasuryReceipt;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\ResolvedTenantContext;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class EnterpriseTreasuryChequeFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-16 12:00:00');
        $this->seed(CoreReferenceSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_received_cheque_lifecycle_is_idempotent_maker_checker_and_audited(): void
    {
        [$store, $maker, $makerContext] = $this->fixture('CHQ-RECV', 'chq-maker@example.test');
        $checker = $this->financeActor('chq-checker@example.test', $store);
        $checkerContext = $this->context($checker, $store);
        $this->enableTreasury($maker, $makerContext);
        $this->entitleTreasury($checker, $checkerContext);

        $bank = $this->bankAccount($store, 'CHQ-RECV-BANK');
        $receipt = $this->postedReceipt(
            $maker,
            $makerContext,
            $checker,
            $checkerContext,
            $bank,
            '125.750',
            '2026-10-16',
            'CHQ-RECV-R-001',
        );

        $payload = [
            'idempotency_key' => 'cheque-received-001',
            'direction' => EnterpriseTreasuryCheque::DIRECTION_RECEIVED,
            'treasury_reference_type' => EnterpriseTreasuryCheque::REFERENCE_RECEIPT,
            'treasury_reference_id' => $receipt->id,
            'bank_account_id' => $bank->id,
            'instrument_number' => 'RCV-0001',
            'issue_date' => '2026-10-16',
            'due_date' => '2026-10-16',
            'payee_or_drawer' => 'Customer A',
        ];

        $service = app(TreasuryChequeService::class);
        $cheque = $service->createInstrument($maker, $makerContext, $payload);
        $retry = $service->createInstrument($maker, $makerContext, $payload);

        $this->assertSame($cheque->id, $retry->id);
        $this->assertSame(EnterpriseTreasuryCheque::STATUS_RECEIVED, $cheque->status);
        $this->assertSame('125.750', $cheque->amount);
        $this->assertSame('KWD', $cheque->currency_code);
        $this->assertDatabaseCount('enterprise_treasury_cheques', 1);

        try {
            $service->transition($maker, $makerContext, $cheque->id, [
                'idempotency_key' => 'cheque-deposit-maker',
                'to_status' => EnterpriseTreasuryCheque::STATUS_DEPOSITED,
            ]);
            $this->fail('Cheque maker must not approve the same instrument transition.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('approver', $exception->errors());
        }

        $deposited = $service->transition($checker, $checkerContext, $cheque->id, [
            'idempotency_key' => 'cheque-deposit-001',
            'to_status' => EnterpriseTreasuryCheque::STATUS_DEPOSITED,
            'reason' => 'Deposited for clearing',
        ]);
        $depositRetry = $service->transition($checker, $checkerContext, $cheque->id, [
            'idempotency_key' => 'cheque-deposit-001',
            'to_status' => EnterpriseTreasuryCheque::STATUS_DEPOSITED,
            'reason' => 'Deposited for clearing',
        ]);

        $this->assertSame(EnterpriseTreasuryCheque::STATUS_DEPOSITED, $deposited->status);
        $this->assertSame($deposited->id, $depositRetry->id);
        $this->assertDatabaseCount('enterprise_treasury_cheque_transitions', 1);

        try {
            $service->transition($checker, $checkerContext, $cheque->id, [
                'idempotency_key' => 'cheque-deposit-001',
                'to_status' => EnterpriseTreasuryCheque::STATUS_CLEARED,
                'reason' => 'Different request',
            ]);
            $this->fail('Transition idempotency key must not bind to a different request.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('idempotency_key', $exception->errors());
        }

        $cleared = $service->transition($checker, $checkerContext, $cheque->id, [
            'idempotency_key' => 'cheque-clear-001',
            'to_status' => EnterpriseTreasuryCheque::STATUS_CLEARED,
            'reason' => 'Bank clearing confirmed internally',
        ]);

        $this->assertSame(EnterpriseTreasuryCheque::STATUS_CLEARED, $cleared->status);
        $this->assertDatabaseCount('enterprise_treasury_cheque_transitions', 2);
        $transition = EnterpriseTreasuryChequeTransition::query()
            ->where('to_status', EnterpriseTreasuryCheque::STATUS_CLEARED)
            ->firstOrFail();
        $this->assertNotNull($transition->workflow_request_id);
        $this->assertSame('125.750', $transition->metadata['amount']);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.treasury.cheque_transitioned',
            'auditable_id' => $cheque->id,
        ]);

        try {
            $service->transition($checker, $checkerContext, $cheque->id, [
                'idempotency_key' => 'cheque-bounce-after-clear',
                'to_status' => EnterpriseTreasuryCheque::STATUS_BOUNCED,
            ]);
            $this->fail('Cleared cheque must reject later lifecycle transitions.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('to_status', $exception->errors());
        }
    }

    public function test_issued_cheque_enforces_source_account_scope_maturity_and_unique_settlement_reference(): void
    {
        [$store, $maker, $makerContext] = $this->fixture('CHQ-ISS', 'iss-maker@example.test');
        $checker = $this->financeActor('iss-checker@example.test', $store);
        $checkerContext = $this->context($checker, $store);
        $this->enableTreasury($maker, $makerContext);
        $this->entitleTreasury($checker, $checkerContext);

        $bank = $this->bankAccount($store, 'CHQ-ISS-BANK');
        $otherBank = $this->bankAccount($store, 'CHQ-OTHER-BANK');
        $payment = $this->postedPayment(
            $maker,
            $makerContext,
            $checker,
            $checkerContext,
            $bank,
            '80.000',
            '2026-10-16',
            'CHQ-ISS-P-001',
        );

        $service = app(TreasuryChequeService::class);

        try {
            $service->createInstrument($maker, $makerContext, [
                'idempotency_key' => 'cheque-wrong-account',
                'direction' => EnterpriseTreasuryCheque::DIRECTION_ISSUED,
                'treasury_reference_type' => EnterpriseTreasuryCheque::REFERENCE_PAYMENT,
                'treasury_reference_id' => $payment->id,
                'bank_account_id' => $otherBank->id,
                'instrument_number' => 'ISS-WRONG',
                'issue_date' => '2026-10-16',
                'due_date' => '2026-10-20',
            ]);
            $this->fail('Cheque bank account must match the canonical Treasury transaction target.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('treasury_reference_id', $exception->errors());
        }

        $cheque = $service->createInstrument($maker, $makerContext, [
            'idempotency_key' => 'cheque-issued-001',
            'direction' => EnterpriseTreasuryCheque::DIRECTION_ISSUED,
            'treasury_reference_type' => EnterpriseTreasuryCheque::REFERENCE_PAYMENT,
            'treasury_reference_id' => $payment->id,
            'bank_account_id' => $bank->id,
            'instrument_number' => 'ISS-0001',
            'issue_date' => '2026-10-16',
            'due_date' => '2026-10-20',
            'payee_or_drawer' => 'Supplier A',
        ]);

        try {
            $service->transition($checker, $checkerContext, $cheque->id, [
                'idempotency_key' => 'cheque-present-too-early',
                'to_status' => EnterpriseTreasuryCheque::STATUS_PRESENTED,
            ]);
            $this->fail('PDC must not be presented before its due date.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('due_date', $exception->errors());
        }

        try {
            $service->createInstrument($maker, $makerContext, [
                'idempotency_key' => 'cheque-issued-duplicate-source',
                'direction' => EnterpriseTreasuryCheque::DIRECTION_ISSUED,
                'treasury_reference_type' => EnterpriseTreasuryCheque::REFERENCE_PAYMENT,
                'treasury_reference_id' => $payment->id,
                'bank_account_id' => $bank->id,
                'instrument_number' => 'ISS-0002',
                'issue_date' => '2026-10-16',
                'due_date' => '2026-10-20',
            ]);
            $this->fail('A canonical Treasury transaction must not create duplicate cheque settlement state.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('treasury_reference_id', $exception->errors());
        }

        [$otherStore, $otherActor, $otherContext] = $this->fixture(
            'CHQ-OTHER',
            'iss-other@example.test',
        );
        $this->entitleTreasury($otherActor, $otherContext);
        $this->assertNotSame($store->id, $otherStore->id);

        $this->expectException(ModelNotFoundException::class);
        $service->transition($otherActor, $otherContext, $cheque->id, [
            'idempotency_key' => 'cross-store-transition',
            'to_status' => EnterpriseTreasuryCheque::STATUS_CANCELLED,
        ]);
    }

    public function test_due_and_overdue_queries_are_bounded_and_disabled_module_does_no_cheque_work(): void
    {
        [$store, $maker, $makerContext] = $this->fixture('CHQ-DUE', 'due-maker@example.test');
        $checker = $this->financeActor('due-checker@example.test', $store);
        $checkerContext = $this->context($checker, $store);
        $this->enableTreasury($maker, $makerContext);
        $this->entitleTreasury($checker, $checkerContext);
        $bank = $this->bankAccount($store, 'CHQ-DUE-BANK');
        $receipt = $this->postedReceipt(
            $maker,
            $makerContext,
            $checker,
            $checkerContext,
            $bank,
            '15.000',
            '2026-10-14',
            'CHQ-DUE-R-001',
        );

        $service = app(TreasuryChequeService::class);
        $service->createInstrument($maker, $makerContext, [
            'idempotency_key' => 'cheque-overdue-001',
            'direction' => EnterpriseTreasuryCheque::DIRECTION_RECEIVED,
            'treasury_reference_type' => EnterpriseTreasuryCheque::REFERENCE_RECEIPT,
            'treasury_reference_id' => $receipt->id,
            'bank_account_id' => $bank->id,
            'instrument_number' => 'OVERDUE-0001',
            'issue_date' => '2026-10-14',
            'due_date' => '2026-10-15',
        ]);

        $overdue = $service->paginateInstruments($maker, $makerContext, ['overdue' => true]);
        $this->assertSame(1, $overdue->total());

        try {
            $service->paginateInstruments($maker, $makerContext, [
                'due_date_from' => '2025-01-01',
                'due_date_to' => '2026-10-16',
            ]);
            $this->fail('Cheque/PDC reads must reject unbounded due-date ranges.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('due_date_to', $exception->errors());
        }

        [$offStore, $offActor, $offContext] = $this->fixture('CHQ-OFF', 'off-cheque@example.test');
        $this->assertTrue($offStore->exists);
        app(ModuleLifecycleManager::class)->transition(
            TreasuryChequeService::MODULE,
            ModuleState::DISABLING,
            $maker,
        );
        app(ModuleLifecycleManager::class)->transition(
            TreasuryChequeService::MODULE,
            ModuleState::DISABLED,
            $maker,
        );

        $queries = [];
        DB::listen(static function ($event) use (&$queries): void {
            $queries[] = strtolower((string) $event->sql);
        });

        try {
            $service->paginateInstruments($offActor, $offContext);
            $this->fail('Disabled Treasury must deny cheque/PDC reads.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertFalse(collect($queries)->contains(
            static fn (string $sql): bool => str_contains($sql, 'enterprise_treasury_cheques'),
        ));
    }

    public function test_cheque_schema_is_additive_and_reversible(): void
    {
        $migration = require database_path(
            'migrations/2026_10_16_100500_create_enterprise_treasury_cheque_pdc_foundation.php',
        );

        $migration->down();
        $this->assertFalse(Schema::hasTable('enterprise_treasury_cheque_transitions'));
        $this->assertFalse(Schema::hasTable('enterprise_treasury_cheques'));
        $this->assertTrue(Schema::hasTable('enterprise_treasury_payments'));
        $this->assertTrue(Schema::hasTable('enterprise_treasury_receipts'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('enterprise_treasury_cheques'));
        $this->assertTrue(Schema::hasTable('enterprise_treasury_cheque_transitions'));
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
        if (! $registry->isEnabled(TreasuryChequeService::MODULE)) {
            $lifecycle = app(ModuleLifecycleManager::class);
            $lifecycle->transition(TreasuryChequeService::MODULE, ModuleState::ENABLING, $actor);
            $lifecycle->transition(TreasuryChequeService::MODULE, ModuleState::ENABLED, $actor);
        }

        $this->entitleTreasury($actor, $context);
    }

    private function entitleTreasury(User $actor, ResolvedTenantContext $context): void
    {
        $scope = $context->enterpriseScope;
        $this->assertNotNull($scope);
        app(ModuleEntitlementManager::class)->set(
            TreasuryChequeService::MODULE,
            new EntitlementScope(EntitlementScope::STORE, $scope->storeId),
            true,
            [
                'treasury.receipts.read',
                'treasury.receipts.manage',
                'treasury.receipts.approve',
                'treasury.payments.read',
                'treasury.payments.manage',
                'treasury.payments.approve',
                'treasury.cheques.read',
                'treasury.cheques.manage',
                'treasury.cheques.approve',
            ],
            $actor,
        );
    }

    private function bankAccount(Store $store, string $code): EnterpriseBankAccount
    {
        $scope = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertNotNull($scope);

        return EnterpriseBankAccount::query()->create([
            'company_id' => $scope['company_id'],
            'store_id' => $store->id,
            'code' => $code.'-'.$store->id,
            'name' => $code,
            'bank_name' => 'Test Bank',
            'account_name' => 'Operating',
            'account_reference' => 'KW00'.$code.$store->id,
            'account_reference_last4' => str_pad((string) ($store->id % 10000), 4, '0', STR_PAD_LEFT),
            'currency_code' => 'KWD',
            'operator_visibility' => EnterpriseBankAccount::VISIBILITY_STORE,
            'approval_visibility' => EnterpriseBankAccount::VISIBILITY_STORE,
            'is_active' => true,
        ]);
    }

    private function postedReceipt(
        User $maker,
        ResolvedTenantContext $makerContext,
        User $checker,
        ResolvedTenantContext $checkerContext,
        EnterpriseBankAccount $bank,
        string $amount,
        string $date,
        string $reference,
    ): EnterpriseTreasuryReceipt {
        $service = app(TreasuryReceiptService::class);
        $draft = $service->createDraft($maker, $makerContext, [
            'idempotency_key' => 'receipt-'.strtolower($reference),
            'target_type' => EnterpriseTreasuryReceipt::TARGET_BANK_ACCOUNT,
            'target_id' => $bank->id,
            'amount' => $amount,
            'value_date' => $date,
            'reference' => $reference,
        ]);

        return $service->post($checker, $checkerContext, $draft->id);
    }

    private function postedPayment(
        User $maker,
        ResolvedTenantContext $makerContext,
        User $checker,
        ResolvedTenantContext $checkerContext,
        EnterpriseBankAccount $bank,
        string $amount,
        string $date,
        string $reference,
    ): EnterpriseTreasuryPayment {
        $service = app(TreasuryPaymentService::class);
        $draft = $service->createDraft($maker, $makerContext, [
            'idempotency_key' => 'payment-'.strtolower($reference),
            'target_type' => EnterpriseTreasuryPayment::TARGET_BANK_ACCOUNT,
            'target_id' => $bank->id,
            'amount' => $amount,
            'value_date' => $date,
            'reference' => $reference,
            'beneficiary' => 'Supplier A',
        ]);

        return $service->post($checker, $checkerContext, $draft->id);
    }
}
