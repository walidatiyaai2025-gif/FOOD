<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Enterprise\Treasury\TreasuryReceiptService;
use App\Models\EnterpriseBankAccount;
use App\Models\EnterpriseBusinessUnit;
use App\Models\EnterpriseCashbox;
use App\Models\EnterpriseCompany;
use App\Models\EnterpriseLegalEntity;
use App\Models\EnterpriseOrganization;
use App\Models\EnterpriseTreasuryReceipt;
use App\Models\Permission;
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

class EnterpriseTreasuryReceiptFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_receipt_create_post_and_retries_are_idempotent_with_maker_approver_separation(): void
    {
        $store = $this->createRetailStore('TREASURY-RECEIPT-LIFECYCLE');
        $this->mapDefaultScope($store);
        $maker = $this->retailFinanceActor('receipt-maker@example.test', $store);
        $approver = $this->retailFinanceActor('receipt-approver@example.test', $store);
        $makerContext = $this->retailContext($maker, $store);
        $approverContext = $this->retailContext($approver, $store);
        $this->enableTreasury($maker, $makerContext);

        $cashbox = $this->cashbox(
            $store,
            EnterpriseCashbox::VISIBILITY_STORE,
            EnterpriseCashbox::VISIBILITY_STORE,
        );

        $payload = [
            'idempotency_key' => 'receipt-request-001',
            'target_type' => EnterpriseTreasuryReceipt::TARGET_CASHBOX,
            'target_id' => $cashbox->id,
            'amount' => '12.345',
            'value_date' => '2026-10-16',
            'reference' => 'DEP-2026-001',
            'description' => 'Counter receipt',
        ];

        $service = app(TreasuryReceiptService::class);
        $draft = $service->createDraft($maker, $makerContext, $payload);
        $draftRetry = $service->createDraft($maker, $makerContext, $payload);

        $this->assertSame($draft->id, $draftRetry->id);
        $this->assertSame(EnterpriseTreasuryReceipt::STATUS_DRAFT, $draft->status);
        $this->assertSame('12.345', $draft->amount);
        $this->assertSame('KWD', $draft->currency_code);
        $this->assertSame($store->id, $draft->store_id);
        $this->assertSame($maker->id, $draft->requested_by);
        $this->assertDatabaseCount('enterprise_treasury_receipts', 1);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.treasury.receipt_created',
            'auditable_id' => $draft->id,
        ]);

        try {
            $service->post($maker, $makerContext, $draft->id);
            $this->fail('The receipt operator must not approve the same receipt.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('approver', $exception->errors());
        }

        $posted = $service->post($approver, $approverContext, $draft->id);
        $postedRetry = $service->post($approver, $approverContext, $draft->id);

        $this->assertSame($draft->id, $posted->id);
        $this->assertSame($posted->id, $postedRetry->id);
        $this->assertSame(EnterpriseTreasuryReceipt::STATUS_POSTED, $posted->status);
        $this->assertSame($approver->id, $posted->posted_by);
        $this->assertNotNull($posted->posted_at);
        $this->assertDatabaseCount('enterprise_treasury_receipts', 1);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.treasury.receipt_posted',
            'auditable_id' => $draft->id,
        ]);

        $cashbox->forceFill(['is_active' => false])->save();

        $createRetryAfterPosting = $service->createDraft($maker, $makerContext, $payload);
        $postRetryAfterDeactivation = $service->post($approver, $approverContext, $draft->id);

        $this->assertSame(EnterpriseTreasuryReceipt::STATUS_POSTED, $createRetryAfterPosting->status);
        $this->assertSame(EnterpriseTreasuryReceipt::STATUS_POSTED, $postRetryAfterDeactivation->status);
        $this->assertDatabaseCount('enterprise_treasury_receipts', 1);

        try {
            $service->createDraft($maker, $makerContext, [
                ...$payload,
                'reference' => 'DIFFERENT-REQUEST',
            ]);
            $this->fail('An idempotency key cannot be reused for a different receipt request.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('idempotency_key', $exception->errors());
        }

        $this->expectException(ValidationException::class);
        $service->cancel($maker, $makerContext, $draft->id);
    }

    public function test_targets_must_be_active_and_respect_operator_and_approval_visibility(): void
    {
        $storeA = $this->createRetailStore('TREASURY-RECEIPT-A');
        $storeB = $this->createRetailStore('TREASURY-RECEIPT-B');
        $this->mapDefaultScope($storeA);
        $this->mapDefaultScope($storeB);

        $maker = $this->retailFinanceActor('receipt-target-maker@example.test', $storeA);
        $approver = $this->retailFinanceActor('receipt-target-approver@example.test', $storeA);
        $makerContext = $this->retailContext($maker, $storeA);
        $approverContext = $this->retailContext($approver, $storeA);
        $this->enableTreasury($maker, $makerContext);

        $companyBank = $this->bankAccount(
            $storeB,
            EnterpriseBankAccount::VISIBILITY_COMPANY,
            EnterpriseBankAccount::VISIBILITY_COMPANY,
        );

        $service = app(TreasuryReceiptService::class);
        $receipt = $service->createDraft($maker, $makerContext, [
            'idempotency_key' => 'company-bank-visible',
            'target_type' => EnterpriseTreasuryReceipt::TARGET_BANK_ACCOUNT,
            'target_id' => $companyBank->id,
            'amount' => '55.000',
            'value_date' => '2026-10-16',
            'reference' => 'BANK-COMPANY-001',
        ]);

        $this->assertSame($storeA->id, $receipt->store_id);
        $this->assertSame($companyBank->id, $receipt->target_id);
        $this->assertSame('KWD', $receipt->currency_code);
        $this->assertSame(
            EnterpriseTreasuryReceipt::STATUS_POSTED,
            $service->post($approver, $approverContext, $receipt->id)->status,
        );

        $storeOnlyBank = $this->bankAccount(
            $storeB,
            EnterpriseBankAccount::VISIBILITY_STORE,
            EnterpriseBankAccount::VISIBILITY_STORE,
            'BANK-STORE-ONLY',
        );

        try {
            $service->createDraft($maker, $makerContext, [
                'idempotency_key' => 'store-bank-hidden',
                'target_type' => EnterpriseTreasuryReceipt::TARGET_BANK_ACCOUNT,
                'target_id' => $storeOnlyBank->id,
                'amount' => '1.000',
                'value_date' => '2026-10-16',
                'reference' => 'BANK-STORE-ONLY-001',
            ]);
            $this->fail('A store-only bank account from another location must be rejected.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }

        $inactiveCashbox = $this->cashbox(
            $storeA,
            EnterpriseCashbox::VISIBILITY_STORE,
            EnterpriseCashbox::VISIBILITY_STORE,
            false,
            'CASH-INACTIVE',
        );

        try {
            $service->createDraft($maker, $makerContext, [
                'idempotency_key' => 'inactive-cashbox',
                'target_type' => EnterpriseTreasuryReceipt::TARGET_CASHBOX,
                'target_id' => $inactiveCashbox->id,
                'amount' => '2.000',
                'value_date' => '2026-10-16',
                'reference' => 'INACTIVE-001',
            ]);
            $this->fail('Inactive receipt targets must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('target_id', $exception->errors());
        }

        $approvalTarget = $this->cashbox(
            $storeA,
            EnterpriseCashbox::VISIBILITY_STORE,
            EnterpriseCashbox::VISIBILITY_STORE,
            true,
            'CASH-APPROVAL',
        );
        $draft = $service->createDraft($maker, $makerContext, [
            'idempotency_key' => 'deactivate-before-post',
            'target_type' => EnterpriseTreasuryReceipt::TARGET_CASHBOX,
            'target_id' => $approvalTarget->id,
            'amount' => '3.000',
            'value_date' => '2026-10-16',
            'reference' => 'DEACTIVATE-001',
        ]);
        $approvalTarget->forceFill(['is_active' => false])->save();

        try {
            $service->post($approver, $approverContext, $draft->id);
            $this->fail('Posting must re-check active target authority.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('target_id', $exception->errors());
        }
    }

    public function test_receipts_are_tenant_location_and_rbac_isolated_and_listing_is_bounded(): void
    {
        $storeA = $this->createRetailStore('TREASURY-ISOLATION-A');
        $storeB = $this->createRetailStore('TREASURY-ISOLATION-B');
        $this->mapDefaultScope($storeA);
        $this->mapSeparateOrganization($storeB);

        $actorA = $this->retailFinanceActor('receipt-isolation-a@example.test', $storeA);
        $actorB = $this->retailFinanceActor('receipt-isolation-b@example.test', $storeB);
        $contextA = $this->retailContext($actorA, $storeA);
        $contextB = $this->retailContext($actorB, $storeB);
        $this->enableTreasury($actorA, $contextA);
        $this->entitleTreasury($actorB, $contextB);

        $cashboxA = $this->cashbox(
            $storeA,
            EnterpriseCashbox::VISIBILITY_STORE,
            EnterpriseCashbox::VISIBILITY_STORE,
        );

        $service = app(TreasuryReceiptService::class);
        $receipt = $service->createDraft($actorA, $contextA, [
            'idempotency_key' => 'tenant-a-only',
            'target_type' => EnterpriseTreasuryReceipt::TARGET_CASHBOX,
            'target_id' => $cashboxA->id,
            'amount' => '5.000',
            'value_date' => '2026-10-16',
            'reference' => 'TENANT-A-001',
        ]);

        $this->assertSame(0, $service->paginateReceipts($actorB, $contextB)->total());

        try {
            $service->post($actorB, $contextB, $receipt->id);
            $this->fail('A receipt from another tenant/location must not be addressable.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }

        $viewOnly = $this->actorWithStorePermissions(
            'receipt-view-only@example.test',
            $storeA,
            ['finance.view'],
            'TREASURY_RECEIPT_VIEW_ONLY',
        );
        $viewContext = $this->retailContext($viewOnly, $storeA);
        $this->assertSame(1, app(TreasuryReceiptService::class)->paginateReceipts(
            $viewOnly,
            $viewContext,
        )->total());

        try {
            app(TreasuryReceiptService::class)->createDraft($viewOnly, $viewContext, [
                'idempotency_key' => 'view-only-denied',
                'target_type' => EnterpriseTreasuryReceipt::TARGET_CASHBOX,
                'target_id' => $cashboxA->id,
                'amount' => '1.000',
                'value_date' => '2026-10-16',
                'reference' => 'VIEW-ONLY-001',
            ]);
            $this->fail('Read-only finance actors must not create receipts.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $scopeA = $contextA->enterpriseScope;
        $this->assertNotNull($scopeA);
        $now = now();
        $rows = [];
        for ($index = 1; $index <= 105; $index++) {
            $rows[] = [
                'organization_id' => $scopeA->organizationId,
                'company_id' => $scopeA->companyId,
                'store_id' => $scopeA->storeId,
                'receipt_number' => sprintf('RCPT-BULK-%03d', $index),
                'idempotency_key' => sprintf('bulk-receipt-%03d', $index),
                'request_hash' => hash('sha256', 'bulk-receipt-'.$index),
                'target_type' => EnterpriseTreasuryReceipt::TARGET_CASHBOX,
                'target_id' => $cashboxA->id,
                'amount' => '1.000',
                'currency_code' => 'KWD',
                'value_date' => '2026-10-16',
                'reference' => sprintf('BULK-REF-%03d', $index),
                'description' => null,
                'status' => EnterpriseTreasuryReceipt::STATUS_DRAFT,
                'requested_by' => $actorA->id,
                'posted_at' => null,
                'posted_by' => null,
                'cancelled_at' => null,
                'cancelled_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 50) as $chunk) {
            DB::table('enterprise_treasury_receipts')->insert($chunk);
        }

        $page = $service->paginateReceipts($actorA, $contextA, [], 500);
        $this->assertSame(100, $page->perPage());
        $this->assertSame(106, $page->total());
        $this->assertCount(100, $page->items());

        $search = $service->paginateReceipts($actorA, $contextA, ['q' => 'BULK-REF-10'], 100);
        $this->assertSame(6, $search->total());
    }

    public function test_disabled_or_unentitled_treasury_performs_no_receipt_query_work(): void
    {
        $store = $this->createRetailStore('TREASURY-DISABLED');
        $this->mapDefaultScope($store);
        $actor = $this->retailFinanceActor('receipt-disabled@example.test', $store);
        $context = $this->retailContext($actor, $store);

        $queries = [];
        DB::listen(static function ($event) use (&$queries): void {
            $queries[] = strtolower((string) $event->sql);
        });

        try {
            app(TreasuryReceiptService::class)->paginateReceipts($actor, $context);
            $this->fail('Disabled Treasury must deny receipt reads.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertFalse(collect($queries)->contains(
            static fn (string $sql): bool => str_contains($sql, 'enterprise_treasury_receipts'),
        ));

        $lifecycle = app(ModuleLifecycleManager::class);
        $lifecycle->transition(TreasuryReceiptService::MODULE, ModuleState::ENABLING, $actor);
        $lifecycle->transition(TreasuryReceiptService::MODULE, ModuleState::ENABLED, $actor);

        $queries = [];

        try {
            app(TreasuryReceiptService::class)->paginateReceipts($actor, $context);
            $this->fail('Unentitled Treasury must deny receipt reads.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertFalse(collect($queries)->contains(
            static fn (string $sql): bool => str_contains($sql, 'enterprise_treasury_receipts'),
        ));
    }

    public function test_draft_cancel_is_retry_safe_and_receipt_schema_is_additive_and_reversible(): void
    {
        $store = $this->createRetailStore('TREASURY-CANCEL');
        $this->mapDefaultScope($store);
        $actor = $this->retailFinanceActor('receipt-cancel@example.test', $store);
        $context = $this->retailContext($actor, $store);
        $this->enableTreasury($actor, $context);

        $cashbox = $this->cashbox(
            $store,
            EnterpriseCashbox::VISIBILITY_STORE,
            EnterpriseCashbox::VISIBILITY_STORE,
        );

        $service = app(TreasuryReceiptService::class);
        $draft = $service->createDraft($actor, $context, [
            'idempotency_key' => 'cancel-retry',
            'target_type' => EnterpriseTreasuryReceipt::TARGET_CASHBOX,
            'target_id' => $cashbox->id,
            'amount' => '7.500',
            'value_date' => '2026-10-16',
            'reference' => 'CANCEL-001',
        ]);
        $cancelled = $service->cancel($actor, $context, $draft->id);
        $cancelRetry = $service->cancel($actor, $context, $draft->id);

        $this->assertSame($cancelled->id, $cancelRetry->id);
        $this->assertSame(EnterpriseTreasuryReceipt::STATUS_CANCELLED, $cancelRetry->status);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.treasury.receipt_cancelled',
            'auditable_id' => $draft->id,
        ]);

        $migration = require database_path(
            'migrations/2026_10_16_071900_create_enterprise_treasury_receipts.php',
        );

        $migration->down();

        $this->assertFalse(Schema::hasTable('enterprise_treasury_receipts'));
        $this->assertTrue(Schema::hasTable('enterprise_cashboxes'));
        $this->assertTrue(Schema::hasTable('enterprise_bank_accounts'));

        $migration->up();

        $this->assertTrue(Schema::hasTable('enterprise_treasury_receipts'));
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

    private function mapDefaultScope(Store $store): void
    {
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);
    }

    private function mapSeparateOrganization(Store $store): void
    {
        $organization = EnterpriseOrganization::query()->create([
            'code' => 'treasury_org_'.$store->id,
            'name' => 'Treasury Organization '.$store->id,
            'is_active' => true,
        ]);
        $company = EnterpriseCompany::query()->create([
            'organization_id' => $organization->id,
            'code' => 'treasury_company_'.$store->id,
            'name' => 'Treasury Company '.$store->id,
            'is_active' => true,
        ]);
        $legalEntity = EnterpriseLegalEntity::query()->create([
            'company_id' => $company->id,
            'code' => 'treasury_legal_'.$store->id,
            'name' => 'Treasury Legal Entity '.$store->id,
            'is_active' => true,
        ]);
        $businessUnit = EnterpriseBusinessUnit::query()->create([
            'legal_entity_id' => $legalEntity->id,
            'code' => 'treasury_unit_'.$store->id,
            'name' => 'Treasury Unit '.$store->id,
            'is_active' => true,
        ]);

        app(OrganizationHierarchyService::class)->assignStore($store, $businessUnit);
    }

    private function retailFinanceActor(string $email, Store $store): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'is_active' => true,
        ]);
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

    /**
     * @param  list<string>  $permissions
     */
    private function actorWithStorePermissions(
        string $email,
        Store $store,
        array $permissions,
        string $roleCode,
    ): User {
        $role = Role::query()->create([
            'code' => $roleCode,
            'name' => $roleCode,
            'scope' => 'store',
            'is_active' => true,
            'is_system' => false,
        ]);

        foreach ($permissions as $permissionCode) {
            $permission = Permission::query()->where('code', $permissionCode)->firstOrFail();
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user = User::factory()->create([
            'email' => $email,
            'is_active' => true,
        ]);

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function retailContext(User $actor, Store $store): ResolvedTenantContext
    {
        return app(TenantContextResolver::class)->retail($actor, $store->id);
    }

    private function enableTreasury(User $actor, ResolvedTenantContext $context): void
    {
        $registry = app(ModuleRegistry::class);
        if (! $registry->isEnabled(TreasuryReceiptService::MODULE)) {
            $lifecycle = app(ModuleLifecycleManager::class);
            $lifecycle->transition(TreasuryReceiptService::MODULE, ModuleState::ENABLING, $actor);
            $lifecycle->transition(TreasuryReceiptService::MODULE, ModuleState::ENABLED, $actor);
        }

        $this->entitleTreasury($actor, $context);
    }

    private function entitleTreasury(User $actor, ResolvedTenantContext $context): void
    {
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

    private function cashbox(
        Store $store,
        string $operatorVisibility,
        string $approvalVisibility,
        bool $active = true,
        string $code = 'CASH-01',
    ): EnterpriseCashbox {
        $scope = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertNotNull($scope);

        return EnterpriseCashbox::query()->create([
            'company_id' => $scope['company_id'],
            'store_id' => $store->id,
            'code' => $code,
            'name' => $code,
            'currency_code' => 'KWD',
            'operator_visibility' => $operatorVisibility,
            'approval_visibility' => $approvalVisibility,
            'is_active' => $active,
        ]);
    }

    private function bankAccount(
        Store $store,
        string $operatorVisibility,
        string $approvalVisibility,
        string $code = 'BANK-01',
    ): EnterpriseBankAccount {
        $scope = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertNotNull($scope);

        return EnterpriseBankAccount::query()->create([
            'company_id' => $scope['company_id'],
            'store_id' => $store->id,
            'code' => $code,
            'name' => $code,
            'bank_name' => 'Treasury Bank',
            'account_name' => 'FOODEX Treasury',
            'account_reference' => 'KW00TEST1234',
            'account_reference_last4' => '1234',
            'currency_code' => 'KWD',
            'operator_visibility' => $operatorVisibility,
            'approval_visibility' => $approvalVisibility,
            'is_active' => true,
        ]);
    }
}
