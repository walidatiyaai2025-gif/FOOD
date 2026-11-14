<?php

namespace Tests\Feature;

use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Enterprise\Procurement\SupplierDirectory;
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
use Tests\TestCase;

final class EnterpriseSupplierFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_crud_is_server_scoped_audited_and_bounded(): void
    {
        [$actor, $store, $context] = $this->scopedUser(
            ['procurement.suppliers.view', 'procurement.suppliers.manage'],
            'SUP-A',
        );
        $directory = app(SupplierDirectory::class);

        $supplier = $directory->create($actor, $context, [
            'scope_type' => 'store',
            'code' => ' sup-001 ',
            'name_en' => 'North Foods',
            'name_ar' => 'أغذية الشمال',
            'email' => 'buying@example.test',
            'tax_registration_number' => 'TAX-001',
            'status' => 'active',
            'organization_id' => 999999,
            'company_id' => 999999,
            'store_id' => 999999,
        ]);

        $this->assertSame('SUP-001', $supplier->code);
        $this->assertSame($store->id, $supplier->store_id);
        $this->assertSame($context->enterpriseScope?->organizationId, $supplier->organization_id);
        $this->assertSame($context->enterpriseScope?->companyId, $supplier->company_id);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.supplier.created',
            'auditable_id' => $supplier->id,
        ]);

        for ($i = 2; $i <= 15; $i++) {
            $directory->create($actor, $context, [
                'scope_type' => 'store',
                'code' => sprintf('SUP-%03d', $i),
                'name_en' => sprintf('Supplier %03d', $i),
                'status' => 'active',
            ]);
        }

        $page = $directory->paginate($actor, $context, ['status' => 'active'], 500);
        $this->assertSame(100, $page->perPage());
        $this->assertSame(15, $page->total());

        $updated = $directory->update($actor, $context, $supplier->id, [
            'contact_name' => 'Procurement Desk',
            'payment_terms_days' => 30,
            'status' => 'on_hold',
        ]);
        $this->assertSame('on_hold', $updated->status);
        $this->assertSame('Procurement Desk', $updated->contact_name);

        $document = $directory->registerDocument($actor, $context, $supplier->id, [
            'category' => 'tax',
            'title' => 'Tax certificate',
            'reference' => 'CERT-1',
        ]);
        $this->assertSame($supplier->id, $document->supplier_id);

        $supplier360 = $directory->supplier360($actor, $context, $supplier->id);
        $this->assertSame($supplier->id, $supplier360['supplier']->id);
        $this->assertCount(1, $supplier360['documents']);
        $this->assertGreaterThanOrEqual(3, count($supplier360['activity']));

        $archived = $directory->archive($actor, $context, $supplier->id);
        $this->assertSame('inactive', $archived->status);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.supplier.updated',
            'auditable_id' => $supplier->id,
        ]);
    }

    public function test_store_scoped_supplier_cannot_cross_store_boundary(): void
    {
        [$actorA, , $contextA] = $this->scopedUser(
            ['procurement.suppliers.view', 'procurement.suppliers.manage'],
            'SUP-B-A',
        );
        [$actorB, , $contextB] = $this->scopedUser(
            ['procurement.suppliers.view', 'procurement.suppliers.manage'],
            'SUP-B-B',
        );

        $directory = app(SupplierDirectory::class);
        $supplier = $directory->create($actorA, $contextA, [
            'scope_type' => 'store',
            'code' => 'STORE-ONLY',
            'name_en' => 'Store Only Supplier',
        ]);

        $this->assertSame(0, $directory->paginate($actorB, $contextB)->total());

        $this->expectException(ModelNotFoundException::class);
        $directory->supplier360($actorB, $contextB, $supplier->id);
    }

    public function test_supplier_query_denies_different_organization_even_with_permission(): void
    {
        [$actorA, , $contextA] = $this->scopedUser(
            ['procurement.suppliers.view', 'procurement.suppliers.manage'],
            'SUP-C-A',
        );
        [$actorB, , $contextB] = $this->scopedUserInNewOrganization(
            ['procurement.suppliers.view', 'procurement.suppliers.manage'],
            'SUP-C-B',
        );

        $directory = app(SupplierDirectory::class);
        $supplier = $directory->create($actorB, $contextB, [
            'scope_type' => 'company',
            'code' => 'OTHER-ORG',
            'name_en' => 'Other Organization Supplier',
        ]);

        $this->assertSame(0, $directory->paginate($actorA, $contextA)->total());

        $this->expectException(ModelNotFoundException::class);
        $directory->supplier360($actorA, $contextA, $supplier->id);
    }

    public function test_supplier_prefix_search_matches_literal_symbols_and_preserves_scope_and_filters(): void
    {
        [$actor, , $context] = $this->scopedUser(
            ['procurement.suppliers.view', 'procurement.suppliers.manage'],
            'SUP-LITERAL-A',
        );
        [$foreignActor, , $foreignContext] = $this->scopedUser(
            ['procurement.suppliers.view', 'procurement.suppliers.manage'],
            'SUP-LITERAL-B',
        );

        $directory = app(SupplierDirectory::class);
        $matched = $directory->create($actor, $context, [
            'scope_type' => 'store',
            'code' => 'LIT-SUP-1',
            'name_en' => 'Percent%_! Supplies',
            'name_ar' => 'عربي%_! المورد',
            'status' => 'active',
        ]);
        $lookalike = $directory->create($actor, $context, [
            'scope_type' => 'store',
            'code' => 'LIT-SUP-2',
            'name_en' => 'PercentXYZ Supplies',
            'name_ar' => 'عربيXYZ المورد',
            'status' => 'active',
        ]);
        $inactive = $directory->create($actor, $context, [
            'scope_type' => 'store',
            'code' => 'LIT-SUP-3',
            'name_en' => 'Percent%_! Inactive',
            'name_ar' => 'عربي%_! غير نشط',
            'status' => 'inactive',
        ]);
        $foreign = $directory->create($foreignActor, $foreignContext, [
            'scope_type' => 'store',
            'code' => 'LIT-SUP-4',
            'name_en' => 'Percent%_! Foreign',
            'name_ar' => 'عربي%_! خارجي',
            'status' => 'active',
        ]);

        // Preserve legitimate historic supplier contact values that cannot always
        // be entered through present-day creation validation.
        DB::table('enterprise_suppliers')->where('id', $matched->id)->update([
            'email' => 'email_%!@example.test',
            'phone' => '+965%_!77',
            'tax_registration_number' => 'TAX%_!98',
        ]);
        $auditCount = DB::table('audit_logs')->count();
        $supplierCount = DB::table('enterprise_suppliers')->count();

        foreach ([
            ['q' => 'Percent%_!', 'expected' => [$matched->id, $inactive->id]],
            ['q' => 'عربي%_!', 'expected' => [$matched->id, $inactive->id]],
            ['q' => 'email_%!', 'expected' => [$matched->id]],
            ['q' => '+965%_!', 'expected' => [$matched->id]],
            ['q' => 'TAX%_!', 'expected' => [$matched->id]],
            ['q' => '%', 'expected' => []],
            ['q' => '_', 'expected' => []],
            ['q' => '!', 'expected' => []],
            ['q' => 'Percent', 'expected' => [$matched->id, $lookalike->id, $inactive->id]],
            ['q' => 'Supplies', 'expected' => []],
        ] as $case) {
            $ids = $directory->paginate($actor, $context, ['q' => $case['q']])
                ->getCollection()->pluck('id')->all();
            sort($ids);
            $expected = $case['expected'];
            sort($expected);
            $this->assertSame($expected, $ids, 'Supplier literal prefix q='.$case['q']);
        }

        $activeIds = $directory->paginate($actor, $context, [
            'q' => 'Percent%_!', 'status' => 'active', 'scope_type' => 'store',
        ])->getCollection()->pluck('id')->all();
        $this->assertSame([$matched->id], $activeIds);

        $this->assertSame(0, $directory->paginate($actor, $context, [
            'q' => 'Percent%_!', 'scope_type' => 'company',
        ])->total());

        $this->assertSame(0, $directory->paginate($actor, $context, [
            'q' => 'Percent%_! Foreign',
        ])->total());
        $this->assertSame(1, $directory->paginate($foreignActor, $foreignContext, [
            'q' => 'Percent%_! Foreign',
        ])->total());
        $this->assertSame($foreign->id, $directory->paginate($foreignActor, $foreignContext, [
            'q' => 'Percent%_! Foreign',
        ])->items()[0]->id);

        $this->assertSame($auditCount, DB::table('audit_logs')->count());
        $this->assertSame($supplierCount, DB::table('enterprise_suppliers')->count());
    }

    /**
     * @param  list<string>  $permissions
     * @return array{User,Store,ResolvedTenantContext}
     */
    private function scopedUser(array $permissions, string $storeCode): array
    {
        $role = Role::query()->create([
            'code' => 'ROLE_'.str_replace('-', '_', $storeCode),
            'name' => $storeCode,
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
        $store = $this->createRetailStore($storeCode);

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $context = app(TenantContextResolver::class)->retail($user, $store->id);

        return [$user, $store, $context];
    }

    /**
     * @param  list<string>  $permissions
     * @return array{User,Store,ResolvedTenantContext}
     */
    private function scopedUserInNewOrganization(array $permissions, string $storeCode): array
    {
        $role = Role::query()->create([
            'code' => 'ROLE_'.str_replace('-', '_', $storeCode),
            'name' => $storeCode,
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
            'code' => 'supplier_org_'.$store->id,
            'name' => 'Supplier Test Organization',
            'is_active' => true,
        ]);
        $company = EnterpriseCompany::query()->create([
            'organization_id' => $organization->id,
            'code' => 'supplier_company_'.$store->id,
            'name' => 'Supplier Test Company',
            'is_active' => true,
        ]);
        $legalEntity = EnterpriseLegalEntity::query()->create([
            'company_id' => $company->id,
            'code' => 'supplier_legal_'.$store->id,
            'name' => 'Supplier Test Legal Entity',
            'is_active' => true,
        ]);
        $businessUnit = EnterpriseBusinessUnit::query()->create([
            'legal_entity_id' => $legalEntity->id,
            'code' => 'supplier_unit_'.$store->id,
            'name' => 'Supplier Test Unit',
            'is_active' => true,
        ]);

        app(OrganizationHierarchyService::class)->assignStore($store, $businessUnit);
    }
}
