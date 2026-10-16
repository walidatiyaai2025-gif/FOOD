<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Events\VersionedDomainEvent;
use App\Enterprise\Finance\FinanceFoundationService;
use App\Enterprise\Finance\FinanceSourcePostingConsumer;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Enterprise\Outbox\OutboxDispatcher;
use App\Enterprise\Outbox\TransactionalOutbox;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\ResolvedTenantContext;
use App\Support\TenantContextResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

final class EnterpriseFinanceSourcePostingConsumerTest extends TestCase
{
    use RefreshDatabase;

    public function test_available_procurement_and_treasury_contracts_post_once_through_canonical_gl(): void
    {
        $fixture = $this->fixture('FIN-SOURCE-POST');
        $this->configurePosting($fixture);

        $procurement = $this->procurementEvent($fixture);
        $treasury = $this->treasuryReceiptEvent($fixture);

        $outbox = app(TransactionalOutbox::class);
        $firstProcurementMessage = $outbox->enqueue($procurement);
        $retryProcurementMessage = $outbox->enqueue($procurement);
        $outbox->enqueue($treasury);

        $this->assertSame($firstProcurementMessage->id, $retryProcurementMessage->id);
        $this->assertSame(2, app(OutboxDispatcher::class)->dispatchDue(10));
        $this->assertSame(0, app(OutboxDispatcher::class)->dispatchDue(10));

        $this->assertDatabaseCount('enterprise_finance_source_postings', 2);
        $this->assertDatabaseCount('enterprise_finance_journals', 2);
        $this->assertDatabaseCount('enterprise_finance_ledger_entries', 4);
        $this->assertDatabaseHas('enterprise_finance_source_postings', [
            'event_id' => $procurement->id,
            'event_type' => 'ProcurementPurchaseInvoiceHandedOff',
            'event_version' => 1,
            'source_domain' => 'procurement',
            'source_type' => 'purchase_invoice',
            'source_id' => 101,
            'source_reference' => 'PINV-101',
            'currency_code' => 'KWD',
        ]);
        $this->assertDatabaseHas('enterprise_finance_source_postings', [
            'event_id' => $treasury->id,
            'event_type' => 'TreasuryReceiptPosted',
            'event_version' => 1,
            'source_domain' => 'treasury',
            'source_type' => 'receipt',
            'source_id' => 202,
            'source_reference' => 'TR-202',
            'currency_code' => 'KWD',
        ]);

        app(FinanceSourcePostingConsumer::class)->handle($procurement);
        $this->assertDatabaseCount('enterprise_finance_journals', 2);
        $this->assertDatabaseCount('enterprise_finance_ledger_entries', 4);
    }

    public function test_disabled_or_pre_effective_finance_never_backfills_source_events(): void
    {
        $fixture = $this->fixture('FIN-SOURCE-GATE');
        $this->configurePosting($fixture);

        $scope = $fixture['maker_context']->enterpriseScope;
        $this->assertNotNull($scope);

        $preEffective = $this->procurementEvent(
            $fixture,
            CarbonImmutable::now('UTC')->subDay(),
        );
        app(TransactionalOutbox::class)->enqueue($preEffective);
        $this->assertSame(1, app(OutboxDispatcher::class)->dispatchDue(10));
        $this->assertDatabaseCount('enterprise_finance_journals', 0);
        $this->assertDatabaseCount('enterprise_finance_source_postings', 0);

        app(ModuleLifecycleManager::class)->transition(
            FinanceFoundationService::MODULE,
            ModuleState::DISABLED,
            $fixture['maker'],
        );

        $disabled = $this->treasuryReceiptEvent($fixture);
        $message = app(TransactionalOutbox::class)->enqueue($disabled);
        $this->assertSame(1, app(OutboxDispatcher::class)->dispatchDue(10));
        $this->assertSame('dispatched', $message->refresh()->status);
        $this->assertDatabaseCount('enterprise_finance_journals', 0);
        $this->assertDatabaseCount('enterprise_finance_source_postings', 0);
    }

