<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Finance\FinanceFoundationService;
use App\Enterprise\Finance\OpeningBalanceService;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\EnterpriseBusinessUnit;
use App\Models\EnterpriseCompany;
use App\Models\EnterpriseLegalEntity;
use App\Models\EnterpriseOrganization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\ResolvedTenantContext;
use App\Support\TenantContextResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class EnterpriseOpeningBalanceFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_opening_balance_posts_once_through_canonical_general_ledger(): void
    {
        $fixture = $this->fixture('OB-IDEMPOTENT');
        $service = app(OpeningBalanceService::class);

        $payload = [
            'idempotency_key' => 'opening-001',
            'fiscal_period_id' => $fixture['period_id'],
            'entry_date' => '2027-01-01',
            'description' => 'Opening balances',
            'lines' => [
                [
                    'account_id' => $fixture['cash_account_id'],
                    'cost_center_id' => $fixture['cost_center_id'],
                    'debit' => '25.125',
                    'credit' => '0',
                    'memo' => 'Opening cash',
                ],
                [
                    'account_id' => $fixture['equity_account_id'],
                    'debit' => '0',
                    'credit' => '25.125',
                    'memo' => 'Opening equity',
                ],
            ],
        ];

        $draft = $service->createDraft(
            $fixture['maker'],
            $fixture['maker_context'],
            $payload,
        );
        $retry = $service->createDraft(
            $fixture['maker'],
            $fixture['maker_context'],
            $payload,
        );

        $this->assertSame($draft->id, $retry->id);
        $this->assertSame('draft', $draft->status);
        $this->assertSame('25.125', $draft->total_debit);
        $this->assertSame('25.125', $draft->total_credit);
        $this->assertSame($fixture['cost_center_id'], $draft->lines->first()?->cost_center_id);
        $this->assertDatabaseCount('enterprise_finance_opening_balance_batches', 1);
        $this->assertDatabaseCount('enterprise_finance_opening_balance_lines', 2);
        $this->assertDatabaseCount('enterprise_finance_journals', 0);
        $this->assertDatabaseCount('enterprise_finance_ledger_entries', 0);

        $posted = $service->post(
            $fixture['poster'],
            $fixture['poster_context'],
            $draft->id,
        );
        $postedRetry = $service->post(
            $fixture['poster'],
            $fixture['poster_context'],
            $draft->id,
        );

        $this->assertSame('posted', $posted->status);
        $this->assertSame($posted->id, $postedRetry->id);
        $this->assertNotNull($posted->journal_id);
        $this->assertSame($fixture['poster']->id, $posted->posted_by);
        $this->assertDatabaseCount('enterprise_finance_journals', 1);
        $this->assertDatabaseCount('enterprise_finance_journal_lines', 2);
        $this->assertDatabaseCount('enterprise_finance_ledger_entries', 2);
        $this->assertDatabaseHas('enterprise_finance_journals', [
            'id' => $posted->journal_id,
            'status' => 'posted',
            'fiscal_period_id' => $fixture['period_id'],
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.finance.opening_balance_created',
            'auditable_id' => $draft->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.finance.opening_balance_posted',
            'auditable_id' => $draft->id,
        ]);
    }

    public function test_opening_balance_rejects_unbalanced_closed_period_and_invalid_scoped_references(): void
    {
        $local = $this->fixture('OB-VALIDATION');
        $foreign = $this->fixture('OB-FOREIGN', true);
        $service = app(OpeningBalanceService::class);

        try {
            $service->createDraft(
                $local['maker'],
                $local['maker_context'],
                [
                    'idempotency_key' => 'unbalanced',
                    'fiscal_period_id' => $local['period_id'],
                    'entry_date' => '2027-01-01',
                    'lines' => [
                        ['account_id' => $local['cash_account_id'], 'debit' => '10.000'],
                        ['account_id' => $local['equity_account_id'], 'credit' => '9.000'],
                    ],
                ],
            );
            $this->fail('Unbalanced opening-balance batches must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines', $exception->errors());
        }

        try {
            $service->createDraft(
                $local['maker'],
                $local['maker_context'],
                [
                    'idempotency_key' => 'foreign-account',
                    'fiscal_period_id' => $local['period_id'],
                    'entry_date' => '2027-01-01',
                    'lines' => [
                        ['account_id' => $foreign['cash_account_id'], 'debit' => '10.000'],
                        ['account_id' => $local['equity_account_id'], 'credit' => '10.000'],
                    ],
                ],
            );
            $this->fail('Cross-tenant accounts must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines', $exception->errors());
        }

        try {
            $service->createDraft(
                $local['maker'],
                $local['maker_context'],
                [
                    'idempotency_key' => 'foreign-cost-center',
                    'fiscal_period_id' => $local['period_id'],
                    'entry_date' => '2027-01-01',
                    'lines' => [
                        [
                            'account_id' => $local['cash_account_id'],
                            'cost_center_id' => $foreign['cost_center_id'],
                            'debit' => '10.000',
                        ],
                        ['account_id' => $local['equity_account_id'], 'credit' => '10.000'],
                    ],
                ],
            );
            $this->fail('Cross-tenant cost centers must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines', $exception->errors());
        }

        app(FinanceFoundationService::class)->closeFiscalPeriod(
            $local['maker'],
            $local['maker_context'],
            $local['period_id'],
        );

        try {
            $service->createDraft(
                $local['maker'],
                $local['maker_context'],
                [
                    'idempotency_key' => 'closed-period',
                    'fiscal_period_id' => $local['period_id'],
                    'entry_date' => '2027-01-01',
                    'lines' => [
                        ['account_id' => $local['cash_account_id'], 'debit' => '10.000'],
                        ['account_id' => $local['equity_account_id'], 'credit' => '10.000'],
                    ],
                ],
            );
            $this->fail('Closed periods must reject opening-balance batches.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('fiscal_period_id', $exception->errors());
        }
    }

    public function test_opening_balance_revalidates_period_and_enforces_safe_lifecycle(): void
    {
        $fixture = $this->fixture('OB-LIFECYCLE');
        $service = app(OpeningBalanceService::class);

        $cancelled = $service->createDraft(
            $fixture['maker'],
            $fixture['maker_context'],
            $this->payload($fixture, 'cancel-me'),
        );
        $cancelled = $service->cancel(
            $fixture['maker'],
            $fixture['maker_context'],
            $cancelled->id,
        );
        $cancelledRetry = $service->cancel(
            $fixture['maker'],
            $fixture['maker_context'],
            $cancelled->id,
        );

        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame($cancelled->id, $cancelledRetry->id);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.finance.opening_balance_cancelled',
            'auditable_id' => $cancelled->id,
        ]);

        try {
            $service->post(
                $fixture['poster'],
                $fixture['poster_context'],
                $cancelled->id,
            );
            $this->fail('Cancelled opening-balance batches must not post.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $draft = $service->createDraft(
            $fixture['maker'],
            $fixture['maker_context'],
            $this->payload($fixture, 'close-after-draft'),
        );

        try {
            app(FinanceFoundationService::class)->closeFiscalPeriod(
                $fixture['maker'],
                $fixture['maker_context'],
                $fixture['period_id'],
            );
            $this->fail('Period close must be blocked while an opening-balance draft is unposted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('fiscal_period', $exception->errors());
            $this->assertStringContainsString(
                'draft_opening_balances',
                implode(' ', $exception->errors()['fiscal_period']),
            );
        }

        $this->assertSame(
            'open',
            DB::table('enterprise_fiscal_periods')->where('id', $fixture['period_id'])->value('status'),
        );

        // Simulate an external/concurrent period-state change to prove posting still revalidates
        // the authoritative fiscal period rather than trusting the state captured at draft time.
        DB::table('enterprise_fiscal_periods')->where('id', $fixture['period_id'])->update([
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => $fixture['maker']->id,
            'updated_at' => now(),
        ]);

        try {
            $service->post(
                $fixture['poster'],
                $fixture['poster_context'],
                $draft->id,
            );
            $this->fail('A period closed after draft creation must still block posting.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('fiscal_period_id', $exception->errors());
        }

        $this->assertSame(
            'draft',
            DB::table('enterprise_finance_opening_balance_batches')->where('id', $draft->id)->value('status'),
        );
        $this->assertDatabaseCount('enterprise_finance_ledger_entries', 0);
    }

    public function test_opening_balance_reads_are_bounded_and_scope_filtered(): void
    {
        $fixture = $this->fixture('OB-BOUNDED');
        $scope = $fixture['maker_context']->enterpriseScope;
        $this->assertNotNull($scope);
        $now = now();

        $rows = [];
        for ($index = 1; $index <= 105; $index++) {
            $rows[] = [
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId,
                'legal_entity_id' => $scope->legalEntityId,
                'fiscal_period_id' => $fixture['period_id'],
                'batch_number' => sprintf('OB-BULK-%03d', $index),
                'idempotency_key' => sprintf('ob-bulk-%03d', $index),
                'request_hash' => hash('sha256', 'ob-bulk-'.$index),
                'entry_date' => '2027-01-01',
                'description' => 'Bounded fixture',
                'status' => 'draft',
                'total_debit' => 1,
                'total_credit' => 1,
                'created_by' => $fixture['maker']->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 50) as $chunk) {
            DB::table('enterprise_finance_opening_balance_batches')->insert($chunk);
        }

        $page = app(OpeningBalanceService::class)->paginate(
            $fixture['maker'],
            $fixture['maker_context'],
            ['status' => 'draft'],
            500,
        );

        $this->assertSame(100, $page->perPage());
        $this->assertSame(105, $page->total());
        $this->assertCount(100, $page->items());

        $foreign = $this->fixture('OB-BOUNDED-FOREIGN', true);
        app(OpeningBalanceService::class)->createDraft(
            $foreign['maker'],
            $foreign['maker_context'],
            $this->payload($foreign, 'foreign-hidden'),
        );

        $this->assertSame(
            105,
            app(OpeningBalanceService::class)->paginate(
                $fixture['maker'],
                $fixture['maker_context'],
            )->total(),
        );
    }

    public function test_unentitled_finance_performs_no_opening_balance_table_query_work(): void
    {
        $store = $this->createRetailStore('OB-NO-ENT');
        $viewer = $this->userForStore($store, ['finance.view'], 'OB-NO-ENT-VIEWER');
        $context = app(TenantContextResolver::class)->retail($viewer, $store->id);
        $queries = [];

        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        try {
            app(OpeningBalanceService::class)->paginate($viewer, $context);
            $this->fail('Opening-balance reads require Finance entitlement.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertFalse(collect($queries)->contains(
            static fn (string $sql): bool => str_contains($sql, 'enterprise_finance_opening_balance_batches')
                || str_contains($sql, 'enterprise_finance_opening_balance_lines'),
        ));
    }

    public function test_opening_balance_migration_is_additive_and_reversible(): void
    {
        $migration = require database_path(
            'migrations/2026_10_16_042000_create_enterprise_opening_balance_foundation.php',
        );

        $migration->down();

        $this->assertFalse(Schema::hasTable('enterprise_finance_opening_balance_lines'));
        $this->assertFalse(Schema::hasTable('enterprise_finance_opening_balance_batches'));
        $this->assertTrue(Schema::hasTable('enterprise_finance_journals'));
        $this->assertTrue(Schema::hasTable('enterprise_cost_centers'));

        $migration->up();

        $this->assertTrue(Schema::hasTable('enterprise_finance_opening_balance_batches'));
        $this->assertTrue(Schema::hasTable('enterprise_finance_opening_balance_lines'));
    }

    /**
     * @param  array<string, mixed>  $fixture
     * @return array<string, mixed>
     */
    private function payload(array $fixture, string $key): array
    {
        return [
            'idempotency_key' => $key,
            'fiscal_period_id' => $fixture['period_id'],
            'entry_date' => '2027-01-01',
            'lines' => [
                [
                    'account_id' => $fixture['cash_account_id'],
                    'cost_center_id' => $fixture['cost_center_id'],
                    'debit' => '5.000',
                ],
                [
                    'account_id' => $fixture['equity_account_id'],
                    'credit' => '5.000',
                ],
            ],
        ];
    }

    /**
     * @return array{
     *   maker:User,
     *   poster:User,
     *   maker_context:ResolvedTenantContext,
     *   poster_context:ResolvedTenantContext,
     *   cash_account_id:int,
     *   equity_account_id:int,
     *   cost_center_id:int,
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
        $asset = $finance->createAccount($maker, $makerContext, [
            'code' => '1000',
            'name' => 'Assets',
            'type' => 'asset',
            'normal_balance' => 'debit',
            'is_control' => true,
        ]);
        $cash = $finance->createAccount($maker, $makerContext, [
            'parent_id' => $asset->id,
            'code' => '1100',
            'name' => 'Cash',
            'type' => 'asset',
            'normal_balance' => 'debit',
            'allow_posting' => true,
        ]);
        $equity = $finance->createAccount($maker, $makerContext, [
            'code' => '3000',
            'name' => 'Opening Equity',
            'type' => 'equity',
            'normal_balance' => 'credit',
            'allow_posting' => true,
        ]);
        $costCenter = $finance->createCostCenter($maker, $makerContext, [
            'code' => 'OPENING',
            'name' => 'Opening Balance',
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
            'cash_account_id' => $cash->id,
            'equity_account_id' => $equity->id,
            'cost_center_id' => $costCenter->id,
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
                'finance.opening_balances.read',
                'finance.opening_balances.manage',
                'finance.opening_balances.post',
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
            'code' => 'ob_org_'.$store->id,
            'name' => 'Opening Balance Test Organization',
            'is_active' => true,
        ]);
        $company = EnterpriseCompany::query()->create([
            'organization_id' => $organization->id,
            'code' => 'ob_company_'.$store->id,
            'name' => 'Opening Balance Test Company',
            'is_active' => true,
        ]);
        $legalEntity = EnterpriseLegalEntity::query()->create([
            'company_id' => $company->id,
            'code' => 'ob_legal_'.$store->id,
            'name' => 'Opening Balance Test Legal Entity',
            'is_active' => true,
        ]);
        $businessUnit = EnterpriseBusinessUnit::query()->create([
            'legal_entity_id' => $legalEntity->id,
            'code' => 'ob_unit_'.$store->id,
            'name' => 'Opening Balance Test Unit',
            'is_active' => true,
        ]);

        app(OrganizationHierarchyService::class)->assignStore($store, $businessUnit);
    }
}
