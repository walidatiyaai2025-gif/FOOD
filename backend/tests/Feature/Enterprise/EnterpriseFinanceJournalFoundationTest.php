<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Finance\FinanceFoundationService;
use App\Enterprise\Finance\FinanceJournalService;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\EnterpriseBusinessUnit;
use App\Models\EnterpriseCompany;
use App\Models\EnterpriseFinanceAccount;
use App\Models\EnterpriseFinanceLedgerEntry;
use App\Models\EnterpriseFiscalPeriod;
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
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class EnterpriseFinanceJournalFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_and_posting_are_idempotent_balanced_audited_and_immutable(): void
    {
        [$creator, $store, $creatorContext] = $this->scopedUser(
            ['finance.view', 'finance.manage'],
            'GL-IDEMP',
        );
        $poster = $this->userForStore($store, ['finance.view', 'finance.post'], 'GL_POSTER');
        $posterContext = app(TenantContextResolver::class)->retail($poster, $store->id);
        $this->enableFinance($creator, $creatorContext);

        [$debitAccount, $creditAccount] = $this->postingAccounts($creator, $creatorContext, 'IDEM');
        $this->openPeriod($creator, $creatorContext, 'IDEM');

        $journals = app(FinanceJournalService::class);
        $payload = [
            'idempotency_key' => 'manual:idem:001',
            'entry_date' => '2027-01-15',
            'reference' => 'MAN-001',
            'memo' => 'Opening test journal',
            'lines' => [
                ['account_id' => $debitAccount->id, 'debit' => '125.5000'],
                ['account_id' => $creditAccount->id, 'credit' => '125.5000'],
            ],
        ];

        $draft = $journals->createDraft($creator, $creatorContext, $payload);
        $retry = $journals->createDraft($creator, $creatorContext, $payload);

        $this->assertSame($draft->id, $retry->id);
        $this->assertSame('draft', $draft->status);
        $this->assertSame($creator->id, $draft->created_by);
        $this->assertCount(2, $draft->lines);
        $this->assertDatabaseCount('enterprise_finance_journals', 1);
        $this->assertDatabaseCount('enterprise_finance_journal_lines', 2);

        try {
            $journals->post($creator, $creatorContext, $draft->id);
            $this->fail('Journal posting requires the dedicated finance.post permission.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $posted = $journals->post($poster, $posterContext, $draft->id);
        $postedRetry = $journals->post($poster, $posterContext, $draft->id);

        $this->assertSame($posted->id, $postedRetry->id);
        $this->assertSame('posted', $posted->status);
        $this->assertSame($creator->id, $posted->created_by);
        $this->assertSame($poster->id, $posted->posted_by);
        $this->assertNotNull($posted->posted_at);
        $this->assertCount(2, $posted->ledgerEntries);
        $this->assertDatabaseCount('enterprise_finance_ledger_entries', 2);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.finance.journal_draft_created',
            'auditable_id' => $draft->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.finance.journal_posted',
            'auditable_id' => $draft->id,
        ]);

        /** @var EnterpriseFinanceLedgerEntry $entry */
        $entry = EnterpriseFinanceLedgerEntry::query()->firstOrFail();

        $this->expectException(LogicException::class);
        $entry->forceFill(['debit' => '1.0000'])->save();
    }

    public function test_journal_rejects_unbalanced_closed_period_non_posting_and_idempotency_conflicts(): void
    {
        [$actor, , $context] = $this->scopedUser(
            ['finance.view', 'finance.manage', 'finance.post'],
            'GL-VALID',
        );
        $this->enableFinance($actor, $context);

        [$debitAccount, $creditAccount] = $this->postingAccounts($actor, $context, 'VALID');
        $period = $this->openPeriod($actor, $context, 'VALID');
        $finance = app(FinanceFoundationService::class);
        $journals = app(FinanceJournalService::class);

        try {
            $journals->createDraft($actor, $context, [
                'idempotency_key' => 'invalid:unbalanced',
                'entry_date' => '2027-01-15',
                'lines' => [
                    ['account_id' => $debitAccount->id, 'debit' => '10.0000'],
                    ['account_id' => $creditAccount->id, 'credit' => '9.0000'],
                ],
            ]);
            $this->fail('Unbalanced journals must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines', $exception->errors());
        }

        $control = $finance->createAccount($actor, $context, [
            'code' => 'CTRL-VALID',
            'name' => 'Control Account',
            'type' => 'asset',
            'normal_balance' => 'debit',
            'is_control' => true,
        ]);

        try {
            $journals->createDraft($actor, $context, [
                'idempotency_key' => 'invalid:control',
                'entry_date' => '2027-01-15',
                'lines' => [
                    ['account_id' => $control->id, 'debit' => '10.0000'],
                    ['account_id' => $creditAccount->id, 'credit' => '10.0000'],
                ],
            ]);
            $this->fail('Control accounts must reject direct journal posting.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines', $exception->errors());
        }

        $payload = [
            'idempotency_key' => 'manual:conflict:001',
            'entry_date' => '2027-01-15',
            'lines' => [
                ['account_id' => $debitAccount->id, 'debit' => '10.0000'],
                ['account_id' => $creditAccount->id, 'credit' => '10.0000'],
            ],
        ];
        $journals->createDraft($actor, $context, $payload);

        $payload['lines'][0]['debit'] = '11.0000';
        $payload['lines'][1]['credit'] = '11.0000';

        try {
            $journals->createDraft($actor, $context, $payload);
            $this->fail('An idempotency key cannot be reused for a different payload.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('idempotency_key', $exception->errors());
        }

        $finance->closeFiscalPeriod($actor, $context, $period->id);

        try {
            $journals->createDraft($actor, $context, [
                'idempotency_key' => 'invalid:closed-period',
                'entry_date' => '2027-01-20',
                'lines' => [
                    ['account_id' => $debitAccount->id, 'debit' => '1.0000'],
                    ['account_id' => $creditAccount->id, 'credit' => '1.0000'],
                ],
            ]);
            $this->fail('Closed fiscal periods must reject journal creation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('entry_date', $exception->errors());
        }
    }

    public function test_journal_and_ledger_reads_are_tenant_scoped_and_bounded(): void
    {
        [$actorA, , $contextA] = $this->scopedUser(
            ['finance.view', 'finance.manage', 'finance.post'],
            'GL-TEN-A',
        );
        [$actorB, , $contextB] = $this->scopedUserInNewOrganization(
            ['finance.view', 'finance.manage', 'finance.post'],
            'GL-TEN-B',
        );
        $this->enableFinance($actorA, $contextA);
        $this->enableFinance($actorB, $contextB);

        [$debitB, $creditB] = $this->postingAccounts($actorB, $contextB, 'TENB');
        $this->openPeriod($actorB, $contextB, 'TENB');

        $journals = app(FinanceJournalService::class);
        $foreign = $journals->createDraft($actorB, $contextB, [
            'idempotency_key' => 'tenant:b:001',
            'entry_date' => '2027-01-15',
            'lines' => [
                ['account_id' => $debitB->id, 'debit' => '25.0000'],
                ['account_id' => $creditB->id, 'credit' => '25.0000'],
            ],
        ]);
        $journals->post($actorB, $contextB, $foreign->id);

        $journalPage = $journals->paginateJournals($actorA, $contextA, [], 500);
        $ledgerPage = $journals->paginateLedger($actorA, $contextA, [], 500);
        $this->assertSame(100, $journalPage->perPage());
        $this->assertSame(100, $ledgerPage->perPage());
        $this->assertSame(0, $journalPage->total());
        $this->assertSame(0, $ledgerPage->total());

        $this->expectException(ModelNotFoundException::class);
        $journals->post($actorA, $contextA, $foreign->id);
    }

    public function test_journal_migration_is_additive_and_reversible_without_touching_finance_foundation(): void
    {
        $migration = require database_path(
            'migrations/2026_10_16_022600_create_enterprise_finance_journal_foundation.php',
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
     * @return array{EnterpriseFinanceAccount,EnterpriseFinanceAccount}
     */
    private function postingAccounts(
        User $actor,
        ResolvedTenantContext $context,
        string $prefix,
    ): array {
        $finance = app(FinanceFoundationService::class);
        $debit = $finance->createAccount($actor, $context, [
            'code' => '1-'.$prefix,
            'name' => $prefix.' Debit',
            'type' => 'asset',
            'normal_balance' => 'debit',
        ]);
        $credit = $finance->createAccount($actor, $context, [
            'code' => '4-'.$prefix,
            'name' => $prefix.' Credit',
            'type' => 'revenue',
            'normal_balance' => 'credit',
        ]);

        return [$debit, $credit];
    }

    private function openPeriod(
        User $actor,
        ResolvedTenantContext $context,
        string $prefix,
    ): EnterpriseFiscalPeriod {
        $finance = app(FinanceFoundationService::class);
        $year = $finance->createFiscalYear($actor, $context, [
            'code' => 'FY27-'.$prefix,
            'name' => 'FY '.$prefix,
            'starts_on' => '2027-01-01',
            'ends_on' => '2027-12-31',
        ]);

        return $finance->createFiscalPeriod($actor, $context, $year->id, [
            'sequence' => 1,
            'code' => 'P01-'.$prefix,
            'name' => 'January '.$prefix,
            'starts_on' => '2027-01-01',
            'ends_on' => '2027-01-31',
        ]);
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
                'finance.journal.read',
                'finance.journal.manage',
                'finance.journal.post',
            ],
            $actor,
        );
    }

    /**
     * @param  list<string>  $permissions
     * @return array{User,Store,ResolvedTenantContext}
     */
    private function scopedUser(array $permissions, string $storeCode): array
    {
        $user = User::factory()->create(['is_active' => true]);
        $store = $this->createRetailStore($storeCode);
        $this->attachPermissions($user, $store, $permissions, $storeCode);

        return [
            $user,
            $store,
            app(TenantContextResolver::class)->retail($user, $store->id),
        ];
    }

    /**
     * @param  list<string>  $permissions
     * @return array{User,Store,ResolvedTenantContext}
     */
    private function scopedUserInNewOrganization(array $permissions, string $storeCode): array
    {
        $user = User::factory()->create(['is_active' => true]);
        $store = $this->createRetailStore($storeCode, false);
        $this->assignStoreToNewOrganization($store);
        $this->attachPermissions($user, $store, $permissions, $storeCode);

        return [
            $user,
            $store,
            app(TenantContextResolver::class)->retail($user, $store->id),
        ];
    }

    /**
     * @param  list<string>  $permissions
     */
    private function userForStore(Store $store, array $permissions, string $code): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->attachPermissions($user, $store, $permissions, $code);

        return $user;
    }

    /**
     * @param  list<string>  $permissions
     */
    private function attachPermissions(User $user, Store $store, array $permissions, string $code): void
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

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
            'code' => 'finance_org_'.$store->id,
            'name' => 'Finance Journal Test Organization',
            'is_active' => true,
        ]);
        $company = EnterpriseCompany::query()->create([
            'organization_id' => $organization->id,
            'code' => 'finance_company_'.$store->id,
            'name' => 'Finance Journal Test Company',
            'is_active' => true,
        ]);
        $legalEntity = EnterpriseLegalEntity::query()->create([
            'company_id' => $company->id,
            'code' => 'finance_legal_'.$store->id,
            'name' => 'Finance Journal Test Legal Entity',
            'is_active' => true,
        ]);
        $businessUnit = EnterpriseBusinessUnit::query()->create([
            'legal_entity_id' => $legalEntity->id,
            'code' => 'finance_unit_'.$store->id,
            'name' => 'Finance Journal Test Unit',
            'is_active' => true,
        ]);

        app(OrganizationHierarchyService::class)->assignStore($store, $businessUnit);
    }
}