    public function test_cross_scope_and_unsupported_contracts_fail_closed_without_source_corruption(): void
    {
        $fixture = $this->fixture('FIN-SOURCE-FAIL');
        $this->configurePosting($fixture);
        config()->set('enterprise_outbox.max_attempts', 1);

        $scope = $fixture['maker_context']->enterpriseScope;
        $this->assertNotNull($scope);

        $crossScope = $this->procurementEvent(
            $fixture,
            null,
            [
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId + 999,
                'store_id' => $scope->storeId,
            ],
        );

        $message = app(TransactionalOutbox::class)->enqueue($crossScope);
        $this->assertSame(0, app(OutboxDispatcher::class)->dispatchDue(10));
        $this->assertSame('dead', $message->refresh()->status);
        $this->assertSame(LogicException::class, $message->last_error);
        $this->assertDatabaseCount('enterprise_finance_journals', 0);
        $this->assertDatabaseCount('enterprise_finance_source_postings', 0);

        $unsupported = new VersionedDomainEvent(
            id: (string) Str::uuid(),
            type: 'ProcurementPurchaseInvoiceHandedOff',
            version: 2,
            occurredAt: CarbonImmutable::now('UTC'),
            scope: [
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId,
                'store_id' => $scope->storeId,
            ],
            correlationId: null,
            causationId: null,
            payload: $this->procurementPayload(),
        );

        $this->expectException(LogicException::class);
        app(FinanceSourcePostingConsumer::class)->handle($unsupported);
    }

    public function test_source_posting_configuration_reserves_all_finance_source_domains_without_inventing_contracts(): void
    {
        $domains = config('enterprise_finance_integrations.supported_domains');

        $this->assertSame(
            ['sales', 'procurement', 'inventory', 'treasury'],
            $domains,
        );
        $this->assertArrayHasKey(
            'ProcurementPurchaseInvoiceHandedOff',
            (array) config('enterprise_finance_integrations.events'),
        );
        $this->assertArrayHasKey(
            'TreasuryReceiptPosted',
            (array) config('enterprise_finance_integrations.events'),
        );
        $this->assertSame(
            ['EnterpriseModuleStateChanged', 'ProcurementPurchaseInvoiceHandedOff', 'TreasuryReceiptPosted', 'TreasuryPaymentPosted', 'TreasuryReconciliationClosed', 'TreasuryCashClosingClosed'],
            array_keys((array) config('enterprise_events.contracts')),
        );
    }

