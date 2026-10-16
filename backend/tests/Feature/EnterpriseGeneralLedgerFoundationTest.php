<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Finance\FinanceFoundationService;
use App\Enterprise\Finance\GeneralLedgerService;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\EnterpriseBusinessUnit;
use App\Models\EnterpriseCompany;
use App\Models\EnterpriseFinanceLedgerEntry;
use App\Models\EnterpriseLegalEntity;
use App\Models\EnterpriseOrganization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\ResolvedTenantContext;
use App\Support\TenantContextResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

final class EnterpriseGeneralLedgerFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_balanced_journal_create_and_post_are_idempotent_and_ledger_is_immutable(): void
    {
        $fixture = $this->fixture('GL-IDEMPOTENT');
        $ledger = app(GeneralLedgerService::class);

        $payload = [
            'idempotency_key' => 'journal-001',
            'entry_date' => '2027-01-15',
            'description' => 'Opening operating journal',
            'lines' => [
                [
                    'account_id' => $fixture['cash_account_id'],
                    'debit' => '10.250',
                    'credit' => '0',
                    'memo' => 'Cash',
                ],
                [
                    'account_id' => $fixture['revenue_account_id'],
                    'debit' => '0',
                    'credit' => '10.250',
                    'memo' => 'Revenue',
                ],
            ],
        ];

        $draft = $ledger->createDraft(
            $fixture['maker'],
            $fixture['maker_context'],
            $payload,
        );
        $retry = $ledger->createDraft(
            $fixture['maker'],
            $fixture['maker_context'],
            $payload,
        );

        $this->assertSame($draft->id, $retry->id);
        $this->assertSame('draft', $draft->status);
        $this->assertSame('10.250', $draft->total_debit);
        $this->assertSame('10.250', $draft->total_credit);
        $this->assertSame($fixture['maker']->id, $draft->requested_by);
        $this->assertDatabaseCount('enterprise_finance_journals', 1);
        $this->assertDatabaseCount('enterprise_finance_journal_lines', 2);
        $this->assertDatabaseCount('enterprise_finance_ledger_entries', 0);

        try {
            $ledger->createDraft(
                $fixture['maker'],
                $fixture['maker_context'],
                [
                    ...$payload,
                    'description' => 'Different payload',
                ],
            );
            $this->fail('An idempotency key cannot be reused for a different journal request.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('idempotency_key', $exception->errors());
        }

        try {
            $ledger->post(
                $fixture['maker'],
                $fixture['maker_context'],
                $draft->id,
            );
            $this->fail('The journal requester must not post the same journal.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('poster', $exception->errors());
        }

        $posted = $ledger->post(
            $fixture['poster'],
            $fixture['poster_context'],
            $draft->id,
        );
        $postedRetry = $ledger->post(
            $fixture['poster'],
            $fixture['poster_context'],
            $draft->id,
        );

        $this->assertSame('posted', $posted->status);
        $this->assertSame($posted->id, $postedRetry->id);
        $this->assertSame($fixture['poster']->id, $posted->posted_by);
        $this->assertNotNull($posted->posted_at);
        $this->assertDatabaseCount('enterprise_finance_ledger_entries', 2);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.finance.journal_created',
            'auditable_id' => $draft->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.finance.journal_posted',
            'auditable_id' => $draft->id,
        ]);

        $entry = EnterpriseFinanceLedgerEntry::query()->firstOrFail();
        $entry->debit = '9.000';

        $this->expectException(LogicException::class);
        $entry->save();
    }

    public function test_journal_rejects_unbalanced_closed_period_and_non_posting_accounts(): void
    {
        $fixture = $this->fixture('GL-VALIDATION');
        $ledger = app(GeneralLedgerService::class);

        try {
            $ledger->createDraft(
                $fixture['maker'],
                $fixture['maker_context'],
                [
                    'idempotency_key' => 'unbalanced',
                    'entry_date' => '2027-01-15',
                    'lines' => [
                        ['account_id' => $fixture['cash_account_id'], 'debit' => '10.000'],
                        ['account_id' => $fixture['revenue_account_id'], 'credit' => '9.000'],
                    ],
                ],
            );
            $this->fail('Unbalanced journals must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines', $exception->errors());
        }

        try {
            $ledger->createDraft(
                $fixture['maker'],
                $fixture['maker_context'],
                [
                    'idempotency_key' => 'control-account',
                    'entry_date' => '2027-01-15',
                    'lines' => [
                        ['account_id' => $fixture['control_account_id'], 'debit' => '10.000'],
                        ['account_id' => $fixture['revenue_account_id'], 'credit' => '10.000'],
                    ],
                ],
            );
            $this->fail('Control/non-posting accounts must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines', $exception->errors());
        }

        app(FinanceFoundationService::class)->closeFiscalPeriod(
            $fixture['maker'],
            $fixture['maker_context'],
            $fixture['period_id'],
        );

        try {
            $ledger->createDraft(
                $fixture['maker'],
                $fixture['maker_context'],
                [
                    'idempotency_key' => 'closed-period',
                    'entry_date' => '2027-01-15',
                    'lines' => [
                        ['account_id' => $fixture['cash_account_id'], 'debit' => '5.000'],
                        ['account_id' => $fixture['revenue_account_id'], 'credit' => '5.000'],
                    ],
                ],
            );
            $this->fail('Closed fiscal periods must reject new journals.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('entry_date', $exception->errors());
        }
    }

    public function test_general_ledger_isolated_by_enterprise_scope(): void
    {
        $tenantA = $this->fixture('GL-TENANT-A');
        $tenantB = $this->fixture('GL-TENANT-B', true);
        $ledger = app(GeneralLedgerService::class);

        $journal = $ledger->createDraft(
            $tenantA['maker'],
            $tenantA['maker_context'],
            [
                'idempotency_key' => 'tenant-a-journal',
                'entry_date' => '2027-01-15',
                'lines' => [
                    ['account_id' => $tenantA['cash_account_id'], 'debit' => '3.000'],
                    ['account_id' => $tenantA['revenue_account_id'], 'credit' => '3.000'],
                ],
            ],
        );

        $this->assertSame(
            0,
            $ledger->paginateJournals(
                $tenantB['maker'],
                $tenantB['maker_context'],
            )->total(),
        );
        $this->assertSame(
            0,
            $ledger->paginateLedger(
                $tenantB['maker'],
                $tenantB['maker_context'],
            )->total(),
        );

        $this->expectException(ModelNotFoundException::class);
        $ledger->post(
            $tenantB['poster'],
            $tenantB['poster_context'],
            $journal->id,
        );
    }

    public function test_journal_and_ledger_queries_are_bounded_to_one_hundred_rows(): void
    {
        $fixture = $this->fixture('GL-BOUNDED');
        $scope = $fixture['maker_context']->enterpriseScope;
        $this->assertNotNull($scope);
        $now = now();

        $journals = [];
        for ($index = 1; $index <= 105; $index++) {
            $journals[] = [
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId,
                'legal_entity_id' => $scope->legalEntityId,
                'fiscal_period_id' => $fixture['period_id'],
                'journal_number' => sprintf('GL-BULK-%03d', $index),
                'idempotency_key' => sprintf('bulk-%03d', $index),
                'request_hash' => hash('sha256', 'bulk-'.$index),
                'entry_date' => '2027-01-15',
                'description' => 'Bounded query fixture',
                'status' => 'posted',
                'total_debit' => 1,
                'total_credit' => 1,
                'requested_by' => $fixture['maker']->id,
                'posted_at' => $now,
                'posted_by' => $fixture['poster']->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($journals, 50) as $chunk) {
            DB::table('enterprise_finance_journals')->insert($chunk);
        }

        $journalPage = app(GeneralLedgerService::class)->paginateJournals(
            $fixture['maker'],
            $fixture['maker_context'],
            [],
            500,
        );
        $this->assertSame(100, $journalPage->perPage());
        $this->assertSame(105, $journalPage->total());
        $this->assertCount(100, $journalPage->items());

        $journalId = (int) DB::table('enterprise_finance_journals')
            ->where('journal_number', 'GL-BULK-001')
            ->value('id');

        $lines = [];
        for ($index = 1; $index <= 105; $index++) {
            $lines[] = [
                'journal_id' => $journalId,
                'line_number' => $index,
                'account_id' => $fixture['cash_account_id'],
                'debit' => 1,
                'credit' => 0,
                'memo' => 'Bounded ledger fixture',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($lines, 50) as $chunk) {
            DB::table('enterprise_finance_journal_lines')->insert($chunk);
        }

        $journalLines = DB::table('enterprise_finance_journal_lines')
            ->where('journal_id', $journalId)
            ->orderBy('line_number')
            ->get(['id']);

        $ledgerRows = [];
        foreach ($journalLines as $line) {
            $ledgerRows[] = [
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId,
                'legal_entity_id' => $scope->legalEntityId,
                'journal_id' => $journalId,
                'journal_line_id' => (int) $line->id,
                'fiscal_period_id' => $fixture['period_id'],
                'account_id' => $fixture['cash_account_id'],
                'entry_date' => '2027-01-15',
                'debit' => 1,
                'credit' => 0,
                'posted_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($ledgerRows, 50) as $chunk) {
            DB::table('enterprise_finance_ledger_entries')->insert($chunk);
        }

        $ledgerPage = app(GeneralLedgerService::class)->paginateLedger(
            $fixture['maker'],
            $fixture['maker_context'],
            [],
            500,
        );
        $this->assertSame(100, $ledgerPage->perPage());
        $this->assertSame(105, $ledgerPage->total());
        $this->assertCount(100, $ledgerPage->items());
    }

    public function test_general_ledger_migration_is_additive_and_reversible(): void
    {
        $migration = require database_path(
            'migrations/2026_10_16_022500_create_enterprise_general_ledger_foundation.php',
        );

        $migration->down();

        $this->assertFalse(Schema::hasTable('enterprise_finance_journals'));
        $this->assertFalse(Schema::hasTable('enterprise_finance_journal_lines'));
        $this->assertFalse(Schema::hasTable('enterprise_finance_ledger_entries'));
        $this->assertTrue(Schema::hasTable('enterprise_finance_accounts'));
        $this->assertTrue(Schema::hasTable('enterprise_fiscal_years'));
        $this->assertTrue(Schema::hasTable('enterprise_fiscal_periods'));

        $migration->up();

        $this->assertTrue(Schema::hasTable('enterprise_finance_journals'));
        $this->assertTrue(Schema::hasTable('enterprise_finance_journal_lines'));
        $this->assertTrue(Schema::hasTable('enterprise_finance_ledger_entries'));
    }

    /**
     * @return array{
     *   maker:User,
     *   poster:User,
     *   maker_context:ResolvedTenantContext,
     *   poster_context:ResolvedTenantContext,
     *   control_account_id:int,
     *   cash_account_id:int,
     *   revenue_account_id:int,
     *   period_id:int
     * }
     */
    private function fixture(string $code, bool $separateOrganization = false): array
    {
        $store = $this->createRetailStore($code, ! $separateOrganization);
        if ($separateOrganization) {
            $this->assignStoreToNewOrganization($store);
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
        $control = $finance->createAccount($maker, $makerContext, [
            'code' => '1000',
            'name' => 'Assets',
            'type' => 'asset',
            'normal_balance' => 'debit',
            'is_control' => true,
        ]);
        $cash = $finance->createAccount($maker, $makerContext, [
            'parent_id' => $control->id,
            'code' => '1100',
            'name' => 'Cash',
            'type' => 'asset',
            'normal_balance' => 'debit',
            'allow_posting' => true,
        ]);
        $revenue = $finance->createAccount($maker, $makerContext, [
            'code' => '4000',
            'name' => 'Revenue',
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
        $period = $finance->createFiscalPeriod($maker, $makerContext, $year->id, [
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
            'control_account_id' => $control->id,
            'cash_account_id' => $cash->id,
            'revenue_account_id' => $revenue->id,
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

    private function createRetailStore(string $code, bool $defaultScope = true): Store
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

        if ($defaultScope) {
            app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);
        }

        return $store;
    }

    private function assignStoreToNewOrganization(Store $store): void
    {
        $organization = EnterpriseOrganization::query()->create([
            'code' => 'gl_org_'.$store->id,
            'name' => 'GL Test Organization',
            'is_active' => true,
        ]);
        $company = EnterpriseCompany::query()->create([
            'organization_id' => $organization->id,
            'code' => 'gl_company_'.$store->id,
            'name' => 'GL Test Company',
            'is_active' => true,
        ]);
        $legalEntity = EnterpriseLegalEntity::query()->create([
            'company_id' => $company->id,
            'code' => 'gl_legal_'.$store->id,
            'name' => 'GL Test Legal Entity',
            'is_active' => true,
        ]);
        $businessUnit = EnterpriseBusinessUnit::query()->create([
            'legal_entity_id' => $legalEntity->id,
            'code' => 'gl_unit_'.$store->id,
            'name' => 'GL Test Unit',
            'is_active' => true,
        ]);

        app(OrganizationHierarchyService::class)->assignStore($store, $businessUnit);
    }
}
