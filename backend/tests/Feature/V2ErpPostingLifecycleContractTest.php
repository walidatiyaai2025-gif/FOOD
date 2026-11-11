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
use Tests\TestCase;

/**
 * V2 #2843 / P109: one real, scoped Finance journal flows through maker,
 * independent checker, independent poster, read-only ledger and audit.
 * This does not certify a reversal operation (not implemented by the GL service).
 */
final class V2ErpPostingLifecycleContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
        config()->set(
            'enterprise_module_readiness.certified_versions.finance',
            app(ModuleRegistry::class)->manifest(FinanceFoundationService::MODULE)->version,
        );
    }

    public function test_segregated_journal_can_be_approved_posted_reported_and_audited_without_duplicate_entries(): void
    {
        $store = $this->store('V2-P109-ERP');
        $maker = $this->actor($store, 'V2_P109_MAKER', ['finance.view', 'finance.manage'], 'ar');
        $checker = $this->actor($store, 'V2_P109_CHECKER', ['finance.view', 'finance.approve', 'finance.post'], 'en');
        $poster = $this->actor($store, 'V2_P109_POSTER', ['finance.view', 'finance.post'], 'en');
        $reader = $this->actor($store, 'V2_P109_READER', ['finance.view'], 'en');

        $makerContext = $this->context($maker['user'], $store);
        $checkerContext = $this->context($checker['user'], $store);
        $posterContext = $this->context($poster['user'], $store);
        $readerContext = $this->context($reader['user'], $store);
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
            'name' => 'Fiscal 2027',
            'starts_on' => '2027-01-01',
            'ends_on' => '2027-12-31',
        ]);
        $finance->createFiscalPeriod($maker['user'], $makerContext, $year->id, [
            'sequence' => 1,
            'code' => 'JAN',
            'name' => 'January',
            'starts_on' => '2027-01-01',
            'ends_on' => '2027-01-31',
        ]);

        $approval = app(FinanceApprovalService::class);
        $approval->defineLimit($maker['user'], $makerContext, [
            'policy_key' => 'v2-p109-independent-check',
            'role_id' => $checker['role']->id,
            'action' => FinanceApprovalService::ACTION_GL_POST,
            'max_amount' => '250.000',
            'effective_from' => now()->subMinutes(2),
        ]);

        $ledger = app(GeneralLedgerService::class);
        $draft = $ledger->createDraft($maker['user'], $makerContext, [
            'idempotency_key' => 'v2-p109-approved-journal',
            'entry_date' => '2027-01-15',
            'description' => 'V2 P109 review-to-post proof',
            'lines' => [
                ['account_id' => $cash->id, 'debit' => '25.500', 'credit' => '0'],
                ['account_id' => $revenue->id, 'debit' => '0', 'credit' => '25.500'],
            ],
        ]);
        $request = $approval->requestApproval(
            $maker['user'],
            $makerContext,
            FinanceApprovalService::ACTION_GL_POST,
            'enterprise_finance_journal',
            $draft->id,
            '25.500',
        );

        $this->assertSame('draft', $draft->status);
        $this->assertDatabaseCount('enterprise_finance_ledger_entries', 0);
        $this->assertValidationError('approval', fn () => $ledger->post(
            $poster['user'],
            $posterContext,
            $draft->id,
        ));
        $this->assertValidationError('approval_limit', fn () => $approval->approve(
            $maker['user'],
            $makerContext,
            $request->id,
        ));

        $approval->approve($checker['user'], $checkerContext, $request->id);
        $this->assertValidationError('poster', fn () => $ledger->post(
            $checker['user'],
            $checkerContext,
            $draft->id,
        ));

        $posted = $ledger->post($poster['user'], $posterContext, $draft->id);
        $this->assertSame('posted', $posted->status);
        $this->assertSame($poster['user']->id, $posted->posted_by);
        $this->assertSame($posted->id, $ledger->post($poster['user'], $posterContext, $draft->id)->id);

        $this->assertDatabaseCount('enterprise_finance_ledger_entries', 2);
        $this->assertSame(1, $ledger->paginateJournals($reader['user'], $readerContext)->total());
        $this->assertSame(2, $ledger->paginateLedger($reader['user'], $readerContext)->total());

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.finance.journal_created',
            'auditable_id' => $draft->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.workflow.decision_recorded',
            'auditable_id' => $request->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.finance.journal_posted',
            'auditable_id' => $draft->id,
        ]);

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $reader['user']->forceFill(['locale' => $locale])->save();

            $this->actingAs($reader['user']->fresh())
                ->get(route('admin.erp.finance.index', [
                    'store_id' => $store->id,
                    'tab' => 'journals',
                ]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee($posted->journal_number);
        }

        $foreign = $this->store('V2-P109-FOREIGN');
        $this->actingAs($reader['user']->fresh())
            ->get(route('admin.erp.finance.index', [
                'store_id' => $foreign->id,
                'tab' => 'journals',
            ]))
            ->assertNotFound();
    }

    public function test_disabled_finance_lifecycle_cannot_expose_reports_to_an_authorized_role(): void
    {
        $store = $this->store('V2-P109-DISABLED');
        $viewer = $this->actor($store, 'V2_P109_DISABLED_VIEWER', ['finance.view'], 'ar');

        $this->actingAs($viewer['user'])
            ->get(route('admin.erp.finance.index', [
                'store_id' => $store->id,
                'tab' => 'journals',
            ]))
            ->assertForbidden();

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

    /**
     * @param  list<string>  $permissions
     * @return array{user:User,role:Role}
     */
    private function actor(Store $store, string $code, array $permissions, string $locale): array
    {
        $role = Role::query()->create([
            'code' => $code,
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
        $lifecycle = app(ModuleLifecycleManager::class);
        $lifecycle->transition(FinanceFoundationService::MODULE, ModuleState::ENABLING, $actor);
        $lifecycle->transition(FinanceFoundationService::MODULE, ModuleState::ENABLED, $actor);

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

    private function assertValidationError(string $field, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected ERP lifecycle separation to reject this action.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }
}