    public function test_source_posting_migration_is_additive_and_reversible(): void
    {
        $migration = require database_path(
            'migrations/2026_10_16_102800_create_enterprise_finance_source_postings.php',
        );

        $migration->down();
        $this->assertFalse(Schema::hasTable('enterprise_finance_source_postings'));
        $this->assertTrue(Schema::hasTable('enterprise_finance_journals'));
        $this->assertTrue(Schema::hasTable('enterprise_outbox_messages'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('enterprise_finance_source_postings'));
    }

    /**
     * @return array{
     *   maker:User,
     *   poster:User,
     *   maker_context:ResolvedTenantContext,
     *   poster_context:ResolvedTenantContext
     * }
     */
    private function fixture(string $code): array
    {
        $store = $this->createRetailStore($code);
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
        $control = $finance->createAccount($maker, $makerContext, [
            'code' => '1000',
            'name' => 'Assets',
            'type' => 'asset',
            'normal_balance' => 'debit',
            'is_control' => true,
        ]);
        $finance->createAccount($maker, $makerContext, [
            'parent_id' => $control->id,
            'code' => '1100',
            'name' => 'Integration Debit',
            'type' => 'asset',
            'normal_balance' => 'debit',
            'allow_posting' => true,
        ]);
        $finance->createAccount($maker, $makerContext, [
            'code' => '4000',
            'name' => 'Integration Credit',
            'type' => 'revenue',
            'normal_balance' => 'credit',
            'allow_posting' => true,
        ]);
        $year = $finance->createFiscalYear($maker, $makerContext, [
            'code' => 'FY2027',
            'name' => 'Fiscal Year 2027',
            'starts_on' => '2027-01-01',
            'ends_on' => '2027-12-31',
        ]);
        $finance->createFiscalPeriod($maker, $makerContext, $year->id, [
            'sequence' => 1,
            'code' => 'P01',
            'name' => 'January 2027',
            'starts_on' => '2027-01-01',
            'ends_on' => '2027-01-31',
        ]);

        return [
            'maker' => $maker,
            'poster' => $poster,
            'maker_context' => $makerContext,
            'poster_context' => $posterContext,
        ];
    }

    /** @param array{maker:User,poster:User,maker_context:ResolvedTenantContext,poster_context:ResolvedTenantContext} $fixture */
    private function configurePosting(array $fixture): void
    {
        config()->set('enterprise_finance_integrations.currency_code', 'KWD');
        config()->set('enterprise_finance_integrations.maker_user_id', $fixture['maker']->id);
        config()->set('enterprise_finance_integrations.poster_user_id', $fixture['poster']->id);

        foreach ([
            'ProcurementPurchaseInvoiceHandedOff',
            'TreasuryReceiptPosted',
            'TreasuryPaymentPosted',
        ] as $eventType) {
            config()->set(
                'enterprise_finance_integrations.events.'.$eventType.'.debit_account_code',
                '1100',
            );
            config()->set(
                'enterprise_finance_integrations.events.'.$eventType.'.credit_account_code',
                '4000',
            );
        }
    }

    /** @param array{maker:User,poster:User,maker_context:ResolvedTenantContext,poster_context:ResolvedTenantContext} $fixture */
    private function procurementEvent(
        array $fixture,
        ?CarbonImmutable $occurredAt = null,
        ?array $scopeOverride = null,
    ): VersionedDomainEvent {
        $scope = $fixture['maker_context']->enterpriseScope;
        $this->assertNotNull($scope);

        return new VersionedDomainEvent(
            id: (string) Str::uuid(),
            type: 'ProcurementPurchaseInvoiceHandedOff',
            version: 1,
            occurredAt: $occurredAt ?? CarbonImmutable::now('UTC'),
            scope: $scopeOverride ?? [
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId,
                'store_id' => $scope->storeId,
            ],
            correlationId: null,
            causationId: null,
            payload: $this->procurementPayload(),
        );
    }

    /** @param array{maker:User,poster:User,maker_context:ResolvedTenantContext,poster_context:ResolvedTenantContext} $fixture */
    private function treasuryReceiptEvent(array $fixture): VersionedDomainEvent
    {
        $scope = $fixture['maker_context']->enterpriseScope;
        $this->assertNotNull($scope);

        return new VersionedDomainEvent(
            id: (string) Str::uuid(),
            type: 'TreasuryReceiptPosted',
            version: 1,
            occurredAt: CarbonImmutable::now('UTC'),
            scope: [
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId,
                'legal_entity_id' => $scope->legalEntityId,
                'store_id' => $scope->storeId,
                'currency_code' => 'KWD',
            ],
            correlationId: null,
            causationId: null,
            payload: [
                'source' => [
                    'domain' => 'treasury',
                    'type' => 'receipt',
                    'id' => 202,
                    'reference' => 'TR-202',
                ],
                'consumer_hints' => ['finance', 'ar'],
                'data' => [
                    'treasury_receipt_id' => 202,
                    'receipt_number' => 'TR-202',
                    'target_type' => 'cashbox',
                    'target_id' => 1,
                    'amount' => '7.500',
                    'currency_code' => 'KWD',
                    'value_date' => '2027-01-16',
                    'reference' => 'RECEIPT-202',
                    'description' => 'Treasury receipt',
                    'posted_by' => $fixture['maker']->id,
                    'posted_at' => CarbonImmutable::now('UTC')->toIso8601String(),
                ],
            ],
        );
    }

    /** @return array<string, mixed> */
    private function procurementPayload(): array
    {
        return [
            'purchase_invoice_id' => 101,
            'invoice_number' => 'PINV-101',
            'supplier_invoice_number' => 'SUP-101',
            'supplier_id' => 1,
            'goods_receipt_id' => 1,
            'source_receipt_number' => 'GRN-101',
            'source_order_number' => 'PO-101',
            'currency_code' => 'KWD',
            'invoice_date' => '2027-01-15',
            'due_date' => '2027-02-15',
            'total_amount' => '12.250',
            'lines' => [],
        ];
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
            ],
            $actor,
        );
    }

    /** @param list<string> $permissions */
    private function userForStore(Store $store, array $permissions, string $code): User
    {
        $role = $this->roleWithPermissions($permissions, $code);
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

    /** @param list<string> $permissions */
    private function roleWithPermissions(array $permissions, string $code): Role
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

        return $role;
    }

    private function createRetailStore(string $code): Store
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        if ($typeId < 1) {
            $typeId = DB::table('store_types')->insertGetId([
                'code' => 'B2C',
                'name' => 'Retail',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $store = Store::query()->create([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);

        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }
}
