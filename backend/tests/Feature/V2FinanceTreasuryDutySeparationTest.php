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
use App\Enterprise\Treasury\TreasuryReceiptService;
use App\Models\EnterpriseCashbox;
use App\Models\EnterpriseTreasuryReceipt;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\ResolvedTenantContext;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class V2FinanceTreasuryDutySeparationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_journal_requires_distinct_maker_checker_poster_and_cannot_create_treasury_receipt_without_entitlement(): void
    {
        $store = $this->store('V2-SOD-FINANCE');
        $maker = $this->actor($store, 'V2_SOD_GL_MAKER', ['finance.view', 'finance.manage', 'finance.post'], 'ar');
        $checker = $this->actor($store, 'V2_SOD_GL_CHECKER', ['finance.view', 'finance.approve', 'finance.post'], 'en');
        $poster = $this->actor($store, 'V2_SOD_GL_POSTER', ['finance.view', 'finance.post'], 'ar');

        $makerContext = $this->context($maker['user'], $store);
        $checkerContext = $this->context($checker['user'], $store);
        $posterContext = $this->context($poster['user'], $store);
        $this->enableFinance($maker['user'], $makerContext);

        $finance = app(FinanceFoundationService::class);
        $cash = $finance->createAccount($maker['user'], $makerContext, [
            'code' => '1100',
            'name' => 'Cash',
            'type' => 'asset',
            'normal_balance' => 'debit',
            'allow_posting' => true,
        ]);
        $revenue = $finance->createAccount($maker['user'], $makerContext, [
            'code' => '4000',
            'name' => 'Revenue',
            'type' => 'revenue',
            'normal_balance' => 'credit',
            'allow_posting' => true,
        ]);
        $year = $finance->createFiscalYear($maker['user'], $makerContext, [
            'code' => 'FY2027',
            'name' => 'Fiscal Year 2027',
            'starts_on' => '2027-01-01',
            'ends_on' => '2027-12-31',
        ]);
        $finance->createFiscalPeriod($maker['user'], $makerContext, $year->id, [
            'sequence' => 1,
            'code' => 'JAN',
            'name' => 'January 2027',
            'starts_on' => '2027-01-01',
            'ends_on' => '2027-01-31',
        ]);

        $approvals = app(FinanceApprovalService::class);
        $approvals->defineLimit($maker['user'], $makerContext, [
            'policy_key' => 'v2-sod-gl-checker-limit',
            'role_id' => $checker['role']->id,
            'action' => FinanceApprovalService::ACTION_GL_POST,
            'max_amount' => '250.000',
            'effective_from' => now()->subMinute(),
        ]);

        $gl = app(GeneralLedgerService::class);
        $journal = $gl->createDraft($maker['user'], $makerContext, [
            'idempotency_key' => 'v2-sod-journal',
            'entry_date' => '2027-01-12',
            'description' => 'Segregated finance journal',
            'lines' => [
                ['account_id' => $cash->id, 'debit' => '100.000', 'credit' => '0.000'],
                ['account_id' => $revenue->id, 'debit' => '0.000', 'credit' => '100.000'],
            ],
        ]);
        $request = $approvals->requestApproval(
            $maker['user'],
            $makerContext,
            FinanceApprovalService::ACTION_GL_POST,
            'enterprise_finance_journal',
            $journal->id,
            '100.000',
        );

        $this->assertValidationFailure('approval', fn () => $gl->post($poster['user'], $posterContext, $journal->id));
        $this->assertValidationFailure('approval_limit', fn () => $approvals->approve($maker['user'], $makerContext, $request->id));
        $approvals->approve($checker['user'], $checkerContext, $request->id);
        $this->assertValidationFailure('poster', fn () => $gl->post($maker['user'], $makerContext, $journal->id));
        $this->assertValidationFailure('poster', fn () => $gl->post($checker['user'], $checkerContext, $journal->id));

        $posted = $gl->post($poster['user'], $posterContext, $journal->id);
        $this->assertSame('posted', $posted->status);
        $this->assertSame($poster['user']->id, $posted->posted_by);
        $this->assertDatabaseCount('enterprise_finance_ledger_entries', 2);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.finance.journal_posted',
            'auditable_id' => $journal->id,
        ]);

        // Finance authority is not a Treasury entitlement.
        $this->assertForbidden(fn () => app(TreasuryReceiptService::class)->createDraft($maker['user'], $makerContext, []));
        $this->assertDatabaseCount('enterprise_treasury_receipts', 0);
    }

    public function test_treasury_receipt_needs_its_own_entitlement_and_an_independent_approver_without_creating_gl_entries(): void
    {
        $store = $this->store('V2-SOD-TREASURY');
        $maker = $this->actor($store, 'V2_SOD_TREASURY_MAKER', ['finance.view', 'finance.manage', 'finance.post'], 'en');
        $approver = $this->actor($store, 'V2_SOD_TREASURY_CHECKER', ['finance.view', 'finance.manage'], 'ar');
        $makerContext = $this->context($maker['user'], $store);
        $approverContext = $this->context($approver['user'], $store);
        $this->enableTreasury($maker['user'], $makerContext);

        $scope = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertNotNull($scope);
        $cashbox = EnterpriseCashbox::query()->create([
            'company_id' => $scope['company_id'],
            'store_id' => $store->id,
            'code' => 'V2-SOD-CASHBOX',
            'name' => 'Collection cashbox',
            'currency_code' => 'KWD',
            'operator_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'approval_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'is_active' => true,
        ]);

        $receiptService = app(TreasuryReceiptService::class);
        $receipt = $receiptService->createDraft($maker['user'], $makerContext, [
            'idempotency_key' => 'v2-sod-collection',
            'target_type' => EnterpriseTreasuryReceipt::TARGET_CASHBOX,
            'target_id' => $cashbox->id,
            'amount' => '25.000',
            'value_date' => '2026-11-10',
            'reference' => 'V2-SOD-RCPT',
        ]);

        $this->assertValidationFailure('approver', fn () => $receiptService->post($maker['user'], $makerContext, $receipt->id));
        $this->assertDatabaseHas('enterprise_treasury_receipts', [
            'id' => $receipt->id,
            'status' => EnterpriseTreasuryReceipt::STATUS_DRAFT,
        ]);

        $posted = $receiptService->post($approver['user'], $approverContext, $receipt->id);
        $this->assertSame(EnterpriseTreasuryReceipt::STATUS_POSTED, $posted->status);
        $this->assertSame($approver['user']->id, $posted->posted_by);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.treasury.receipt_posted',
            'auditable_id' => $receipt->id,
        ]);

        // A Treasury collection does not grant Finance GL posting.
        $this->assertFalse(app(ModuleRegistry::class)->isEnabled(FinanceFoundationService::MODULE));
        $this->assertForbidden(fn () => app(GeneralLedgerService::class)->createDraft($maker['user'], $makerContext, []));
        $this->assertDatabaseCount('enterprise_finance_journals', 0);
        $this->assertDatabaseCount('enterprise_finance_ledger_entries', 0);
    }

    private function store(string $code): Store
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

    /** @param list<string> $permissions
     *  @return array{user:User,role:Role}
     */
    private function actor(Store $store, string $roleCode, array $permissions, string $locale): array
    {
        $role = Role::query()->create([
            'code' => $roleCode,
            'name' => $roleCode,
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

        $user = User::factory()->create(['is_active' => true, 'locale' => $locale]);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['user' => $user, 'role' => $role];
    }

    private function context(User $actor, Store $store): ResolvedTenantContext
    {
        return app(TenantContextResolver::class)->retail($actor, $store->id);
    }

    private function enableFinance(User $actor, ResolvedTenantContext $context): void
    {
        $this->enableModule(FinanceFoundationService::MODULE, $actor);
        $scope = $context->enterpriseScope;
        $this->assertNotNull($scope);
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
                'finance.approvals.manage',
                'finance.approvals.approve',
            ],
            $actor,
        );
    }

    private function enableTreasury(User $actor, ResolvedTenantContext $context): void
    {
        $this->enableModule(TreasuryReceiptService::MODULE, $actor);
        $scope = $context->enterpriseScope;
        $this->assertNotNull($scope);
        app(ModuleEntitlementManager::class)->set(
            TreasuryReceiptService::MODULE,
            new EntitlementScope(EntitlementScope::STORE, $scope->storeId),
            true,
            [
                'treasury.receipts.read',
                'treasury.receipts.manage',
                'treasury.receipts.approve',
            ],
            $actor,
        );
    }

    private function enableModule(string $code, User $actor): void
    {
        $registry = app(ModuleRegistry::class);
        if ($registry->isEnabled($code)) {
            return;
        }
        $lifecycle = app(ModuleLifecycleManager::class);
        $lifecycle->transition($code, ModuleState::ENABLING, $actor);
        $lifecycle->transition($code, ModuleState::ENABLED, $actor);
    }

    private function assertValidationFailure(string $field, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected the action to be rejected by the finance/treasury SoD rule.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    private function assertForbidden(callable $operation): void
    {
        try {
            $operation();
            $this->fail('A disabled or unentitled module must reject the action.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }
}
