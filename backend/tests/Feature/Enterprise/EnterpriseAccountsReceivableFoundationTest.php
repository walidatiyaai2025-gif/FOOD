<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Finance\AccountsReceivableService;
use App\Enterprise\Finance\FinanceFoundationService;
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
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class EnterpriseAccountsReceivableFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_ar_document_and_allocation_post_once_through_canonical_gl(): void
    {
        $fixture = $this->fixture('AR-LIFE');
        $service = app(AccountsReceivableService::class);

        $payload = [
            'idempotency_key' => 'ar-doc-001',
            'store_id' => $fixture['store']->id,
            'customer_id' => $fixture['customer']->id,
            'entry_date' => '2027-01-05',
            'due_date' => '2027-02-05',
            'amount' => '100.000',
            'currency_code' => 'KWD',
            'receivable_account_id' => $fixture['receivable_account_id'],
            'offset_account_id' => $fixture['revenue_account_id'],
            'description' => 'Customer invoice',
        ];

        $document = $service->createDraft(
            $fixture['maker'],
            $fixture['maker_context'],
            $payload,
        );
        $retry = $service->createDraft(
            $fixture['maker'],
            $fixture['maker_context'],
            $payload,
        );

        $this->assertSame($document->id, $retry->id);
        $this->assertDatabaseCount('enterprise_ar_documents', 1);

        try {
            $service->post(
                $fixture['maker'],
                $fixture['maker_context'],
                $document->id,
            );
            $this->fail('AR maker must not post the same document.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('poster', $exception->errors());
        }

        $posted = $service->post(
            $fixture['poster'],
            $fixture['poster_context'],
            $document->id,
        );
        $postedRetry = $service->post(
            $fixture['poster'],
            $fixture['poster_context'],
            $document->id,
        );

        $this->assertSame('posted', $posted->status);
        $this->assertSame($posted->id, $postedRetry->id);
        $this->assertNotNull($posted->journal_id);
        $this->assertDatabaseCount('enterprise_finance_journals', 1);
        $this->assertDatabaseCount('enterprise_finance_ledger_entries', 2);

        $allocationPayload = [
            'idempotency_key' => 'ar-alloc-001',
            'allocation_date' => '2027-01-10',
            'amount' => '40.000',
            'settlement_account_id' => $fixture['cash_account_id'],
            'reference' => 'RECEIPT-001',
        ];
        $allocation = $service->createAllocation(
            $fixture['maker'],
            $fixture['maker_context'],
            $posted->id,
            $allocationPayload,
        );

        try {
            $service->postAllocation(
                $fixture['maker'],
                $fixture['maker_context'],
                $allocation->id,
            );
            $this->fail('Allocation maker must not post the same allocation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('poster', $exception->errors());
        }

        $postedAllocation = $service->postAllocation(
            $fixture['poster'],
            $fixture['poster_context'],
            $allocation->id,
        );
        $allocationRetry = $service->createAllocation(
            $fixture['maker'],
            $fixture['maker_context'],
            $posted->id,
            $allocationPayload,
        );

        $this->assertSame('posted', $postedAllocation->status);
        $this->assertSame($postedAllocation->id, $allocationRetry->id);
        $this->assertDatabaseCount('enterprise_finance_journals', 2);
        $this->assertDatabaseCount('enterprise_finance_ledger_entries', 4);

        $aging = $service->aging(
            $fixture['maker'],
            $fixture['maker_context'],
            $fixture['customer']->id,
        );
        $this->assertCount(1, $aging);
        $this->assertSame(60.0, (float) $aging->first()->outstanding_amount);

        try {
            $service->createAllocation(
                $fixture['maker'],
                $fixture['maker_context'],
                $posted->id,
                [
                    ...$allocationPayload,
                    'idempotency_key' => 'ar-over-allocate',
                    'amount' => '61.000',
                ],
            );
            $this->fail('Allocation beyond outstanding balance must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount', $exception->errors());
        }

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.finance.ar_document_posted',
            'auditable_id' => $document->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.finance.ar_allocation_posted',
            'auditable_id' => $allocation->id,
        ]);
    }

    public function test_ar_draft_cancel_is_retry_safe_and_listing_is_bounded(): void
    {
        $fixture = $this->fixture('AR-CANCEL');
        $service = app(AccountsReceivableService::class);

        $document = $service->createDraft(
            $fixture['maker'],
            $fixture['maker_context'],
            [
                'idempotency_key' => 'ar-cancel-001',
                'store_id' => $fixture['store']->id,
                'customer_id' => $fixture['customer']->id,
                'entry_date' => '2027-01-05',
                'due_date' => '2027-02-05',
                'amount' => '10.000',
                'receivable_account_id' => $fixture['receivable_account_id'],
                'offset_account_id' => $fixture['revenue_account_id'],
            ],
        );

        $cancelled = $service->cancel(
            $fixture['maker'],
            $fixture['maker_context'],
            $document->id,
        );
        $cancelRetry = $service->cancel(
            $fixture['maker'],
            $fixture['maker_context'],
            $document->id,
        );

        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame($cancelled->id, $cancelRetry->id);

        $page = $service->paginate(
            $fixture['maker'],
            $fixture['maker_context'],
            [],
            500,
        );
        $this->assertSame(100, $page->perPage());
        $this->assertSame(1, $page->total());
    }

    public function test_unentitled_finance_performs_no_ar_table_query_work(): void
    {
        $store = $this->createStore('AR-NO-ENT');
        $actor = $this->userForStore($store, ['finance.view'], 'AR-NO-ENT-VIEW');
        $context = app(TenantContextResolver::class)->retail($actor, $store->id);

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower((string) $query->sql);
        });

        try {
            app(AccountsReceivableService::class)->paginate($actor, $context);
            $this->fail('AR reads require Finance entitlement.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertFalse(collect($queries)->contains(
            static fn (string $sql): bool => str_contains($sql, 'enterprise_ar_'),
        ));
    }

    public function test_ar_schema_is_additive_and_reversible(): void
    {
        $migration = require database_path(
            'migrations/2026_10_16_074300_create_enterprise_ar_foundation.php',
        );

        $migration->down();
        $this->assertFalse(Schema::hasTable('enterprise_ar_allocations'));
        $this->assertFalse(Schema::hasTable('enterprise_ar_documents'));
        $this->assertTrue(Schema::hasTable('enterprise_finance_journals'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('enterprise_ar_documents'));
        $this->assertTrue(Schema::hasTable('enterprise_ar_allocations'));
    }

    /**
     * @return array{
     *   store:Store,
     *   customer:Customer,
     *   maker:User,
     *   poster:User,
     *   maker_context:ResolvedTenantContext,
     *   poster_context:ResolvedTenantContext,
     *   receivable_account_id:int,
     *   revenue_account_id:int,
     *   cash_account_id:int
     * }
     */
    private function fixture(string $code): array
    {
        $store = $this->createStore($code);
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
        $receivable = $finance->createAccount(
            $maker,
            $makerContext,
            [
                'code' => '1200',
                'name' => 'Accounts Receivable',
                'type' => 'asset',
                'normal_balance' => 'debit',
                'allow_posting' => true,
            ],
        );
        $revenue = $finance->createAccount(
            $maker,
            $makerContext,
            [
                'code' => '4000',
                'name' => 'Sales Revenue',
                'type' => 'revenue',
                'normal_balance' => 'credit',
                'allow_posting' => true,
            ],
        );
        $cash = $finance->createAccount(
            $maker,
            $makerContext,
            [
                'code' => '1100',
                'name' => 'Cash',
                'type' => 'asset',
                'normal_balance' => 'debit',
                'allow_posting' => true,
            ],
        );

        $year = $finance->createFiscalYear(
            $maker,
            $makerContext,
            [
                'code' => 'FY2027',
                'name' => 'Fiscal Year 2027',
                'starts_on' => '2027-01-01',
                'ends_on' => '2027-12-31',
            ],
        );
        $finance->createFiscalPeriod(
            $maker,
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
            'maker' => $maker,
            'poster' => $poster,
            'maker_context' => $makerContext,
            'poster_context' => $posterContext,
            'receivable_account_id' => $receivable->id,
            'revenue_account_id' => $revenue->id,
            'cash_account_id' => $cash->id,
        ];
    }

    private function enableFinance(
        User $actor,
        ResolvedTenantContext $context,
    ): void {
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
                'finance.ar.read',
                'finance.ar.manage',
                'finance.ar.post',
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

    /** @param list<string> $permissions */
    private function userForStore(
        Store $store,
        array $permissions,
        string $code,
    ): User {
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
