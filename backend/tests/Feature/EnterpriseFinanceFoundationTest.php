<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Finance\CostCenterService;
use App\Enterprise\Finance\FinanceFoundationService;
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
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class EnterpriseFinanceFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_manifest_is_disabled_by_default_and_disabled_reads_touch_no_finance_tables(): void
    {
        [$actor, , $context] = $this->scopedUser(['finance.view'], 'FIN-DISABLED');
        $manifest = app(ModuleRegistry::class)->manifest(FinanceFoundationService::MODULE);

        $this->assertSame(ModuleState::DISABLED, $manifest->defaultState);
        $this->assertSame([], $manifest->hardDependencies);
        $this->assertContains('finance.foundation.read', $manifest->capabilities);

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = (string) $query->sql;
        });

        try {
            app(FinanceFoundationService::class)->paginateAccounts($actor, $context);
            $this->fail('Disabled Finance must deny access.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertFalse(collect($queries)->contains(
            static fn (string $sql): bool => str_contains($sql, 'enterprise_finance_accounts')
                || str_contains($sql, 'enterprise_fiscal_years')
                || str_contains($sql, 'enterprise_fiscal_periods'),
        ));
    }

    public function test_chart_of_accounts_is_server_scoped_audited_and_bounded(): void
    {
        [$actor, , $context] = $this->scopedUser(
            ['finance.view', 'finance.manage'],
            'FIN-COA',
        );
        $this->enableFinance($actor, $context);
        $finance = app(FinanceFoundationService::class);

        $control = $finance->createAccount($actor, $context, [
            'organization_id' => 999999,
            'company_id' => 999999,
            'legal_entity_id' => 999999,
            'code' => ' 1000 ',
            'name' => 'Assets',
            'type' => 'asset',
            'normal_balance' => 'debit',
            'is_control' => true,
        ]);

        $this->assertSame('1000', $control->code);
        $this->assertTrue($control->is_control);
        $this->assertFalse($control->allow_posting);
        $this->assertSame($context->enterpriseScope?->organizationId, $control->organization_id);
        $this->assertSame($context->enterpriseScope?->companyId, $control->company_id);
        $this->assertSame($context->enterpriseScope?->legalEntityId, $control->legal_entity_id);

        $posting = $finance->createAccount($actor, $context, [
            'parent_id' => $control->id,
            'code' => '1100',
            'name' => 'Cash',
            'type' => 'asset',
            'normal_balance' => 'debit',
            'allow_posting' => true,
        ]);
        $this->assertSame($control->id, $posting->parent_id);

        try {
            $finance->createAccount($actor, $context, [
                'code' => '1200',
                'name' => 'Invalid Control',
                'type' => 'asset',
                'normal_balance' => 'debit',
                'is_control' => true,
                'allow_posting' => true,
            ]);
            $this->fail('Control accounts must reject direct posting.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('allow_posting', $exception->errors());
        }

        $scope = $context->enterpriseScope;
        $this->assertNotNull($scope);
        $now = now();
        $rows = [];
        for ($i = 1; $i <= 105; $i++) {
            $rows[] = [
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId,
                'legal_entity_id' => $scope->legalEntityId,
                'parent_id' => $control->id,
                'code' => sprintf('2%03d', $i),
                'name' => sprintf('Bounded Account %03d', $i),
                'type' => 'asset',
                'normal_balance' => 'debit',
                'is_control' => false,
                'allow_posting' => true,
                'status' => 'active',
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 50) as $chunk) {
            DB::table('enterprise_finance_accounts')->insert($chunk);
        }

        $page = $finance->paginateAccounts($actor, $context, ['status' => 'active'], 500);
        $this->assertSame(100, $page->perPage());
        $this->assertSame(107, $page->total());
        $this->assertCount(100, $page->items());

        $inactive = $finance->setAccountStatus($actor, $context, $posting->id, 'inactive');
        $this->assertSame('inactive', $inactive->status);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.finance.account_created',
            'auditable_id' => $posting->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.finance.account_status_changed',
            'auditable_id' => $posting->id,
        ]);
    }

    public function test_finance_requires_both_rbac_and_module_entitlement(): void
    {
        [$viewer, , $viewerContext] = $this->scopedUser(['finance.view'], 'FIN-RBAC');
        $this->enableFinance($viewer, $viewerContext, [
            'finance.foundation.read',
            'finance.foundation.manage',
        ]);

        $finance = app(FinanceFoundationService::class);
        $this->assertSame(0, $finance->paginateAccounts($viewer, $viewerContext)->total());

        try {
            $finance->createAccount($viewer, $viewerContext, [
                'code' => '3000',
                'name' => 'Equity',
                'type' => 'equity',
                'normal_balance' => 'credit',
            ]);
            $this->fail('finance.manage is required for account mutation.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        [$manager, , $unentitledContext] = $this->scopedUserInNewOrganization(
            ['finance.view', 'finance.manage'],
            'FIN-NO-ENT',
        );

        try {
            $finance->paginateAccounts($manager, $unentitledContext);
            $this->fail('A legal entity without a Finance entitlement must be denied.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_finance_records_cannot_cross_enterprise_organization_boundary(): void
    {
        [$actorA, , $contextA] = $this->scopedUser(
            ['finance.view', 'finance.manage'],
            'FIN-TENANT-A',
        );
        [$actorB, , $contextB] = $this->scopedUserInNewOrganization(
            ['finance.view', 'finance.manage'],
            'FIN-TENANT-B',
        );

        $this->enableFinance($actorA, $contextA);
        $this->enableFinance($actorB, $contextB);

        $finance = app(FinanceFoundationService::class);
        $foreign = $finance->createAccount($actorB, $contextB, [
            'code' => '4000',
            'name' => 'Other Tenant Revenue',
            'type' => 'revenue',
            'normal_balance' => 'credit',
        ]);

        $this->assertSame(0, $finance->paginateAccounts($actorA, $contextA)->total());

        $this->expectException(ModelNotFoundException::class);
        $finance->setAccountStatus($actorA, $contextA, $foreign->id, 'inactive');
    }

    public function test_fiscal_period_state_rules_prevent_overlap_and_premature_year_close(): void
    {
        [$actor, , $context] = $this->scopedUser(
            ['finance.view', 'finance.manage'],
            'FIN-PERIOD',
        );
        $this->enableFinance($actor, $context);
        $finance = app(FinanceFoundationService::class);

        $year = $finance->createFiscalYear($actor, $context, [
            'code' => 'FY2027',
            'name' => 'Fiscal Year 2027',
            'starts_on' => '2027-01-01',
            'ends_on' => '2027-12-31',
        ]);

        try {
            $finance->createFiscalYear($actor, $context, [
                'code' => 'FY2027-OVERLAP',
                'name' => 'Overlap',
                'starts_on' => '2027-12-01',
                'ends_on' => '2028-11-30',
            ]);
            $this->fail('Fiscal years cannot overlap.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('starts_on', $exception->errors());
        }

        $period = $finance->createFiscalPeriod($actor, $context, $year->id, [
            'sequence' => 1,
            'code' => 'P01',
            'name' => 'January',
            'starts_on' => '2027-01-01',
            'ends_on' => '2027-01-31',
        ]);

        try {
            $finance->createFiscalPeriod($actor, $context, $year->id, [
                'sequence' => 2,
                'code' => 'P02',
                'name' => 'Overlap Period',
                'starts_on' => '2027-01-15',
                'ends_on' => '2027-02-15',
            ]);
            $this->fail('Fiscal periods cannot overlap.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('starts_on', $exception->errors());
        }

        try {
            $finance->closeFiscalYear($actor, $context, $year->id);
            $this->fail('Fiscal year cannot close while a period is open.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('fiscal_year', $exception->errors());
        }

        $closedPeriod = $finance->closeFiscalPeriod($actor, $context, $period->id);
        $this->assertSame('closed', $closedPeriod->status);
        $closedYear = $finance->closeFiscalYear($actor, $context, $year->id);
        $this->assertSame('closed', $closedYear->status);

        try {
            $finance->reopenFiscalPeriod($actor, $context, $period->id);
            $this->fail('A period cannot reopen while its fiscal year is closed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('fiscal_period', $exception->errors());
        }

        $this->assertSame('open', $finance->reopenFiscalYear($actor, $context, $year->id)->status);
        $this->assertSame('open', $finance->reopenFiscalPeriod($actor, $context, $period->id)->status);

        $periodPage = $finance->paginateFiscalPeriods($actor, $context, [], 500);
        $yearPage = $finance->paginateFiscalYears($actor, $context, [], 500);
        $this->assertSame(100, $periodPage->perPage());
        $this->assertSame(100, $yearPage->perPage());

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.finance.fiscal_period_closed',
            'auditable_id' => $period->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.finance.fiscal_year_closed',
            'auditable_id' => $year->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.finance.fiscal_year_reopened',
            'auditable_id' => $year->id,
        ]);
    }


    public function test_cost_centers_are_scoped_hierarchical_audited_and_bounded(): void
    {
        [$actor, , $context] = $this->scopedUser(
            ['finance.view', 'finance.manage'],
            'FIN-COST-CENTER',
        );
        $this->enableFinance($actor, $context, [
            'finance.cost_centers.read',
            'finance.cost_centers.manage',
        ]);

        $service = app(CostCenterService::class);
        $parent = $service->create($actor, $context, [
            'organization_id' => 999999,
            'company_id' => 999999,
            'legal_entity_id' => 999999,
            'code' => ' ops ',
            'name' => 'Operations',
        ]);

        $this->assertSame('OPS', $parent->code);
        $this->assertSame($context->enterpriseScope?->organizationId, $parent->organization_id);
        $this->assertSame($context->enterpriseScope?->companyId, $parent->company_id);
        $this->assertSame($context->enterpriseScope?->legalEntityId, $parent->legal_entity_id);

        $child = $service->create($actor, $context, [
            'parent_id' => $parent->id,
            'code' => 'OPS-KWI',
            'name' => 'Kuwait Operations',
        ]);
        $this->assertSame($parent->id, $child->parent_id);

        try {
            $service->create($actor, $context, [
                'code' => 'ops',
                'name' => 'Duplicate',
            ]);
            $this->fail('Cost center codes must be unique per legal entity.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('code', $exception->errors());
        }

        try {
            $service->setStatus($actor, $context, $parent->id, 'inactive');
            $this->fail('A cost center with active children cannot be made inactive.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertSame('inactive', $service->setStatus($actor, $context, $child->id, 'inactive')->status);
        $this->assertSame('inactive', $service->setStatus($actor, $context, $parent->id, 'inactive')->status);

        try {
            $service->create($actor, $context, [
                'parent_id' => $parent->id,
                'code' => 'OPS-NEW',
                'name' => 'Invalid Child',
            ]);
            $this->fail('Inactive parents must reject new child cost centers.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('parent_id', $exception->errors());
        }

        $scope = $context->enterpriseScope;
        $this->assertNotNull($scope);
        $now = now();
        $rows = [];
        for ($i = 1; $i <= 105; $i++) {
            $rows[] = [
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId,
                'legal_entity_id' => $scope->legalEntityId,
                'parent_id' => null,
                'code' => sprintf('CC%03d', $i),
                'name' => sprintf('Cost Center %03d', $i),
                'status' => 'active',
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 50) as $chunk) {
            DB::table('enterprise_finance_cost_centers')->insert($chunk);
        }

        $page = $service->paginate($actor, $context, ['status' => 'active'], 500);
        $this->assertSame(100, $page->perPage());
        $this->assertSame(105, $page->total());
        $this->assertCount(100, $page->items());

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.finance.cost_center_created',
            'auditable_id' => $child->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.finance.cost_center_status_changed',
            'auditable_id' => $child->id,
        ]);
    }

    public function test_cost_centers_require_rbac_entitlement_and_tenant_scope(): void
    {
        [$viewer, , $viewerContext] = $this->scopedUser(['finance.view'], 'FIN-CC-VIEW');
        $this->enableFinance($viewer, $viewerContext, [
            'finance.cost_centers.read',
            'finance.cost_centers.manage',
        ]);

        $service = app(CostCenterService::class);
        $this->assertSame(0, $service->paginate($viewer, $viewerContext)->total());

        try {
            $service->create($viewer, $viewerContext, [
                'code' => 'NO-MANAGE',
                'name' => 'Denied',
            ]);
            $this->fail('finance.manage is required for cost center mutation.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        [$actorA, , $contextA] = $this->scopedUser(
            ['finance.view', 'finance.manage'],
            'FIN-CC-A',
        );
        [$actorB, , $contextB] = $this->scopedUserInNewOrganization(
            ['finance.view', 'finance.manage'],
            'FIN-CC-B',
        );
        $capabilities = ['finance.cost_centers.read', 'finance.cost_centers.manage'];
        $this->enableFinance($actorA, $contextA, $capabilities);
        $this->enableFinance($actorB, $contextB, $capabilities);

        $foreign = $service->create($actorB, $contextB, [
            'code' => 'FOREIGN',
            'name' => 'Foreign Cost Center',
        ]);

        $this->assertSame(0, $service->paginate($actorA, $contextA)->total());

        try {
            $service->setStatus($actorA, $contextA, $foreign->id, 'inactive');
            $this->fail('Cross-tenant cost center mutation must be denied.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }

        [$unentitled, , $unentitledContext] = $this->scopedUserInNewOrganization(
            ['finance.view', 'finance.manage'],
            'FIN-CC-NO-ENT',
        );

        try {
            $service->paginate($unentitled, $unentitledContext);
            $this->fail('A legal entity without the cost-center entitlement must be denied.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_cost_center_migration_is_additive_and_reversible(): void
    {
        $migration = require database_path(
            'migrations/2026_10_16_035200_create_enterprise_finance_cost_centers.php',
        );

        $migration->down();

        $this->assertFalse(Schema::hasTable('enterprise_finance_cost_centers'));
        $this->assertTrue(Schema::hasTable('enterprise_finance_accounts'));
        $this->assertTrue(Schema::hasTable('enterprise_fiscal_years'));
        $this->assertTrue(Schema::hasTable('enterprise_fiscal_periods'));

        $migration->up();

        $this->assertTrue(Schema::hasTable('enterprise_finance_cost_centers'));
    }

    public function test_finance_migration_is_additive_and_reversible_without_touching_core_hierarchy(): void
    {
        $migration = require database_path(
            'migrations/2026_10_15_234000_create_enterprise_finance_foundation.php',
        );

        $migration->down();

        $this->assertFalse(Schema::hasTable('enterprise_finance_accounts'));
        $this->assertFalse(Schema::hasTable('enterprise_fiscal_years'));
        $this->assertFalse(Schema::hasTable('enterprise_fiscal_periods'));
        $this->assertTrue(Schema::hasTable('enterprise_organizations'));
        $this->assertTrue(Schema::hasTable('enterprise_companies'));
        $this->assertTrue(Schema::hasTable('enterprise_legal_entities'));

        $migration->up();

        $this->assertTrue(Schema::hasTable('enterprise_finance_accounts'));
        $this->assertTrue(Schema::hasTable('enterprise_fiscal_years'));
        $this->assertTrue(Schema::hasTable('enterprise_fiscal_periods'));
    }

    /**
     * @param  list<string>  $capabilities
     */
    private function enableFinance(
        User $actor,
        ResolvedTenantContext $context,
        array $capabilities = ['finance.foundation.read', 'finance.foundation.manage'],
    ): void {
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
            $capabilities,
            $actor,
        );
    }

    /**
     * @param  list<string>  $permissions
     * @return array{User,Store,ResolvedTenantContext}
     */
    private function scopedUser(array $permissions, string $storeCode): array
    {
        $role = $this->roleWithPermissions($permissions, $storeCode);
        $user = User::factory()->create(['is_active' => true]);
        $store = $this->createRetailStore($storeCode);

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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
        $role = $this->roleWithPermissions($permissions, $storeCode);
        $user = User::factory()->create(['is_active' => true]);
        $store = $this->createRetailStore($storeCode, false);
        $this->assignStoreToNewOrganization($store);

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            $user,
            $store,
            app(TenantContextResolver::class)->retail($user, $store->id),
        ];
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
            'code' => 'finance_org_'.$store->id,
            'name' => 'Finance Test Organization',
            'is_active' => true,
        ]);
        $company = EnterpriseCompany::query()->create([
            'organization_id' => $organization->id,
            'code' => 'finance_company_'.$store->id,
            'name' => 'Finance Test Company',
            'is_active' => true,
        ]);
        $legalEntity = EnterpriseLegalEntity::query()->create([
            'company_id' => $company->id,
            'code' => 'finance_legal_'.$store->id,
            'name' => 'Finance Test Legal Entity',
            'is_active' => true,
        ]);
        $businessUnit = EnterpriseBusinessUnit::query()->create([
            'legal_entity_id' => $legalEntity->id,
            'code' => 'finance_unit_'.$store->id,
            'name' => 'Finance Test Unit',
            'is_active' => true,
        ]);

        app(OrganizationHierarchyService::class)->assignStore($store, $businessUnit);
    }
}
