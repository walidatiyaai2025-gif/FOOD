<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Finance\AccountsReceivableService;
use App\Enterprise\Finance\FinanceCreditTermsService;
use App\Enterprise\Finance\FinanceFoundationService;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Customer;
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
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class FinanceCreditTermsFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_customer_credit_snapshot_is_derived_from_posted_ar_exposure(): void
    {
        [$maker, $store, $makerContext] = $this->scopedUser(
            ['finance.view', 'finance.manage', 'finance.post'],
            'CREDIT-AR',
        );
        $poster = $this->userForStore(
            $store,
            ['finance.view', 'finance.post'],
            'CREDIT-AR-POSTER',
        );
        $posterContext = app(TenantContextResolver::class)->retail($poster, $store->id);
        $this->enableFinance($maker, $makerContext);

        $finance = app(FinanceFoundationService::class);
        $receivable = $finance->createAccount($maker, $makerContext, [
            'code' => '1200',
            'name' => 'Accounts Receivable',
            'type' => 'asset',
            'normal_balance' => 'debit',
            'allow_posting' => true,
        ]);
        $revenue = $finance->createAccount($maker, $makerContext, [
            'code' => '4000',
            'name' => 'Sales Revenue',
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

        $customer = Customer::query()->create([
            'type' => 'b2b',
            'name' => 'Credit Terms Customer',
            'email' => 'credit-terms-customer@example.test',
        ]);

        $terms = app(FinanceCreditTermsService::class);
        $policy = $terms->create($maker, $makerContext, [
            'subject_type' => FinanceCreditTermsService::SUBJECT_CUSTOMER,
            'subject_reference' => (string) $customer->id,
            'credit_limit' => '100.000',
            'currency_code' => 'KWD',
            'payment_terms_days' => 30,
            'valid_from' => '2027-01-01',
        ]);

        $ar = app(AccountsReceivableService::class);
        $document = $ar->createDraft($maker, $makerContext, [
            'idempotency_key' => 'credit-terms-ar-001',
            'store_id' => $store->id,
            'customer_id' => $customer->id,
            'entry_date' => '2027-01-05',
            'due_date' => '2027-02-04',
            'amount' => '40.000',
            'currency_code' => 'KWD',
            'receivable_account_id' => $receivable->id,
            'offset_account_id' => $revenue->id,
        ]);
        $ar->post($poster, $posterContext, $document->id);

        $snapshot = $terms->customerAvailableCredit(
            $maker,
            $makerContext,
            $customer->id,
            '2027-01-31',
        );

        $this->assertSame($policy->id, $snapshot['policy_id']);
        $this->assertSame('KWD', $snapshot['currency']);
        $this->assertSame(100.0, $snapshot['credit_limit']);
        $this->assertSame(40.0, $snapshot['outstanding_receivable']);
        $this->assertSame(60.0, $snapshot['available_credit']);
        $this->assertSame(30, $snapshot['payment_terms_days']);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.finance.credit_terms_created',
            'auditable_id' => $policy->id,
        ]);
    }

    public function test_effective_windows_are_deterministic_and_supplier_due_date_uses_policy(): void
    {
        [$actor, , $context] = $this->scopedUser(
            ['finance.view', 'finance.manage'],
            'CREDIT-EFFECTIVE',
        );
        $this->enableFinance($actor, $context);
        $service = app(FinanceCreditTermsService::class);

        $supplier = $service->create($actor, $context, [
            'subject_type' => FinanceCreditTermsService::SUBJECT_SUPPLIER,
            'subject_reference' => 'SUP-001',
            'currency_code' => 'KWD',
            'payment_terms_days' => 45,
            'valid_from' => '2027-01-01',
            'valid_until' => '2027-06-30',
        ]);

        $this->assertSame(
            '2027-03-02',
            $service->supplierDueDate($actor, $context, 'SUP-001', '2027-01-16'),
        );
        $this->assertSame(
            $supplier->id,
            $service->effective(
                $actor,
                $context,
                FinanceCreditTermsService::SUBJECT_SUPPLIER,
                'SUP-001',
                '2027-05-01',
            )->id,
        );

        try {
            $service->create($actor, $context, [
                'subject_type' => FinanceCreditTermsService::SUBJECT_SUPPLIER,
                'subject_reference' => 'SUP-001',
                'currency_code' => 'KWD',
                'payment_terms_days' => 60,
                'valid_from' => '2027-06-01',
                'valid_until' => '2027-12-31',
            ]);
            $this->fail('Overlapping effective Finance terms must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('valid_from', $exception->errors());
        }

        $service->setStatus($actor, $context, $supplier->id, 'inactive');
        $replacement = $service->create($actor, $context, [
            'subject_type' => FinanceCreditTermsService::SUBJECT_SUPPLIER,
            'subject_reference' => 'SUP-001',
            'currency_code' => 'KWD',
            'payment_terms_days' => 60,
            'valid_from' => '2027-06-01',
            'valid_until' => '2027-12-31',
        ]);

        $this->assertSame(
            $replacement->id,
            $service->effective(
                $actor,
                $context,
                FinanceCreditTermsService::SUBJECT_SUPPLIER,
                'SUP-001',
                '2027-07-01',
            )->id,
        );
    }

    public function test_credit_terms_are_tenant_isolated_and_listing_is_bounded(): void
    {
        [$actorA, , $contextA] = $this->scopedUser(
            ['finance.view', 'finance.manage'],
            'CREDIT-A',
        );
        [$actorB, , $contextB] = $this->scopedUserInNewOrganization(
            ['finance.view', 'finance.manage'],
            'CREDIT-B',
        );
        $this->enableFinance($actorA, $contextA);
        $this->enableFinance($actorB, $contextB);
        $service = app(FinanceCreditTermsService::class);

        $foreign = $service->create($actorB, $contextB, [
            'subject_type' => FinanceCreditTermsService::SUBJECT_SUPPLIER,
            'subject_reference' => 'FOREIGN-SUP',
            'currency_code' => 'KWD',
            'payment_terms_days' => 14,
            'valid_from' => '2027-01-01',
        ]);

        $page = $service->paginate($actorA, $contextA, [], 500);
        $this->assertSame(100, $page->perPage());
        $this->assertSame(0, $page->total());

        try {
            $service->setStatus($actorA, $contextA, $foreign->id, 'inactive');
            $this->fail('Cross-organization Finance policy mutation must be hidden.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }
    }

    public function test_disabled_finance_performs_no_credit_terms_query_work(): void
    {
        [$actor, , $context] = $this->scopedUser(
            ['finance.view'],
            'CREDIT-OFF',
        );
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower((string) $query->sql);
        });

        try {
            app(FinanceCreditTermsService::class)->paginate($actor, $context);
            $this->fail('Disabled Finance must deny credit-term reads.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertFalse(collect($queries)->contains(
            static fn (string $sql): bool => str_contains($sql, 'enterprise_finance_credit_terms'),
        ));
    }

    public function test_credit_terms_schema_is_additive_and_reversible(): void
    {
        $migration = require database_path(
            'migrations/2026_10_16_092500_create_enterprise_finance_credit_terms.php',
        );

        $migration->down();
        $this->assertFalse(Schema::hasTable('enterprise_finance_credit_terms'));
        $this->assertTrue(Schema::hasTable('enterprise_ar_documents'));
        $this->assertTrue(Schema::hasTable('enterprise_ap_documents'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('enterprise_finance_credit_terms'));
    }

    /** @return array{User,Store,ResolvedTenantContext} */
    private function scopedUser(array $permissions, string $storeCode): array
    {
        $store = $this->createRetailStore($storeCode);
        $user = $this->userForStore($store, $permissions, $storeCode);

        return [
            $user,
            $store,
            app(TenantContextResolver::class)->retail($user, $store->id),
        ];
    }

    /** @return array{User,Store,ResolvedTenantContext} */
    private function scopedUserInNewOrganization(array $permissions, string $storeCode): array
    {
        $store = $this->createRetailStore($storeCode, false);
        $this->assignStoreToNewOrganization($store);
        $user = $this->userForStore($store, $permissions, $storeCode);

        return [
            $user,
            $store,
            app(TenantContextResolver::class)->retail($user, $store->id),
        ];
    }

    /** @param list<string> $permissions */
    private function userForStore(Store $store, array $permissions, string $code): User
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

    private function enableFinance(User $actor, ResolvedTenantContext $context): void
    {
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
                'finance.ar.read',
                'finance.ar.manage',
                'finance.ar.post',
                'finance.ap.read',
                'finance.ap.manage',
                'finance.ap.post',
                'finance.credit_terms.read',
                'finance.credit_terms.manage',
            ],
            $actor,
        );
    }

    private function createRetailStore(string $code, bool $defaultScope = true): Store
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
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
            'code' => 'credit_org_'.$store->id,
            'name' => 'Credit Terms Test Organization',
            'is_active' => true,
        ]);
        $company = EnterpriseCompany::query()->create([
            'organization_id' => $organization->id,
            'code' => 'credit_company_'.$store->id,
            'name' => 'Credit Terms Test Company',
            'is_active' => true,
        ]);
        $legalEntity = EnterpriseLegalEntity::query()->create([
            'company_id' => $company->id,
            'code' => 'credit_legal_'.$store->id,
            'name' => 'Credit Terms Test Legal Entity',
            'is_active' => true,
        ]);
        $unit = EnterpriseBusinessUnit::query()->create([
            'legal_entity_id' => $legalEntity->id,
            'code' => 'credit_unit_'.$store->id,
            'name' => 'Credit Terms Test Unit',
            'is_active' => true,
        ]);

        app(OrganizationHierarchyService::class)->assignStore($store, $unit);
    }
}
