<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Enterprise\Procurement\GoodsReceiptManager;
use App\Enterprise\Procurement\PurchaseInvoiceHandoffManager;
use App\Enterprise\Procurement\PurchaseOrderManager;
use App\Enterprise\Procurement\PurchaseRequestManager;
use App\Enterprise\Procurement\SupplierDirectory;
use App\Http\Controllers\Admin\EnterpriseProcurementController;
use App\Models\Catalog;
use App\Models\EnterpriseBusinessUnit;
use App\Models\EnterpriseCashbox;
use App\Models\EnterpriseCompany;
use App\Models\EnterpriseLegalEntity;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\EnterpriseModuleAdministration;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\Support\SyntheticErpWorkflowEvidence;
use Tests\TestCase;

/**
 * V2 P111: a global procurement role does not override an explicit store
 * entitlement denial, and restoring the entitlement does not bypass SoD.
 */
final class V2ErpEntitlementCheckerBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
        // P135's signed test helper is unmerged on this independent P111 branch.
        // Install it only when present in the synthesized test checkout; never
        // allow test fixtures to become a runtime certification or a dependency.
        if (class_exists(SyntheticErpWorkflowEvidence::class)) {
            SyntheticErpWorkflowEvidence::install();
        }
        config()->set(
            'enterprise_module_readiness.certified_versions.procurement',
            app(ModuleRegistry::class)->manifest('procurement')->version,
        );
    }

    public function test_store_entitlement_revocation_blocks_existing_maker_and_checker_http_workflows_in_both_locales(): void
    {
        $store = $this->wholesaleStore();
        $admin = $this->actor('SUPER_ADMIN');
        $buyer = $this->actor('PROCUREMENT_BUYER');
        $checker = $this->actor('PROCUREMENT_APPROVER');

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $admin);
        $entitlements = app(ModuleEntitlementManager::class);
        $entitlements->set('procurement', EntitlementScope::platform(), true, null, $admin);
        $storeScope = new EntitlementScope(EntitlementScope::STORE, $store->id);

        $this->actingAs($buyer)->post(route('admin.erp.procurement.requests.store'), [
            'store_id' => $store->id,
            'currency_code' => 'KWD',
            'reason' => 'P111 entitlement and independent checker',
            'item_code' => 'P111-PROC-SKU',
            'description' => 'Store entitlement revocation acceptance',
            'quantity' => 2,
            'unit' => 'case',
            'estimated_unit_cost' => '5.000',
        ])->assertRedirect();

        $requestId = (int) DB::table('enterprise_purchase_requests')
            ->where('reason', 'P111 entitlement and independent checker')
            ->value('id');
        $this->assertGreaterThan(0, $requestId);

        $this->actingAs($buyer)->post(route('admin.erp.procurement.requests.submit', $requestId), [
            'store_id' => $store->id,
        ])->assertRedirect();
        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $requestId,
            'status' => 'submitted',
            'submitted_by' => $buyer->id,
            'decided_by' => null,
        ]);

        // A store-specific DENY takes precedence over the platform grant,
        // including for a global checker and a platform Super Admin.
        $entitlements->set('procurement', $storeScope, false, null, $admin);
        $this->assertDatabaseHas('enterprise_module_entitlements', [
            'module_code' => 'procurement',
            'scope_key' => $storeScope->key(),
            'is_enabled' => false,
        ]);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $checker->forceFill(['locale' => $locale])->save();
            $admin->forceFill(['locale' => $locale])->save();

            $this->actingAs($checker->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $store->id,
                'tab' => 'requests',
            ], false))->assertForbidden();

            $this->actingAs($checker->fresh())->post(route('admin.erp.procurement.requests.decision', $requestId), [
                'store_id' => $store->id,
                'decision' => 'approve',
            ])->assertForbidden();

            $this->actingAs($admin->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $store->id,
                'tab' => 'requests',
            ], false))->assertForbidden();

            $this->actingAs($buyer->fresh())->post(route('admin.erp.procurement.requests.submit', $requestId), [
                'store_id' => $store->id,
            ])->assertForbidden();
        }

        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $requestId,
            'status' => 'submitted',
            'decided_by' => null,
        ]);
        $this->assertSame(0, DB::table('audit_logs')
            ->where('event', 'enterprise.purchase_request.approved')
            ->where('auditable_id', $requestId)
            ->count());

        // Restoring entitlement only restores route eligibility. Even a
        // maker who later receives the checker role cannot approve their own
        // previously submitted request.
        $entitlements->set('procurement', $storeScope, true, null, $admin);
        $buyer->roles()->attach(Role::query()->where('code', 'PROCUREMENT_APPROVER')->firstOrFail());
        $this->assertTrue($buyer->fresh()->hasPermission('procurement.purchase_requests.approve', $store->id));

        $this->actingAs($buyer->fresh())->post(route('admin.erp.procurement.requests.decision', $requestId), [
            'store_id' => $store->id,
            'decision' => 'approve',
        ])->assertSessionHasErrors('decision');

        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $requestId,
            'status' => 'submitted',
            'decided_by' => null,
        ]);

        $this->actingAs($checker->fresh())->post(route('admin.erp.procurement.requests.decision', $requestId), [
            'store_id' => $store->id,
            'decision' => 'approve',
            'note' => 'Independent P111 checker',
        ])->assertRedirect();

        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $requestId,
            'status' => 'approved',
            'decided_by' => $checker->id,
        ]);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('event', 'enterprise.purchase_request.approved')
            ->where('auditable_id', $requestId)
            ->count());
    }

    public function test_explicit_store_deny_preserves_independent_sibling_b2b_entitlement(): void
    {
        $primary = $this->wholesaleStore();
        $sibling = Store::query()->create([
            'store_type_id' => $primary->store_type_id,
            'code' => 'P111-B2B-SIBLING',
            'name' => 'P111 B2B sibling',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($sibling);

        $admin = $this->actor('SUPER_ADMIN');
        $buyer = $this->actor('PROCUREMENT_BUYER');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $admin);

        $entitlements = app(ModuleEntitlementManager::class);
        $entitlements->set('procurement', EntitlementScope::platform(), true, null, $admin);
        $entitlements->set(
            'procurement',
            new EntitlementScope(EntitlementScope::STORE, $primary->id),
            false,
            null,
            $admin,
        );

        foreach (['ar', 'en'] as $locale) {
            $buyer->forceFill(['locale' => $locale])->save();

            $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $primary->id,
                'tab' => 'requests',
            ]))->assertForbidden();

            $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $sibling->id,
                'tab' => 'requests',
            ]))->assertOk();
        }

        $request = [
            'currency_code' => 'KWD',
            'reason' => 'P111 independent B2B store scope',
            'item_code' => 'P111-SCOPE-SKU',
            'description' => 'Cross-store entitlement enforcement',
            'quantity' => 1,
            'unit' => 'case',
            'estimated_unit_cost' => '1.000',
        ];

        $this->actingAs($buyer->fresh())->post(route('admin.erp.procurement.requests.store'), [
            ...$request,
            'store_id' => $primary->id,
        ])->assertForbidden();

        $this->assertDatabaseMissing('enterprise_purchase_requests', [
            'reason' => $request['reason'],
        ]);

        $this->actingAs($buyer->fresh())->post(route('admin.erp.procurement.requests.store'), [
            ...$request,
            'store_id' => $sibling->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'store_id' => $sibling->id,
            'reason' => $request['reason'],
        ]);
    }

    public function test_store_only_entitlement_revocation_denies_direct_requests_read_and_mutation_without_platform_fallback(): void
    {
        $store = $this->wholesaleStore();
        $admin = $this->actor('SUPER_ADMIN');
        $buyer = $this->actor('PROCUREMENT_BUYER');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $admin);
        $entitlements = app(ModuleEntitlementManager::class);
        $scope = new EntitlementScope(EntitlementScope::STORE, $store->id);
        $entitlements->set('procurement', $scope, true, null, $admin);
        $this->assertDatabaseMissing('enterprise_module_entitlements', [
            'module_code' => 'procurement',
            'scope_key' => 'platform:0',
        ]);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $buyer->forceFill(['locale' => $locale])->save();
            $this->actingAs($buyer->fresh())
                ->get(route('admin.erp.procurement.index', [
                    'store_id' => $store->id,
                    'tab' => 'requests',
                ]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);
        }

        // Revoking the only STORE entitlement must reject direct HTTP even
        // though the same actor retains their global Procurement Buyer role.
        $entitlements->set('procurement', $scope, false, null, $admin);
        $auditBefore = DB::table('audit_logs')->count();
        foreach (['ar', 'en'] as $locale) {
            $buyer->forceFill(['locale' => $locale])->save();
            $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $store->id,
                'tab' => 'requests',
            ]))->assertForbidden();

            $this->actingAs($buyer->fresh())->post(route('admin.erp.procurement.requests.store'), [
                'store_id' => $store->id,
                'currency_code' => 'KWD',
                'reason' => 'P111 revoked store only is forbidden',
                'item_code' => 'P111-REVOKED',
                'description' => 'Never persist after revocation',
                'quantity' => 1,
                'unit' => 'case',
                'estimated_unit_cost' => '1.000',
            ])->assertForbidden();
        }

        $this->assertDatabaseMissing('enterprise_purchase_requests', [
            'reason' => 'P111 revoked store only is forbidden',
        ]);
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_global_supplier_deny_and_retail_assignment_do_not_cross_store_or_channel(): void
    {
        $wholesale = $this->wholesaleStore();
        $sibling = Store::query()->create([
            'store_type_id' => $wholesale->store_type_id,
            'code' => 'P111-GLOBAL-DENY-B2B',
            'name' => 'P111 GLOBAL DENY B2B',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($sibling);
        $retail = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'P111-FOREIGN-RETAIL',
            'name' => 'P111 unrelated Retail',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($retail);
        $admin = $this->actor('SUPER_ADMIN');
        $buyer = $this->actor('PROCUREMENT_BUYER');
        $buyer->storeRoleAssignments()->create([
            'store_id' => $retail->id,
            'role_id' => Role::query()->where('code', 'RETAIL_FINANCE')->firstOrFail()->id,
        ]);
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $admin);
        $entitlements = app(ModuleEntitlementManager::class);
        $entitlements->set(
            'procurement', EntitlementScope::platform(), true, null, $admin,
        );
        $retailScope = new EntitlementScope(EntitlementScope::STORE, $retail->id);
        $entitlements->set('procurement', $retailScope, true, null, $admin);

        // Preserve both existing channel contexts for a platform administrator;
        // store-level DENY still overrides the broader platform ALLOW.
        $adminPage = $this->actingAs($admin)->get(route('admin.erp.procurement.index', [
            'store_id' => $wholesale->id,
            'tab' => 'requests',
        ]));
        $adminPage->assertOk();
        $channels = collect($adminPage->viewData('storeOptions'))->pluck('channel')->unique()->values()->all();
        $this->assertContains('b2b', $channels);
        $this->assertContains('b2c', $channels);
        $this->actingAs($admin)->get(route('admin.erp.procurement.index', [
            'store_id' => $retail->id,
            'tab' => 'requests',
        ]))->assertOk()->assertViewHas('selectedStoreId', $retail->id);

        $entitlements->set('procurement', $retailScope, false, null, $admin);
        $auditBeforeRetailDeny = DB::table('audit_logs')->count();
        $this->actingAs($admin)->get(route('admin.erp.procurement.index', [
            'store_id' => $retail->id,
            'tab' => 'requests',
        ]))->assertForbidden();
        $this->assertSame($auditBeforeRetailDeny, DB::table('audit_logs')->count());
        $entitlements->set('procurement', $retailScope, true, null, $admin);

        foreach (['procurement.suppliers.view', 'procurement.suppliers.manage'] as $code) {
            $permission = Permission::query()->where('code', $code)->firstOrFail();
            foreach ([
                ['scope_key' => 'global', 'store_id' => null, 'effect' => 'deny'],
                ['scope_key' => 'store:'.$sibling->id, 'store_id' => $sibling->id, 'effect' => 'allow'],
            ] as $override) {
                DB::table('user_permission_overrides')->insert([
                    'user_id' => $buyer->id,
                    'permission_id' => $permission->id,
                    ...$override,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
        $buyer->forceFill(['has_permission_overrides' => true])->save();
        $this->assertFalse($buyer->fresh()->hasPermission('procurement.suppliers.view', $sibling->id));
        $this->assertTrue($buyer->fresh()->hasPermission('procurement.purchase_requests.view', $sibling->id));
        $this->assertFalse($buyer->fresh()->hasRole('PROCUREMENT_BUYER', $retail->id));
        $this->assertFalse($buyer->fresh()->hasPermission('procurement.suppliers.view', $retail->id));
        $auditBefore = DB::table('audit_logs')->count();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $buyer->forceFill(['locale' => $locale])->save();
            $actor = $buyer->fresh();
            // Supplier-specific DENY does not revoke independent Requests.
            $this->actingAs($actor)->get(route('admin.erp.procurement.index', [
                'store_id' => $sibling->id, 'tab' => 'requests',
            ]))->assertOk()->assertSee('dir="'.$direction.'"', false);

            $this->actingAs($actor)->get(route('admin.erp.procurement.index', [
                'store_id' => $sibling->id, 'tab' => 'suppliers',
            ]))->assertForbidden();
            $this->actingAs($actor)->get(route('admin.erp.procurement.index', [
                'store_id' => $retail->id, 'tab' => 'suppliers',
            ]))->assertForbidden();
            $this->actingAs($actor)->post(route('admin.erp.procurement.suppliers.store'), [
                'store_id' => $retail->id,
                'scope_type' => 'store',
                'code' => 'P111-RETAIL-FORGED',
                'name_en' => 'Forbidden Retail Supplier',
                'name_ar' => 'مورد غير مصرح',
            ])->assertForbidden();
            $this->actingAs($actor)->post(route('admin.erp.procurement.suppliers.store'), [
                'store_id' => $retail->id,
                'scope_type' => 'store',
                'code' => 'P111-RETAIL-FORGED-TEST',
                'name_en' => 'Forbidden Retail Supplier Test',
                'setup_action' => 'test',
            ])->assertForbidden();
        }

        $this->assertDatabaseMissing('enterprise_suppliers', [
            'code' => 'P111-RETAIL-FORGED',
        ]);
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_company_entitlement_inherits_to_authorized_store_and_foreign_company_deny_overrides_platform_allow(): void
    {
        $wholesale = $this->wholesaleStore();
        $hierarchy = app(OrganizationHierarchyService::class);
        $wholesaleScope = $hierarchy->scopeForStore($wholesale->id);
        $this->assertIsArray($wholesaleScope);

        $retail = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'P111-COMPANY-DENY-RETAIL',
            'name' => 'P111 Company-Denied Retail',
            'is_active' => true,
        ]);
        $foreignCompany = EnterpriseCompany::query()->create([
            'organization_id' => $wholesaleScope['organization_id'],
            'code' => 'P111_COMPANY_DENY_RETAIL',
            'name' => 'P111 Company-Denied Retail',
            'is_active' => true,
        ]);
        $foreignEntity = EnterpriseLegalEntity::query()->create([
            'company_id' => $foreignCompany->id,
            'code' => 'P111_COMPANY_DENY_RETAIL_ENTITY',
            'name' => 'P111 Company-Denied Retail Entity',
            'is_active' => true,
        ]);
        $foreignUnit = EnterpriseBusinessUnit::query()->create([
            'legal_entity_id' => $foreignEntity->id,
            'code' => 'P111_COMPANY_DENY_RETAIL_UNIT',
            'name' => 'P111 Company-Denied Retail Unit',
            'is_active' => true,
        ]);
        $hierarchy->assignStore($retail, $foreignUnit);
        $retailScope = $hierarchy->scopeForStore($retail->id);
        $this->assertIsArray($retailScope);
        $this->assertNotSame($wholesaleScope['company_id'], $retailScope['company_id']);

        $owner = $this->actor('SUPER_ADMIN');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        $entitlements = app(ModuleEntitlementManager::class);
        $entitlements->set('procurement', EntitlementScope::platform(), false, null, $owner);
        $entitlements->set(
            'procurement',
            new EntitlementScope(EntitlementScope::COMPANY, $wholesaleScope['company_id']),
            true,
            null,
            $owner,
        );
        $entitlements->set(
            'procurement',
            new EntitlementScope(EntitlementScope::COMPANY, $retailScope['company_id']),
            false,
            null,
            $owner,
        );

        $allowedPage = $this->actingAs($owner)->get(route('admin.erp.procurement.index', [
            'store_id' => $wholesale->id,
            'tab' => 'requests',
        ]));
        $allowedPage->assertOk()->assertViewHas('selectedStoreId', $wholesale->id);
        $this->assertNotContains(
            $retail->id,
            collect($allowedPage->viewData('storeOptions'))->pluck('id')->all(),
        );

        // Once the broad platform grant is added, the foreign company DENY
        // remains the first matching decision in that store's hierarchy.
        $entitlements->set('procurement', EntitlementScope::platform(), true, null, $owner);
        $requestCount = DB::table('enterprise_purchase_requests')->count();
        $auditBeforeDeny = DB::table('audit_logs')->count();
        $this->actingAs($owner)->get(route('admin.erp.procurement.index', [
            'store_id' => $retail->id,
            'tab' => 'requests',
        ]))->assertForbidden();
        $this->actingAs($owner)->post(route('admin.erp.procurement.requests.store'), [
            'store_id' => $retail->id,
            'currency_code' => 'KWD',
            'reason' => 'P111 foreign company entitlement denial',
            'description' => 'Company-denied request must not persist',
            'quantity' => '1.000',
            'unit' => 'case',
        ])->assertForbidden();

        $this->assertSame($requestCount, DB::table('enterprise_purchase_requests')->count());
        $this->assertSame($auditBeforeDeny, DB::table('audit_logs')->count());
    }

    public function test_business_unit_branch_entitlement_allows_its_store_and_deny_overrides_platform_allow(): void
    {
        $hierarchy = app(OrganizationHierarchyService::class);
        $primary = $this->storeInDefaultCompany('B2B', 'P111_BRANCH_PRIMARY');
        $primaryScope = $hierarchy->scopeForStore($primary->id);
        $this->assertIsArray($primaryScope);

        $siblingUnit = EnterpriseBusinessUnit::query()->create([
            'legal_entity_id' => $primaryScope['legal_entity_id'],
            'code' => 'P111_BRANCH_SIBLING_UNIT',
            'name' => 'P111 sibling branch',
            'is_active' => true,
        ]);
        $sibling = $this->storeInBusinessUnit('B2B', 'P111_BRANCH_SIBLING', $siblingUnit);
        $siblingScope = $hierarchy->scopeForStore($sibling->id);
        $this->assertIsArray($siblingScope);
        $this->assertSame($primaryScope['company_id'], $siblingScope['company_id']);
        $this->assertSame($primaryScope['legal_entity_id'], $siblingScope['legal_entity_id']);
        $this->assertNotSame($primaryScope['business_unit_id'], $siblingScope['business_unit_id']);

        $owner = $this->actor('SUPER_ADMIN');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        $entitlements = app(ModuleEntitlementManager::class);
        $entitlements->set('procurement', EntitlementScope::platform(), false, null, $owner);
        $entitlements->set(
            'procurement',
            new EntitlementScope(EntitlementScope::BUSINESS_UNIT, $primaryScope['business_unit_id']),
            true,
            null,
            $owner,
        );
        $entitlements->set(
            'procurement',
            new EntitlementScope(EntitlementScope::BUSINESS_UNIT, $siblingScope['business_unit_id']),
            false,
            null,
            $owner,
        );

        $this->actingAs($owner)->get(route('admin.erp.procurement.index', [
            'store_id' => $primary->id,
            'tab' => 'requests',
        ]))->assertOk()->assertViewHas('selectedStoreId', $primary->id);

        $entitlements->set('procurement', EntitlementScope::platform(), true, null, $owner);
        $requestsBefore = DB::table('enterprise_purchase_requests')->count();
        $auditBefore = DB::table('audit_logs')->count();
        $this->actingAs($owner)->get(route('admin.erp.procurement.index', [
            'store_id' => $sibling->id,
            'tab' => 'requests',
        ]))->assertForbidden();
        $this->actingAs($owner)->post(route('admin.erp.procurement.requests.store'), [
            'store_id' => $sibling->id,
            'currency_code' => 'KWD',
            'reason' => 'P111 sibling branch denial',
            'description' => 'Branch-denied request must not persist',
            'quantity' => '1.000',
            'unit' => 'case',
        ])->assertForbidden();

        $this->assertSame($requestsBefore, DB::table('enterprise_purchase_requests')->count());
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_legal_entity_entitlement_allows_its_store_and_foreign_entity_deny_overrides_platform_allow(): void
    {
        $hierarchy = app(OrganizationHierarchyService::class);
        $primary = $this->storeInDefaultCompany('B2B', 'P111_ENTITY_PRIMARY');
        $primaryScope = $hierarchy->scopeForStore($primary->id);
        $this->assertIsArray($primaryScope);

        $foreignEntity = EnterpriseLegalEntity::query()->create([
            'company_id' => $primaryScope['company_id'],
            'code' => 'P111_ENTITY_SIBLING',
            'name' => 'P111 sibling legal entity',
            'is_active' => true,
        ]);
        $foreignUnit = EnterpriseBusinessUnit::query()->create([
            'legal_entity_id' => $foreignEntity->id,
            'code' => 'P111_ENTITY_SIBLING_UNIT',
            'name' => 'P111 sibling entity branch',
            'is_active' => true,
        ]);
        $sibling = $this->storeInBusinessUnit('B2B', 'P111_ENTITY_SIBLING_STORE', $foreignUnit);
        $siblingScope = $hierarchy->scopeForStore($sibling->id);
        $this->assertIsArray($siblingScope);
        $this->assertSame($primaryScope['company_id'], $siblingScope['company_id']);
        $this->assertNotSame($primaryScope['legal_entity_id'], $siblingScope['legal_entity_id']);

        $owner = $this->actor('SUPER_ADMIN');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        $entitlements = app(ModuleEntitlementManager::class);
        $entitlements->set('procurement', EntitlementScope::platform(), false, null, $owner);
        $entitlements->set(
            'procurement',
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $primaryScope['legal_entity_id']),
            true,
            null,
            $owner,
        );
        $entitlements->set(
            'procurement',
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $siblingScope['legal_entity_id']),
            false,
            null,
            $owner,
        );

        $this->actingAs($owner)->get(route('admin.erp.procurement.index', [
            'store_id' => $primary->id,
            'tab' => 'requests',
        ]))->assertOk()->assertViewHas('selectedStoreId', $primary->id);

        $entitlements->set('procurement', EntitlementScope::platform(), true, null, $owner);
        $requestsBefore = DB::table('enterprise_purchase_requests')->count();
        $auditBefore = DB::table('audit_logs')->count();
        $this->actingAs($owner)->get(route('admin.erp.procurement.index', [
            'store_id' => $sibling->id,
            'tab' => 'requests',
        ]))->assertForbidden();
        $this->actingAs($owner)->post(route('admin.erp.procurement.requests.store'), [
            'store_id' => $sibling->id,
            'currency_code' => 'KWD',
            'reason' => 'P111 sibling legal entity denial',
            'description' => 'Entity-denied request must not persist',
            'quantity' => '1.000',
            'unit' => 'case',
        ])->assertForbidden();

        $this->assertSame($requestsBefore, DB::table('enterprise_purchase_requests')->count());
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_company_scoped_finance_http_grant_and_foreign_deny_override_platform_without_writes(): void
    {
        [$primary, $primaryScope, $foreign, $foreignScope] = $this->companyScopedStores('B2B', 'P111_FINANCE');
        $actor = $this->financeActor();
        $this->enableAndCertifyModule('finance', $actor);

        $entitlements = app(ModuleEntitlementManager::class);
        $entitlements->set('finance', EntitlementScope::platform(), false, null, $actor);
        $entitlements->set(
            'finance',
            new EntitlementScope(EntitlementScope::COMPANY, $primaryScope['company_id']),
            true,
            ['finance.foundation.read', 'finance.ap.read', 'finance.foundation.manage'],
            $actor,
        );
        $entitlements->set(
            'finance',
            new EntitlementScope(EntitlementScope::COMPANY, $foreignScope['company_id']),
            false,
            null,
            $actor,
        );

        $this->actingAs($actor->fresh())
            ->get(route('admin.erp.finance.index', ['store_id' => $primary->id, 'tab' => 'payables']))
            ->assertOk()
            ->assertViewHas('selectedContext', fn ($context): bool => is_array($context)
                && (int) ($context['id'] ?? 0) === (int) $primary->id);

        // The foreign-company DENY remains authoritative after a broad grant.
        $entitlements->set('finance', EntitlementScope::platform(), true, null, $actor);
        $accountsBefore = DB::table('enterprise_finance_accounts')->count();
        $auditBefore = DB::table('audit_logs')->count();
        $this->actingAs($actor->fresh())
            ->get(route('admin.erp.finance.index', ['store_id' => $foreign->id, 'tab' => 'payables']))
            ->assertNotFound();
        $this->actingAs($actor->fresh())
            ->post(route('admin.erp.finance.accounts.store'), [
                'store_id' => $foreign->id,
                'code' => 'P111-FINANCE-COMPANY-DENIED',
                'name' => 'Denied foreign-company account',
                'type' => 'asset',
                'normal_balance' => 'debit',
            ])
            ->assertForbidden();

        $this->assertSame($accountsBefore, DB::table('enterprise_finance_accounts')->count());
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_company_scoped_treasury_http_grant_and_foreign_deny_override_platform_without_writes(): void
    {
        [$primary, $primaryScope, $foreign, $foreignScope] = $this->companyScopedStores('B2C', 'P111_TREASURY');
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->grantPlatformErpAccess($actor);
        $retailFinance = Role::query()->where('code', 'RETAIL_FINANCE')->firstOrFail();
        foreach ([$primary, $foreign] as $store) {
            DB::table('user_store_roles')->insert([
                'user_id' => $actor->id,
                'store_id' => $store->id,
                'role_id' => $retailFinance->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $this->enableAndCertifyModule('treasury', $actor);
        $primaryCashbox = $this->cashboxFor($primary, 'P111_TREASURY_PRIMARY');
        $foreignCashbox = $this->cashboxFor($foreign, 'P111_TREASURY_FOREIGN');

        $entitlements = app(ModuleEntitlementManager::class);
        $entitlements->set('treasury', EntitlementScope::platform(), false, null, $actor);
        $entitlements->set(
            'treasury',
            new EntitlementScope(EntitlementScope::COMPANY, $primaryScope['company_id']),
            true,
            ['treasury.payments.read', 'treasury.payments.manage'],
            $actor,
        );
        $entitlements->set(
            'treasury',
            new EntitlementScope(EntitlementScope::COMPANY, $foreignScope['company_id']),
            false,
            null,
            $actor,
        );

        $this->actingAs($actor->fresh())
            ->get(route('admin.erp.treasury.index', ['store_id' => $primary->id, 'tab' => 'payments']))
            ->assertOk()
            ->assertViewHas('selectedStoreId', $primary->id)
            ->assertViewHas('canManagePayment', true);

        // The foreign-company DENY remains authoritative after a broad grant.
        $entitlements->set('treasury', EntitlementScope::platform(), true, null, $actor);
        $paymentsBefore = DB::table('enterprise_treasury_payments')->count();
        $auditBefore = DB::table('audit_logs')->count();
        $this->actingAs($actor->fresh())
            ->get(route('admin.erp.treasury.index', ['store_id' => $foreign->id, 'tab' => 'payments']))
            ->assertForbidden();
        $this->actingAs($actor->fresh())
            ->post(route('admin.erp.treasury.payments.store'), [
                'store_id' => $foreign->id,
                '_operation_key' => 'P111-TREASURY-FOREIGN-DENY',
                'target_key' => 'cashbox:'.$foreignCashbox->id,
                'amount' => '7.500',
                'value_date' => '2026-11-18',
                'reference' => 'P111-TREASURY-FOREIGN-DENY',
                'beneficiary' => 'Denied foreign-company payment',
            ])
            ->assertForbidden();

        $this->assertSame($paymentsBefore, DB::table('enterprise_treasury_payments')->count());
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
        $this->assertDatabaseHas('enterprise_cashboxes', ['id' => $primaryCashbox->id, 'store_id' => $primary->id]);
    }

    public function test_company_scoped_treasury_payment_requires_an_independent_checker(): void
    {
        $store = $this->storeInDefaultCompany('B2C', 'P111_TREASURY_SOD');
        $scope = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertIsArray($scope);

        $owner = $this->actor('SUPER_ADMIN');
        $this->enableAndCertifyModule('treasury', $owner);
        $role = Role::query()->where('code', 'RETAIL_FINANCE')->firstOrFail();
        $maker = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $checker = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        foreach ([$maker, $checker] as $actor) {
            $this->grantPlatformErpAccess($actor);
            DB::table('user_store_roles')->insert([
                'user_id' => $actor->id,
                'store_id' => $store->id,
                'role_id' => $role->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $entitlements = app(ModuleEntitlementManager::class);
        $entitlements->set('treasury', EntitlementScope::platform(), false, null, $owner);
        $entitlements->set(
            'treasury',
            new EntitlementScope(EntitlementScope::COMPANY, $scope['company_id']),
            true,
            ['treasury.payments.read', 'treasury.payments.manage', 'treasury.payments.approve'],
            $owner,
        );
        $cashbox = $this->cashboxFor($store, 'P111_TREASURY_SOD_CASHBOX');
        $payload = [
            'store_id' => $store->id,
            '_operation_key' => 'P111-TREASURY-SOD-001',
            'target_key' => 'cashbox:'.$cashbox->id,
            'amount' => '11.250',
            'value_date' => '2026-11-18',
            'reference' => 'P111-TREASURY-SOD-001',
            'beneficiary' => 'P111 company-scoped maker checker',
        ];
        $redirect = route('admin.erp.treasury.index', ['tab' => 'payments', 'store_id' => $store->id]);

        $this->actingAs($maker)->post(route('admin.erp.treasury.payments.store'), $payload)
            ->assertRedirect($redirect);
        $payment = DB::table('enterprise_treasury_payments')
            ->where('reference', 'P111-TREASURY-SOD-001')
            ->first();
        $this->assertNotNull($payment);
        $this->assertSame('draft', $payment->status);
        $this->assertSame((int) $maker->id, (int) $payment->requested_by);

        $postRoute = route('admin.erp.treasury.payments.post', ['payment' => $payment->id]);
        $auditBeforeSelfApproval = DB::table('audit_logs')->count();
        $this->actingAs($maker)->post($postRoute, ['store_id' => $store->id])
            ->assertSessionHasErrors('approver');
        $this->assertSame('draft', DB::table('enterprise_treasury_payments')->where('id', $payment->id)->value('status'));
        $this->assertSame($auditBeforeSelfApproval, DB::table('audit_logs')->count());

        $this->actingAs($checker)->post($postRoute, ['store_id' => $store->id])->assertRedirect($redirect);
        $this->assertDatabaseHas('enterprise_treasury_payments', [
            'id' => $payment->id,
            'status' => 'posted',
            'posted_by' => $checker->id,
        ]);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('event', 'enterprise.treasury.payment_posted')
            ->where('auditable_id', $payment->id)
            ->count());
    }

    public function test_company_scoped_wms_http_grant_and_foreign_deny_override_platform_without_writes(): void
    {
        [$primary, $primaryScope, $foreign, $foreignScope] = $this->companyScopedStores('B2C', 'P111_WMS');
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->grantPlatformErpAccess($actor);
        $retailInventory = Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail();
        foreach ([$primary, $foreign] as $store) {
            DB::table('user_store_roles')->insert([
                'user_id' => $actor->id,
                'store_id' => $store->id,
                'role_id' => $retailInventory->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $this->enableAndCertifyModule('inventory_wms', $actor);
        $unitId = (int) DB::table('units')->insertGetId([
            'store_id' => null,
            'scope' => 'global',
            'scope_key' => 'global',
            'code' => 'P111-WMS-COMPANY-EACH',
            'name' => 'Each',
            'name_ar' => 'قطعة',
            'name_en' => 'Each',
            'decimal_places' => 3,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $primaryStock = $this->wmsStockFor($primary, $unitId, 'P111_WMS_PRIMARY');
        $foreignStock = $this->wmsStockFor($foreign, $unitId, 'P111_WMS_FOREIGN');

        $entitlements = app(ModuleEntitlementManager::class);
        $entitlements->set('inventory_wms', EntitlementScope::platform(), false, null, $actor);
        $entitlements->set(
            'inventory_wms',
            new EntitlementScope(EntitlementScope::COMPANY, $primaryScope['company_id']),
            true,
            null,
            $actor,
        );
        $entitlements->set(
            'inventory_wms',
            new EntitlementScope(EntitlementScope::COMPANY, $foreignScope['company_id']),
            false,
            null,
            $actor,
        );

        $this->actingAs($actor->fresh())
            ->get(route('admin.erp.wms.index', [
                'tab' => 'adjustments',
                'store_id' => $primary->id,
                'warehouse_id' => $primaryStock['warehouse_id'],
            ]))
            ->assertOk()
            ->assertViewHas('storeId', $primary->id)
            ->assertViewHas('canManageAdjustments', true);

        // WMS intentionally hides a denied warehouse scope with 404.
        $entitlements->set('inventory_wms', EntitlementScope::platform(), true, null, $actor);
        $movementsBefore = DB::table('stock_movements')->count();
        $auditBefore = DB::table('audit_logs')->count();
        $foreignInventoryBefore = DB::table('inventories')
            ->where('id', $foreignStock['inventory_id'])->value('quantity');
        $this->actingAs($actor->fresh())
            ->get(route('admin.erp.wms.index', [
                'tab' => 'adjustments',
                'store_id' => $foreign->id,
                'warehouse_id' => $foreignStock['warehouse_id'],
            ]))
            ->assertNotFound();
        $this->actingAs($actor->fresh())
            ->post(route('admin.erp.wms.adjustments.store'), [
                'store_id' => $foreign->id,
                'warehouse_id' => $foreignStock['warehouse_id'],
                'product_id' => $foreignStock['product_id'],
                'quantity_delta' => -2,
                'classification' => 'damage',
                'reason' => 'Denied foreign-company adjustment',
                'request_key' => 'P111-WMS-FOREIGN-DENY',
            ])
            ->assertNotFound();

        $this->assertSame($movementsBefore, DB::table('stock_movements')->count());
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
        $this->assertSame($foreignInventoryBefore, DB::table('inventories')
            ->where('id', $foreignStock['inventory_id'])->value('quantity'));
    }

    public function test_historical_unmatched_invoice_get_keeps_pending_row_without_creating_or_approving_review_in_ar_en(): void
    {
        // An imported/legacy invoice can have no match-review row. A GET must
        // never materialize a decision, even if it could be auto-approved.
        // The write-only sync remains in the explicit invoice/review lifecycle.
        $store = $this->wholesaleStore();
        $maker = $this->actor('PROCUREMENT_BUYER');
        $checker = $this->actor('PROCUREMENT_APPROVER');
        $admin = $this->actor('SUPER_ADMIN');

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $admin);
        app(ModuleEntitlementManager::class)->set(
            'procurement',
            EntitlementScope::platform(),
            true,
            null,
            $admin,
        );
        if (class_exists(SyntheticErpWorkflowEvidence::class)) {
            SyntheticErpWorkflowEvidence::install();
        }

        $adminContext = app(TenantContextResolver::class)->wholesale($admin, $store->id);
        $makerContext = app(TenantContextResolver::class)->wholesale($maker, $store->id);
        $checkerContext = app(TenantContextResolver::class)->wholesale($checker, $store->id);

        $sku = 'P111-NO-GET-WRITE';
        $catalog = Catalog::query()->create([
            'store_id' => $store->id,
            'channel' => 'b2b',
            'code' => 'p111-no-get-write',
            'name' => 'P111 source stock',
            'is_active' => true,
            'is_migration_quarantine' => false,
        ]);
        $unitId = (int) DB::table('units')->insertGetId([
            'store_id' => null,
            'scope' => 'global',
            'scope_key' => 'global',
            'code' => 'P111-EACH-GET',
            'name' => 'Each',
            'name_ar' => 'قطعة',
            'name_en' => 'Each',
            'decimal_places' => 3,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Product::query()->create([
            'catalog_id' => $catalog->id,
            'unit_id' => $unitId,
            'sku' => $sku,
            'name' => 'P111 source stock',
            'is_active' => true,
        ]);
        $warehouse = Warehouse::query()->create([
            'store_id' => $store->id,
            'code' => 'P111-GET-WH',
            'name' => 'P111 match source',
            'is_active' => true,
        ]);

        $supplier = app(SupplierDirectory::class)->create($admin, $adminContext, [
            'scope_type' => 'store',
            'code' => 'P111-GET-SUP',
            'name_en' => 'P111 supplier',
        ]);
        $requests = app(PurchaseRequestManager::class);
        $request = $requests->create($maker, $makerContext, [
            'supplier_id' => $supplier->id,
            'currency_code' => 'KWD',
            'reason' => 'P111 unmatched invoice direct GET',
            'lines' => [[
                'item_code' => $sku,
                'description' => 'P111 source stock',
                'quantity' => 2,
                'unit' => 'each',
                'estimated_unit_cost' => 1.250,
            ]],
        ]);
        $requests->submit($maker, $makerContext, $request->id);
        $requests->approve($checker, $checkerContext, $request->id);

        $orders = app(PurchaseOrderManager::class);
        $order = $orders->createFromApprovedRequest($admin, $adminContext, $request->id);
        $order = $orders->issue($admin, $adminContext, $order->id);
        $receipts = app(GoodsReceiptManager::class);
        $receipt = $receipts->createFromIssuedOrder($admin, $adminContext, $order->id, [
            'lines' => [[
                'purchase_order_line_id' => $order->lines()->firstOrFail()->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => 2,
            ]],
        ]);
        $receipt = $receipts->post($admin, $adminContext, $receipt->id);
        $invoice = app(PurchaseInvoiceHandoffManager::class)->createFromPostedReceipt(
            $admin,
            $adminContext,
            $receipt->id,
            [
                'supplier_invoice_number' => 'P111-GET-NOWRITE',
                'invoice_date' => '2026-11-17',
                'lines' => [[
                    'goods_receipt_line_id' => $receipt->lines()->firstOrFail()->id,
                    'quantity' => 2,
                ]],
            ],
        );

        // Simulate a genuine historical invoice imported before matching was
        // materialized, after the normal authorized write workflow has ended.
        DB::table('enterprise_procurement_match_reviews')
            ->where('purchase_invoice_id', $invoice->id)
            ->delete();
        $this->assertDatabaseMissing('enterprise_procurement_match_reviews', [
            'purchase_invoice_id' => $invoice->id,
        ]);
        $auditCount = DB::table('audit_logs')->count();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $admin->forceFill(['locale' => $locale])->save();
            $this->actingAs($admin->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $store->id,
                'tab' => 'matches',
            ]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee($invoice->invoice_number);

            $this->assertDatabaseMissing('enterprise_procurement_match_reviews', [
                'purchase_invoice_id' => $invoice->id,
            ]);
            $this->assertSame($auditCount, DB::table('audit_logs')->count());
        }
        $this->assertDatabaseHas('enterprise_purchase_invoices', [
            'id' => $invoice->id,
            'status' => 'draft',
        ]);
    }

    public function test_supplier_setup_candidate_uses_existing_authority_and_validator_without_writes_in_both_locales(): void
    {
        $store = $this->wholesaleStore();
        $admin = $this->actor('SUPER_ADMIN');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $admin);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $admin,
        );

        $candidate = [
            'store_id' => $store->id,
            'scope_type' => 'store',
            'code' => 'P111-SUPPLIER-PREFLIGHT',
            'name_en' => 'Fictional Supplier Example',
            'name_ar' => 'مثال مورد خيالي',
            'payment_terms_days' => 30,
        ];
        $before = DB::table('enterprise_suppliers')->count();
        $auditBefore = DB::table('audit_logs')->count();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $admin->forceFill(['locale' => $locale])->save();
            $actor = $admin->fresh();

            $this->actingAs($actor)->get(route('admin.erp.procurement.index', [
                'store_id' => $store->id,
                'tab' => 'suppliers',
            ]))->assertOk()->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__('supplier_setup_help.test', [], $locale));

            $this->actingAs($actor)->post(route('admin.erp.procurement.suppliers.store'), [
                ...$candidate,
                'setup_action' => 'test',
            ])->assertRedirect()->assertSessionHas('status');
            $this->assertSame($before, DB::table('enterprise_suppliers')->count());
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());
        }

        $this->actingAs($admin)->post(route('admin.erp.procurement.suppliers.store'), [
            ...$candidate,
            'setup_action' => 'test',
            'payment_terms_days' => 3651,
        ])->assertSessionHasErrors(['payment_terms_days']);
        $this->actingAs($admin)->post(route('admin.erp.procurement.suppliers.store'), [
            ...$candidate,
            'setup_action' => 'unexpected',
        ])->assertSessionHasErrors(['setup_action']);
        $this->assertSame($before, DB::table('enterprise_suppliers')->count());
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());

        $this->actingAs($admin)->post(route('admin.erp.procurement.suppliers.store'), [
            ...$candidate,
            'setup_action' => 'save',
        ])->assertRedirect()->assertSessionHas('status');
        $this->assertDatabaseHas('enterprise_suppliers', [
            'code' => 'P111-SUPPLIER-PREFLIGHT',
            'scope_type' => 'store',
            'store_id' => $store->id,
        ]);
        $this->assertSame($before + 1, DB::table('enterprise_suppliers')->count());
        $this->assertSame($auditBefore + 1, DB::table('audit_logs')->count());

        $this->actingAs($admin)->post(route('admin.erp.procurement.suppliers.store'), [
            ...$candidate,
            'setup_action' => 'test',
        ])->assertSessionHasErrors(['code']);
        $this->assertSame($before + 1, DB::table('enterprise_suppliers')->count());
    }

    public function test_existing_procurement_dashboard_http_contract_is_localized_read_only_and_store_denied(): void
    {
        $store = $this->wholesaleStore();
        $owner = $this->actor('SUPER_ADMIN');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        $entitlements = app(ModuleEntitlementManager::class);
        $entitlements->set('procurement', EntitlementScope::platform(), true, null, $owner);

        $from = now()->subDays(7)->toDateString();
        $to = now()->toDateString();
        $auditBefore = DB::table('audit_logs')->count();
        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $owner->forceFill(['locale' => $locale])->save();
            $response = $this->actingAs($owner->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $store->id,
                'tab' => 'overview',
                'from' => $from,
                'to' => $to,
            ]));
            $response->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-foodex-module-overview', false);

            $dashboard = $response->viewData('dashboard');
            $this->assertSame($from, $dashboard['selected_from']);
            $this->assertSame($to, $dashboard['selected_to']);
            $this->assertSame('current', $dashboard['state']);
            $this->assertArrayHasKey('open_requests', collect($dashboard['kpis'])->keyBy('key')->all());
            $this->assertArrayHasKey('supplier_performance', $dashboard['charts']);
            $this->assertSame(
                ['b2b'],
                collect($response->viewData('storeOptions'))->pluck('channel')->unique()->values()->all(),
            );
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());
        }

        $entitlements->set(
            'procurement',
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            false,
            null,
            $owner,
        );
        $auditBeforeDeniedGet = DB::table('audit_logs')->count();
        $this->actingAs($owner->fresh())->get(route('admin.erp.procurement.index', [
            'store_id' => $store->id,
            'tab' => 'overview',
        ]))->assertForbidden();
        $this->assertSame($auditBeforeDeniedGet, DB::table('audit_logs')->count());
    }

    public function test_procurement_reports_and_csv_export_respect_store_entitlement_and_do_not_leak_sibling_orders(): void
    {
        $primary = $this->wholesaleStore();
        $sibling = Store::query()->create([
            'store_type_id' => $primary->store_type_id,
            'code' => 'P111-REPORT-SIBLING',
            'name' => 'P111 Reports Sibling',
            'is_active' => true,
        ]);
        $hierarchy = app(OrganizationHierarchyService::class);
        $primaryScope = $hierarchy->scopeForStore($primary->id);
        $this->assertIsArray($primaryScope);
        // Distinct company (not just a second store in the default company).
        // Verify both the company and selected-store disclosure boundary.
        $foreignCompany = EnterpriseCompany::query()->create([
            'organization_id' => $primaryScope['organization_id'],
            'code' => 'P111_REPORT_OTHER_COMPANY',
            'name' => 'P111 Reporting Other Company',
            'is_active' => true,
        ]);
        $foreignEntity = EnterpriseLegalEntity::query()->create([
            'company_id' => $foreignCompany->id,
            'code' => 'P111_REPORT_OTHER_ENTITY',
            'name' => 'P111 Reporting Other Entity',
            'is_active' => true,
        ]);
        $foreignUnit = EnterpriseBusinessUnit::query()->create([
            'legal_entity_id' => $foreignEntity->id,
            'code' => 'P111_REPORT_OTHER_UNIT',
            'name' => 'P111 Reporting Other Unit',
            'is_active' => true,
        ]);
        $hierarchy->assignStore($sibling, $foreignUnit);
        $siblingScope = $hierarchy->scopeForStore($sibling->id);
        $this->assertIsArray($siblingScope);
        $this->assertNotSame($primaryScope['company_id'], $siblingScope['company_id']);

        $admin = $this->actor('SUPER_ADMIN');
        $buyer = $this->actor('PROCUREMENT_BUYER');
        $checker = $this->actor('PROCUREMENT_APPROVER');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $admin);
        $entitlements = app(ModuleEntitlementManager::class);
        $entitlements->set('procurement', EntitlementScope::platform(), true, null, $admin);

        $adminContext = app(TenantContextResolver::class)->wholesale($admin, $primary->id);
        $buyerContext = app(TenantContextResolver::class)->wholesale($buyer, $primary->id);
        $checkerContext = app(TenantContextResolver::class)->wholesale($checker, $primary->id);

        $supplier = app(SupplierDirectory::class)->create($admin, $adminContext, [
            'scope_type' => 'store',
            'code' => 'P111-REPORT-SUP',
            'name_en' => 'P111 Reports Supplier',
        ]);
        $requests = app(PurchaseRequestManager::class);
        $request = $requests->create($buyer, $buyerContext, [
            'supplier_id' => $supplier->id,
            'currency_code' => 'KWD',
            'reason' => 'P111 scoped reports origin',
            'lines' => [[
                'item_code' => 'P111-REPORT-SKU',
                'description' => 'Scoped source order',
                'quantity' => 2,
                'unit' => 'each',
                'estimated_unit_cost' => '1.250',
            ]],
        ]);
        $requests->submit($buyer, $buyerContext, $request->id);
        $requests->approve($checker, $checkerContext, $request->id);
        $order = app(PurchaseOrderManager::class)
            ->createFromApprovedRequest($admin, $adminContext, $request->id);

        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $request->id,
            'created_by' => $buyer->id,
            'decided_by' => $checker->id,
            'status' => 'approved',
        ]);
        $auditBefore = DB::table('audit_logs')->count();
        $ordersBefore = DB::table('enterprise_purchase_orders')->count();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $admin->forceFill(['locale' => $locale])->save();
            $actor = $admin->fresh();

            $this->actingAs($actor)->get(route('admin.erp.procurement.index', [
                'store_id' => $primary->id,
                'tab' => 'reports',
                'report' => 'open_orders',
            ]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee($order->order_number);

            $authorizedCsv = $this->actingAs($actor)->get(
                route('admin.erp.procurement.reports.export', [
                    'store_id' => $primary->id,
                    'report' => 'open_orders',
                ]),
            );
            $authorizedCsv->assertOk();
            $this->assertStringContainsString($order->order_number, $authorizedCsv->streamedContent());

            // Global B2B role and platform entitlement do not override an
            // explicit STORE denial on either report or download endpoints.
            $entitlements->set(
                'procurement',
                new EntitlementScope(EntitlementScope::STORE, $primary->id),
                false,
                null,
                $admin,
            );

            $this->actingAs($admin->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $primary->id,
                'tab' => 'reports',
                'report' => 'open_orders',
            ]))->assertForbidden();
            $this->actingAs($admin->fresh())->get(route('admin.erp.procurement.reports.export', [
                'store_id' => $primary->id,
                'report' => 'open_orders',
            ]))->assertForbidden();

            // A separate entitled B2B store must not inherit the first
            // company's purchase order through dashboard or CSV export.
            $this->actingAs($admin->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $sibling->id,
                'tab' => 'reports',
                'report' => 'open_orders',
            ]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertDontSee($order->order_number);

            $siblingCsv = $this->actingAs($admin->fresh())->get(
                route('admin.erp.procurement.reports.export', [
                    'store_id' => $sibling->id,
                    'report' => 'open_orders',
                ]),
            );
            $siblingCsv->assertOk();
            $this->assertStringNotContainsString($order->order_number, $siblingCsv->streamedContent());

            $entitlements->set(
                'procurement',
                new EntitlementScope(EntitlementScope::STORE, $primary->id),
                true,
                null,
                $admin,
            );
        }

        $this->assertSame($ordersBefore, DB::table('enterprise_purchase_orders')->count());
        $this->assertDatabaseHas('enterprise_purchase_orders', [
            'id' => $order->id,
            'store_id' => $primary->id,
        ]);
        // Entitlement setup is audited separately; read/export requests must
        // never create a purchase-order or finance-posting audit event.
        $this->assertSame(0, DB::table('audit_logs')
            ->where('event', 'enterprise.purchase_order.issued')
            ->where('auditable_id', $order->id)
            ->count());
        $this->assertGreaterThanOrEqual($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_procurement_erp_center_sidebar_http_readiness_parity_for_four_roles(): void
    {
        // A production-like isolated DB must never let the local Super Admin
        // bootstrap create operational approvals or entitlements on GET.
        $this->app['env'] = 'production';
        $store = $this->wholesaleStore();
        $owner = $this->actor('SUPER_ADMIN');
        $actors = [
            'PROCUREMENT_BUYER' => $this->actor('PROCUREMENT_BUYER'),
            'PROCUREMENT_MANAGER' => $this->actor('PROCUREMENT_MANAGER'),
            'PROCUREMENT_APPROVER' => $this->actor('PROCUREMENT_APPROVER'),
            'SUPER_ADMIN' => $owner,
        ];

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $owner,
        );
        config()->set('enterprise_module_readiness.certified_versions.procurement', null);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            foreach ($actors as $role => $actor) {
                $actor->forceFill(['locale' => $locale])->save();
                $user = $actor->fresh();
                $page = $this->actingAs($user)->get(route('admin.erp.index', ['tab' => 'modules']));
                $page->assertOk()->assertSee('dir="'.$direction.'"', false);
                $card = collect($page->viewData('cards'))->firstWhere('key', 'procurement');
                $this->assertIsArray($card, $role);
                $this->assertSame('degraded', $card['status'], $role);
                $this->assertSame('uncertified', $card['denial_reason'], $role);
                $this->assertNull($card['url'], $role);
                $this->assertSame('healthy', $card['health'], 'Health alone must not certify '.$role);
                $page->assertSeeText(
                    __('admin.erp_center.denial_reasons.uncertified', [], $locale),
                );

                $item = collect(app(AdminNavigation::class)->groupsFor($user))
                    ->flatMap(static fn (array $group): array => $group['children'])
                    ->firstWhere('key', 'erp_procurement');
                if ($role === 'SUPER_ADMIN') {
                    // AdminNavigation reserves an uncertified module's
                    // diagnostic Sidebar entry for the registered Platform
                    // Owner, not every Super Admin. The existing platform
                    // management HTTP action is independently authorized;
                    // never invent a business or diagnostic menu grant.
                    $this->assertNull($item, 'No unready Procurement Sidebar grant for Super Admin');
                    $this->actingAs($user)
                        ->get(route('admin.enterprise-modules.show', ['module' => 'procurement']))
                        ->assertOk();
                } else {
                    $this->assertNull($item, 'Uncertified business route visible to '.$role);
                    $this->actingAs($user)
                        ->get(route('admin.enterprise-modules.show', ['module' => 'procurement']))
                        ->assertForbidden();
                }

                // HTTP readiness must forbid direct URL entry even for an
                // authenticated role and without a valid operational tab.
                $this->actingAs($user)->get(route('admin.erp.procurement.index', [
                    'store_id' => $store->id,
                    'tab' => 'requests',
                ]))->assertForbidden();
            }
        }

        config()->set(
            'enterprise_module_readiness.certified_versions.procurement',
            app(ModuleRegistry::class)->manifest('procurement')->version,
        );

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            foreach ($actors as $role => $actor) {
                $actor->forceFill(['locale' => $locale])->save();
                $user = $actor->fresh();
                $page = $this->actingAs($user)->get(route('admin.erp.index'));
                $page->assertOk()->assertSee('dir="'.$direction.'"', false);
                $card = collect($page->viewData('cards'))->firstWhere('key', 'procurement');
                $this->assertIsArray($card, $role);
                $this->assertSame('available', $card['status'], $role);
                $this->assertSame(route('admin.erp.procurement.index'), $card['url'], $role);
                $item = collect(app(AdminNavigation::class)->groupsFor($user))
                    ->flatMap(static fn (array $group): array => $group['children'])
                    ->firstWhere('key', 'erp_procurement');
                $this->assertIsArray($item, $role);
                $this->assertSame('admin.erp.procurement.index', $item['route'], $role);
                $this->actingAs($user)->get(route('admin.erp.procurement.index', [
                    'store_id' => $store->id,
                    'tab' => 'requests',
                ]))->assertOk()->assertSee('dir="'.$direction.'"', false);
                // Every advertised operational tab is a real role-authorized
                // HTTP destination, not merely a visible link on ERP Center.
                foreach (['suppliers', 'orders'] as $tab) {
                    $tabResponse = $this->actingAs($user)->get(route('admin.erp.procurement.index', [
                        'store_id' => $store->id,
                        'tab' => $tab,
                    ]));
                    $tabResponse->assertOk()->assertSee('dir="'.$direction.'"', false);
                    $this->assertSame($tab, $tabResponse->viewData('activeTab'));
                    $this->assertContains($tab, collect($tabResponse->viewData('tabs'))->pluck('key')->all());
                }

                $this->actingAs($user)->get(route('admin.erp.procurement.index', [
                    'store_id' => $store->id,
                    'tab' => 'invalid-tab-forged',
                ]))->assertNotFound();
            }
        }
    }

    public function test_revoked_procurement_store_entitlement_denies_direct_workbench_access_in_both_locales(): void
    {
        $this->app['env'] = 'production';
        $store = $this->wholesaleStore();
        $owner = $this->actor('SUPER_ADMIN');
        $actors = [
            'PROCUREMENT_BUYER' => $this->actor('PROCUREMENT_BUYER'),
            'PROCUREMENT_MANAGER' => $this->actor('PROCUREMENT_MANAGER'),
            'PROCUREMENT_APPROVER' => $this->actor('PROCUREMENT_APPROVER'),
            'SUPER_ADMIN' => $owner,
        ];

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        config()->set(
            'enterprise_module_readiness.certified_versions.procurement',
            app(ModuleRegistry::class)->manifest('procurement')->version,
        );
        $entitlements = app(ModuleEntitlementManager::class);
        $entitlements->set('procurement', EntitlementScope::platform(), true, null, $owner);
        $entitlements->set(
            'procurement',
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            false,
            null,
            $owner,
        );
        $this->assertDatabaseHas('enterprise_module_entitlements', [
            'module_code' => 'procurement',
            'scope_key' => 'store:'.$store->id,
            'is_enabled' => false,
        ]);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            foreach ($actors as $actor) {
                $actor->forceFill(['locale' => $locale])->save();
                $user = $actor->fresh();
                $this->actingAs($user)->get(route('admin.erp.procurement.index', [
                    'store_id' => $store->id,
                    'tab' => 'requests',
                ]))->assertForbidden();
            }
        }
    }

    public function test_procurement_tab_permissions_deny_suppliers_and_orders_without_hiding_authorized_requests(): void
    {
        $this->app['env'] = 'production';
        $store = $this->wholesaleStore();
        $owner = $this->actor('SUPER_ADMIN');
        $buyer = $this->actor('PROCUREMENT_BUYER');

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $owner,
        );
        foreach (['procurement.suppliers.view', 'procurement.purchase_orders.view'] as $code) {
            DB::table('user_permission_overrides')->insert([
                'user_id' => $buyer->id,
                'permission_id' => Permission::query()->where('code', $code)->firstOrFail()->id,
                'scope_key' => 'global',
                'store_id' => null,
                'effect' => 'deny',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $buyer->forceFill(['has_permission_overrides' => true])->save();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $buyer->forceFill(['locale' => $locale])->save();
            $actor = $buyer->fresh();

            $this->assertTrue($actor->hasPermission('procurement.purchase_requests.view', $store->id));
            $this->assertFalse($actor->hasPermission('procurement.suppliers.view', $store->id));
            $this->assertFalse($actor->hasPermission('procurement.purchase_orders.view', $store->id));

            $requests = $this->actingAs($actor)->get(route('admin.erp.procurement.index', [
                'store_id' => $store->id,
                'tab' => 'requests',
            ]));
            $requests->assertOk()->assertSee('dir="'.$direction.'"', false);
            $visible = collect($requests->viewData('tabs'))->pluck('key')->all();
            $this->assertContains('requests', $visible);
            $this->assertNotContains('suppliers', $visible);
            $this->assertNotContains('orders', $visible);

            foreach (['suppliers', 'orders'] as $forbiddenTab) {
                $this->actingAs($actor)->get(route('admin.erp.procurement.index', [
                    'store_id' => $store->id,
                    'tab' => $forbiddenTab,
                ]))->assertForbidden();
            }
        }
    }

    public function test_procurement_lifecycle_and_stale_manifest_fail_closed_for_card_and_direct_http(): void
    {
        // Only disposable signed evidence is used by this suite; these are
        // HTTP gate tests, never an operational G0-G13 certification.
        $this->app['env'] = 'production';
        $store = $this->wholesaleStore();
        $owner = $this->actor('SUPER_ADMIN');
        $buyer = $this->actor('PROCUREMENT_BUYER');
        $lifecycle = app(EnterpriseModuleAdministration::class);
        $lifecycle->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $owner,
        );
        $manifestVersion = app(ModuleRegistry::class)->manifest('procurement')->version;

        foreach (['suspend', 'disable'] as $action) {
            $lifecycle->perform('procurement', $action, $owner);
            foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
                app()->setLocale($locale);
                $owner->forceFill(['locale' => $locale])->save();
                $buyer->forceFill(['locale' => $locale])->save();

                $center = $this->actingAs($owner->fresh())->get(route('admin.erp.index'));
                $center->assertOk()->assertSee('dir="'.$direction.'"', false);
                $card = collect($center->viewData('cards'))->firstWhere('key', 'procurement');
                $this->assertIsArray($card);
                $this->assertNotSame('available', $card['status']);
                $this->assertNull($card['url']);

                $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                    'store_id' => $store->id,
                    'tab' => 'requests',
                ]))->assertForbidden();
            }
            $lifecycle->perform('procurement', 'enable', $owner);
        }

        config()->set('enterprise_module_readiness.certified_versions.procurement', $manifestVersion + 1);
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $owner->forceFill(['locale' => $locale])->save();
            $buyer->forceFill(['locale' => $locale])->save();
            $center = $this->actingAs($owner->fresh())->get(route('admin.erp.index'));
            $center->assertOk();
            $card = collect($center->viewData('cards'))->firstWhere('key', 'procurement');
            $this->assertIsArray($card);
            $this->assertSame('degraded', $card['status']);
            $this->assertSame('uncertified', $card['denial_reason']);
            $this->assertNull($card['url']);
            $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $store->id,
                'tab' => 'requests',
            ]))->assertForbidden();
        }
    }

    public function test_broken_procurement_health_probe_degrades_card_and_denies_http_without_writes_in_both_locales(): void
    {
        // Probe configuration is a server-side operational requirement, not a
        // UI preference or an entitlement that an enabled module can bypass.
        $this->app['env'] = 'production';
        // Model a valid production CSRF submission: a 403 must originate in
        // Procurement authorization/readiness, never in the unrelated 419 gate.
        $csrfToken = 'p111-csrf-token-in-request';
        $this->withSession(['_token' => $csrfToken])
            ->withHeader('X-CSRF-TOKEN', $csrfToken);
        $store = $this->wholesaleStore();
        $owner = $this->actor('SUPER_ADMIN');
        $buyer = $this->actor('PROCUREMENT_BUYER');

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $owner,
        );
        // Invalid probe configuration triggers an actual ModuleHealthService
        // ERROR, not a manually forged health/status tag.
        config()->set('enterprise_module_health.probes.procurement', ['nonexistent-procurement-health-probe']);

        $reason = 'P111 unhealthy probe must never create request';
        $requestsBefore = DB::table('enterprise_purchase_requests')->count();
        $auditBefore = DB::table('audit_logs')->count();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $owner->forceFill(['locale' => $locale])->save();
            $buyer->forceFill(['locale' => $locale])->save();

            $page = $this->actingAs($owner->fresh())->get(route('admin.erp.index'));
            $page->assertOk()->assertSee('dir="'.$direction.'"', false);
            $card = collect($page->viewData('cards'))->firstWhere('key', 'procurement');
            $this->assertIsArray($card);
            $this->assertSame('degraded', $card['status']);
            $this->assertSame('unhealthy', $card['denial_reason']);
            $this->assertSame('error', $card['health']);
            $this->assertNull($card['url'], 'Failed health must suppress business CTA');

            $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $store->id,
                'tab' => 'requests',
            ]))->assertForbidden();
            $this->actingAs($buyer->fresh())->post(route('admin.erp.procurement.requests.store'), [
                'store_id' => $store->id,
                'currency_code' => 'KWD',
                'reason' => $reason,
                'item_code' => 'P111-HEALTH-DENY',
                'description' => 'Must be blocked before mutation',
                'quantity' => '1.000',
                'unit' => 'case',
                'estimated_unit_cost' => '2.000',
            ])->assertForbidden();
        }

        $this->assertSame($requestsBefore, DB::table('enterprise_purchase_requests')->count());
        $this->assertDatabaseMissing('enterprise_purchase_requests', ['reason' => $reason]);
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_missing_store_entitlement_and_missing_required_certification_cannot_be_bypassed_by_reenable(): void
    {
        // Once Procurement is enrolled in the entitlement policy, absent
        // effective STORE grants fail closed even if another B2B store is allowed.
        $this->app['env'] = 'production';
        // Model a valid production CSRF submission: a 403 must originate in
        // Procurement authorization/readiness, never in the unrelated 419 gate.
        $csrfToken = 'p111-csrf-token-in-request';
        $this->withSession(['_token' => $csrfToken])
            ->withHeader('X-CSRF-TOKEN', $csrfToken);
        $primary = $this->wholesaleStore();
        $sibling = Store::query()->create([
            'store_type_id' => $primary->store_type_id,
            'code' => 'P111-MISSING-ENTITLEMENT-SIBLING',
            'name' => 'P111 entitlement sibling',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($sibling);

        $owner = $this->actor('SUPER_ADMIN');
        $buyer = $this->actor('PROCUREMENT_BUYER');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        $entitlements = app(ModuleEntitlementManager::class);
        $entitlements->set(
            'procurement',
            new EntitlementScope(EntitlementScope::STORE, $sibling->id),
            true,
            null,
            $owner,
        );
        $reason = 'P111 absent entitlement must never create request';
        $countBefore = DB::table('enterprise_purchase_requests')->count();
        $auditBefore = DB::table('audit_logs')->count();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $buyer->forceFill(['locale' => $locale])->save();
            $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $primary->id,
                'tab' => 'requests',
            ]))->assertForbidden();
            $this->actingAs($buyer->fresh())->post(route('admin.erp.procurement.requests.store'), [
                'store_id' => $primary->id,
                'currency_code' => 'KWD',
                'reason' => $reason,
                'item_code' => 'P111-ENTITLEMENT-DENY',
                'description' => 'Cannot write through an absent scope grant',
                'quantity' => '1.000',
                'unit' => 'case',
                'estimated_unit_cost' => '1.000',
            ])->assertForbidden();
            // The other entitled B2B tenant can still perform its own read.
            $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $sibling->id,
                'tab' => 'requests',
            ]))->assertOk();
        }

        $this->assertSame($countBefore, DB::table('enterprise_purchase_requests')->count());
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());

        // A newly granted store scope does not override an absent exact-version
        // operational certification. The manifest/version requirement stays
        // independent of entitlement and channel/role admission.
        $entitlements->set(
            'procurement',
            new EntitlementScope(EntitlementScope::STORE, $primary->id),
            true,
            null,
            $owner,
        );
        // Entitlement configuration is itself an authorized, audited write.
        // Only forbidden HTTP and subsequent GET must be free of extra audit
        // side effects; do not compare across the owner's legitimate grant.
        $auditAfterGrant = DB::table('audit_logs')->count();
        config()->set('enterprise_module_readiness.certified_versions.procurement', null);
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $buyer->forceFill(['locale' => $locale])->save();
            $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $primary->id,
                'tab' => 'requests',
            ]))->assertForbidden();
        }

        // A test-only exact-manifest readiness fixture restores authorized GET
        // after every independent guard passes. This is NOT real G0-G13 proof.
        config()->set(
            'enterprise_module_readiness.certified_versions.procurement',
            app(ModuleRegistry::class)->manifest('procurement')->version,
        );
        $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
            'store_id' => $primary->id,
            'tab' => 'requests',
        ]))->assertOk();
        $this->assertSame($countBefore, DB::table('enterprise_purchase_requests')->count());
        $this->assertSame($auditAfterGrant, DB::table('audit_logs')->count());
    }

    public function test_forged_nonpositive_or_malformed_store_id_never_falls_back_to_an_authorized_store(): void
    {
        $this->app['env'] = 'production';
        $csrfToken = 'p111-forged-store-selector-csrf';
        $this->withSession(['_token' => $csrfToken])->withHeader('X-CSRF-TOKEN', $csrfToken);
        $store = $this->wholesaleStore();
        $owner = $this->actor('SUPER_ADMIN');
        $buyer = $this->actor('PROCUREMENT_BUYER');

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $owner,
        );

        $this->actingAs($buyer)->get(route('admin.erp.procurement.index', [
            'store_id' => $store->id,
            'tab' => 'requests',
        ]))->assertOk();
        $auditBefore = DB::table('audit_logs')->count();
        $requestsBefore = DB::table('enterprise_purchase_requests')->count();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $buyer->forceFill(['locale' => $locale])->save();

            // "Ngarbage", fractional/exponent notation and nested form/query
            // arrays must not be coerced into a valid tenant store ID.
            foreach ([
                0, -1, 'not-a-store',
                $store->id.'garbage',
                $store->id.'.0',
                $store->id.'e0',
                [$store->id],
                ['id' => $store->id],
            ] as $invalidStoreId) {
                $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                    'store_id' => $invalidStoreId,
                    'tab' => 'requests',
                ]))->assertForbidden();
                $this->actingAs($buyer->fresh())->post(route('admin.erp.procurement.requests.store'), [
                    'store_id' => $invalidStoreId,
                    'currency_code' => 'KWD',
                    'reason' => 'P111 forged store selector must never persist',
                    'item_code' => 'P111-FORGED-STORE',
                    'description' => 'Unauthorized selected-store fallback',
                    'quantity' => '1.000',
                    'unit' => 'case',
                    'estimated_unit_cost' => '1.000',
                ])->assertForbidden();
            }
        }

        $this->assertSame($requestsBefore, DB::table('enterprise_purchase_requests')->count());
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_default_procurement_store_picker_excludes_store_deny_and_uses_entitled_sibling(): void
    {
        $primary = $this->wholesaleStore();
        $sibling = Store::query()->create([
            'store_type_id' => $primary->store_type_id,
            'code' => 'P111-DEFAULT-ENTITLED-B2B',
            'name' => 'P111 entitled B2B sibling',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($sibling);

        $owner = $this->actor('SUPER_ADMIN');
        $buyer = $this->actor('PROCUREMENT_BUYER');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        $entitlements = app(ModuleEntitlementManager::class);
        $entitlements->set('procurement', EntitlementScope::platform(), true, null, $owner);
        $entitlements->set(
            'procurement', new EntitlementScope(EntitlementScope::STORE, $primary->id),
            false, null, $owner,
        );
        $auditBefore = DB::table('audit_logs')->count();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $buyer->forceFill(['locale' => $locale])->save();
            $page = $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'tab' => 'requests',
            ]));
            $page->assertOk()->assertSee('dir="'.$direction.'"', false);
            $this->assertSame((int) $sibling->id, (int) $page->viewData('selectedStoreId'));
            $visibleStoreIds = collect($page->viewData('storeOptions'))->pluck('id')->all();
            $this->assertContains((int) $sibling->id, $visibleStoreIds);
            $this->assertNotContains((int) $primary->id, $visibleStoreIds);

            $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $primary->id,
                'tab' => 'requests',
            ]))->assertForbidden();
        }

        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_deactivated_super_admin_cannot_read_or_mutate_procurement_even_with_historical_role(): void
    {
        $store = $this->wholesaleStore();
        $owner = $this->actor('SUPER_ADMIN');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $owner,
        );
        $owner->forceFill(['is_active' => false])->save();

        $this->actingAs($owner->fresh())->get(route('admin.erp.procurement.index', [
            'store_id' => $store->id,
            'tab' => 'requests',
        ]))->assertForbidden();
        $this->actingAs($owner->fresh())->post(route('admin.erp.procurement.requests.store'), [
            'store_id' => $store->id,
            'currency_code' => 'KWD',
            'reason' => 'P111 inactive administrator cannot mutate',
            'item_code' => 'P111-INACTIVE',
            'description' => 'No inactive business mutation',
            'quantity' => '1.000',
            'unit' => 'case',
            'estimated_unit_cost' => '1.000',
        ])->assertForbidden();
        $this->assertDatabaseMissing('enterprise_purchase_requests', [
            'reason' => 'P111 inactive administrator cannot mutate',
        ]);
    }

    public function test_super_admin_explicit_global_and_store_permission_denies_override_procurement_actions_in_ar_en(): void
    {
        // The canonical User::hasPermission() grants SUPER_ADMIN implicitly.
        // Selected Procurement business actions must still honor explicit
        // user-specific GLOBAL and STORE deny overrides at their HTTP guard.
        $this->app['env'] = 'production';
        $csrfToken = 'p111-admin-deny-csrf';
        $this->withSession(['_token' => $csrfToken])->withHeader('X-CSRF-TOKEN', $csrfToken);

        $store = $this->wholesaleStore();
        $owner = $this->actor('SUPER_ADMIN');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $owner,
        );

        $this->actingAs($owner)->get(route('admin.erp.procurement.index', [
            'store_id' => $store->id,
            'tab' => 'suppliers',
        ]))->assertOk();

        foreach ([
            ['permission' => 'procurement.suppliers.view', 'scope_key' => 'global', 'store_id' => null],
            ['permission' => 'procurement.suppliers.manage', 'scope_key' => 'global', 'store_id' => null],
            ['permission' => 'procurement.purchase_requests.manage', 'scope_key' => 'store:'.$store->id, 'store_id' => $store->id],
        ] as $deny) {
            DB::table('user_permission_overrides')->insert([
                'user_id' => $owner->id,
                'permission_id' => Permission::query()->where('code', $deny['permission'])->firstOrFail()->id,
                'scope_key' => $deny['scope_key'],
                'store_id' => $deny['store_id'],
                'effect' => 'deny',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $owner->forceFill(['has_permission_overrides' => true])->save();

        $supplierCount = DB::table('enterprise_suppliers')->count();
        $requestCount = DB::table('enterprise_purchase_requests')->count();
        $auditCount = DB::table('audit_logs')->count();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $owner->forceFill(['locale' => $locale])->save();
            $admin = $owner->fresh();

            // A permitted read remains usable, while denied tabs disappear.
            $allowed = $this->actingAs($admin)->get(route('admin.erp.procurement.index', [
                'store_id' => $store->id,
                'tab' => 'requests',
            ]))->assertOk()->assertSee('dir="'.$direction.'"', false);
            $visible = collect($allowed->viewData('tabs'))->pluck('key')->all();
            $this->assertContains('requests', $visible);
            $this->assertNotContains('suppliers', $visible);
            $this->assertNotContains('overview', $visible);

            $this->actingAs($admin)->get(route('admin.erp.procurement.index', [
                'store_id' => $store->id,
                'tab' => 'suppliers',
            ]))->assertForbidden();
            $this->actingAs($admin)->post(route('admin.erp.procurement.suppliers.store'), [
                'store_id' => $store->id,
                'scope_type' => 'store',
                'code' => 'P111-ADMIN-DENIED-SUPPLIER',
                'name_en' => 'Must not be created',
            ])->assertForbidden();
            $this->actingAs($admin)->post(route('admin.erp.procurement.requests.store'), [
                'store_id' => $store->id,
                'currency_code' => 'KWD',
                'reason' => 'P111 denied Admin mutation must not persist',
                'item_code' => 'P111-DENIED-ADMIN-REQ',
                'description' => 'No Admin override bypass',
                'quantity' => '1.000',
                'unit' => 'case',
                'estimated_unit_cost' => '1.000',
            ])->assertForbidden();
        }

        $this->assertSame($supplierCount, DB::table('enterprise_suppliers')->count());
        $this->assertSame($requestCount, DB::table('enterprise_purchase_requests')->count());
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }

    public function test_forged_retail_without_enterprise_hierarchy_is_403_before_setup_409_in_both_locales(): void
    {
        $this->app['env'] = 'production';
        $csrfToken = 'p111-forged-retail-no-hierarchy-csrf';
        $this->withSession(['_token' => $csrfToken])->withHeader('X-CSRF-TOKEN', $csrfToken);

        $wholesale = $this->wholesaleStore();
        $retail = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'P111-FORGED-RETAIL-NO-HIERARCHY',
            'name' => 'P111 forged Retail without enterprise scope',
            'is_active' => true,
        ]);
        // Deliberately leave the Retail hierarchy unconfigured. Its missing
        // setup must not turn an authorization DENY into HTTP 409.
        $this->assertDatabaseMissing('enterprise_store_scopes', ['store_id' => $retail->id]);
        $owner = $this->actor('SUPER_ADMIN');
        $buyer = $this->actor('PROCUREMENT_BUYER');
        $buyer->storeRoleAssignments()->create([
            'store_id' => $retail->id,
            'role_id' => Role::query()->where('code', 'RETAIL_FINANCE')->firstOrFail()->id,
        ]);

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $owner,
        );

        $this->assertFalse($buyer->fresh()->hasPermission('procurement.purchase_requests.view', $retail->id));
        $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
            'store_id' => $wholesale->id,
            'tab' => 'requests',
        ]))->assertOk();

        $requestsBefore = DB::table('enterprise_purchase_requests')->count();
        $auditBefore = DB::table('audit_logs')->count();
        $reason = 'P111 forged Retail must not reach missing-hierarchy 409';

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $buyer->forceFill(['locale' => $locale])->save();

            $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $retail->id,
                'tab' => 'requests',
            ]))->assertForbidden();

            $this->actingAs($buyer->fresh())->post(route('admin.erp.procurement.requests.store'), [
                'store_id' => $retail->id,
                'currency_code' => 'KWD',
                'reason' => $reason,
                'item_code' => 'P111-FORGED-RETAIL-NO-HIERARCHY',
                'description' => 'Retail forgery cannot become a setup warning',
                'quantity' => '1.000',
                'unit' => 'case',
                'estimated_unit_cost' => '1.000',
            ])->assertForbidden();
        }

        $this->assertSame($requestsBefore, DB::table('enterprise_purchase_requests')->count());
        $this->assertDatabaseMissing('enterprise_purchase_requests', ['reason' => $reason]);
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_action_permission_denial_precedes_unconfigured_store_409_for_get_and_post(): void
    {
        $this->app['env'] = 'production';
        $csrfToken = 'p111-specific-operation-denial-csrf';
        $this->withSession(['_token' => $csrfToken])->withHeader('X-CSRF-TOKEN', $csrfToken);

        $configured = $this->wholesaleStore();
        $unconfigured = Store::query()->create([
            'store_type_id' => $configured->store_type_id,
            'code' => 'P111-PARTIAL-PERMISSION-UNCONFIGURED',
            'name' => 'P111 partially authorized but missing hierarchy',
            'is_active' => true,
        ]);
        // No hierarchy: only a genuinely authorized action can reach 409.
        $this->assertDatabaseMissing('enterprise_store_scopes', ['store_id' => $unconfigured->id]);
        $owner = $this->actor('SUPER_ADMIN');
        $buyer = $this->actor('PROCUREMENT_BUYER');
        $approver = $this->actor('PROCUREMENT_APPROVER');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $owner,
        );

        // Buyer retains Supplier read/manage but an explicit global DENY
        // revokes both Request read and Request manage on every B2B store.
        foreach (['procurement.purchase_requests.view', 'procurement.purchase_requests.manage'] as $code) {
            DB::table('user_permission_overrides')->insert([
                'user_id' => $buyer->id,
                'permission_id' => Permission::query()->where('code', $code)->firstOrFail()->id,
                'scope_key' => 'global',
                'store_id' => null,
                'effect' => 'deny',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $buyer->forceFill(['has_permission_overrides' => true])->save();
        $this->assertTrue($buyer->fresh()->hasPermission('procurement.suppliers.view', $configured->id));
        $this->assertTrue($buyer->fresh()->hasPermission('procurement.suppliers.manage', $configured->id));
        $this->assertFalse($buyer->fresh()->hasPermission('procurement.purchase_requests.view', $configured->id));
        $this->assertFalse($buyer->fresh()->hasPermission('procurement.purchase_requests.manage', $configured->id));
        $this->assertFalse($approver->fresh()->hasPermission('procurement.suppliers.manage', $configured->id));

        $reason = 'P111 denied action cannot create purchase request';
        $auditBefore = DB::table('audit_logs')->count();
        $requestsBefore = DB::table('enterprise_purchase_requests')->count();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $buyer->forceFill(['locale' => $locale])->save();
            $approver->forceFill(['locale' => $locale])->save();

            // Valid role+store+entitlement still allows an unrelated Supplier
            // read: the permission DENY applies to the exact requested action.
            $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $configured->id,
                'tab' => 'suppliers',
            ]))->assertOk()->assertSee('dir="'.$direction.'"', false);

            $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $configured->id,
                'tab' => 'requests',
            ]))->assertForbidden();

            // Same actor and unconfigured B2B store: Supplier authority may
            // reach setup 409; explicit Request DENY must return 403 first.
            $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $unconfigured->id,
                'tab' => 'suppliers',
            ]))->assertStatus(409);
            $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $unconfigured->id,
                'tab' => 'requests',
            ]))->assertForbidden();

            $this->actingAs($buyer->fresh())->post(route('admin.erp.procurement.requests.store'), [
                'store_id' => $unconfigured->id,
                'currency_code' => 'KWD',
                'reason' => $reason,
                'item_code' => 'P111-DENIED-ACTION',
                'description' => 'Explicit deny must precede missing scope',
                'quantity' => '1.000',
                'unit' => 'case',
                'estimated_unit_cost' => '1.000',
            ])->assertForbidden();

            // An Approver can read Suppliers, but cannot execute the
            // Supplier-manage POST, even if the enterprise store is unconfigured.
            $this->actingAs($approver->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $unconfigured->id,
                'tab' => 'suppliers',
            ]))->assertStatus(409);
            $this->actingAs($approver->fresh())->post(route('admin.erp.procurement.suppliers.store'), [
                'store_id' => $unconfigured->id,
                'scope_type' => 'store',
                'code' => 'P111-DENIED-SUPPLIER-MANAGE',
                'name_en' => 'Never persisted',
            ])->assertForbidden();
        }

        $this->assertSame($requestsBefore, DB::table('enterprise_purchase_requests')->count());
        $this->assertDatabaseMissing('enterprise_purchase_requests', ['reason' => $reason]);
        $this->assertDatabaseMissing('enterprise_suppliers', ['code' => 'P111-DENIED-SUPPLIER-MANAGE']);
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_every_procurement_http_action_has_an_explicit_permission_precheck(): void
    {
        // Future routes must fail closed until their service-level permissions
        // are deliberately mapped at the canonical Procurement controller.
        $constant = (new \ReflectionClass(EnterpriseProcurementController::class))
            ->getReflectionConstant('ACTION_PERMISSIONS');
        $this->assertInstanceOf(\ReflectionClassConstant::class, $constant);
        $mapped = array_keys($constant->getValue());
        sort($mapped);

        $registered = collect(array_keys(Route::getRoutes()->getRoutesByName()))
            ->filter(static fn (string $name): bool => str_starts_with($name, 'admin.erp.procurement.')
                && $name !== 'admin.erp.procurement.index')
            ->sort()
            ->values()
            ->all();
        $this->assertSame($registered, $mapped);
    }

    public function test_authorized_b2b_store_without_enterprise_hierarchy_returns_409_after_authority_checks(): void
    {
        $this->app['env'] = 'production';
        $csrfToken = 'p111-authorized-missing-hierarchy-csrf';
        $this->withSession(['_token' => $csrfToken])->withHeader('X-CSRF-TOKEN', $csrfToken);

        $primary = $this->wholesaleStore();
        $unconfigured = Store::query()->create([
            'store_type_id' => $primary->store_type_id,
            'code' => 'P111-B2B-MISSING-ENTERPRISE-SCOPE',
            'name' => 'P111 authorized but unconfigured B2B',
            'is_active' => true,
        ]);
        // Deliberately DO NOT call ensureDefaultScopeForStore(): this is an
        // authorized-but-unconfigured hierarchy, unlike a forged Retail scope.
        $owner = $this->actor('SUPER_ADMIN');
        $buyer = $this->actor('PROCUREMENT_BUYER');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $owner,
        );
        $this->actingAs($buyer)->get(route('admin.erp.procurement.index', [
            'store_id' => $primary->id,
            'tab' => 'requests',
        ]))->assertOk();

        $auditBefore = DB::table('audit_logs')->count();
        $requestsBefore = DB::table('enterprise_purchase_requests')->count();
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $buyer->forceFill(['locale' => $locale])->save();
            $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $unconfigured->id,
                'tab' => 'requests',
            ]))->assertStatus(409);
            $this->actingAs($buyer->fresh())->post(route('admin.erp.procurement.requests.store'), [
                'store_id' => $unconfigured->id,
                'currency_code' => 'KWD',
                'reason' => 'P111 legitimate but unconfigured hierarchy',
                'item_code' => 'P111-SETUP-409',
                'description' => 'Reject before writes on missing hierarchy',
                'quantity' => '1.000',
                'unit' => 'case',
                'estimated_unit_cost' => '1.000',
            ])->assertStatus(409);
        }

        $this->assertSame($requestsBefore, DB::table('enterprise_purchase_requests')->count());
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_canonical_procurement_global_roles_require_live_account_pivot_and_actual_job_role_permissions(): void
    {
        $store = $this->wholesaleStore();
        $owner = $this->actor('SUPER_ADMIN');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $owner,
        );

        $matrix = [
            'PROCUREMENT_BUYER' => [true, false, false],
            'PROCUREMENT_APPROVER' => [false, true, true],
            'PROCUREMENT_MANAGER' => [true, true, true],
        ];
        $permissions = [
            'procurement.purchase_requests.manage',
            'procurement.purchase_requests.approve',
            'procurement.purchase_orders.issue',
        ];
        $this->assertContains('PROCUREMENT_BUYER', config('admin.channels.b2b.global_roles'));
        $this->assertNotContains('PROCUREMENT_BUYER', config('admin.channels.b2c.global_roles'));

        foreach ($matrix as $code => $expected) {
            $definition = config('job_roles.roles.'.$code);
            $this->assertIsArray($definition, $code);
            $this->assertSame('global', $definition['scope'], $code);
            $this->assertSame('procurement', $definition['domain'], $code);
            $this->assertContains('procurement', $definition['required_modules'], $code);
            $role = Role::query()->where('code', $code)->firstOrFail();
            $this->assertSame('global', $role->scope, $code);
            $this->assertTrue($role->is_active, $code);
            $this->assertNull($role->archived_at, $code);
            $this->assertEqualsCanonicalizing(
                $definition['permissions'],
                $role->permissions->pluck('code')->all(),
                $code.' must use the seeded canonical job_roles grants',
            );

            $actor = $this->actor($code);
            $this->assertDatabaseHas('role_user', [
                'user_id' => $actor->id,
                'role_id' => $role->id,
            ]);
            $this->assertTrue($actor->hasRole($code, $store->id), $code);
            foreach ($permissions as $index => $permission) {
                $this->assertSame($expected[$index], $actor->hasPermission($permission, $store->id), $code.':'.$permission);
            }
            foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
                $actor->forceFill(['locale' => $locale])->save();
                $this->actingAs($actor->fresh())->get(route('admin.erp.procurement.index', [
                    'store_id' => $store->id,
                    'tab' => 'requests',
                ]))->assertOk()->assertSee('dir="'.$direction.'"', false);
            }
        }

        // A seeded role/catalog entry with no actual role_user assignment does
        // not grant an otherwise active and authenticated account any access.
        $unassigned = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->assertFalse($unassigned->hasRole('PROCUREMENT_BUYER', $store->id));
        $this->assertFalse($unassigned->hasPermission('procurement.purchase_requests.view', $store->id));
        $before = DB::table('enterprise_purchase_requests')->count();
        $auditBefore = DB::table('audit_logs')->count();
        $this->actingAs($unassigned)->get(route('admin.erp.procurement.index', [
            'store_id' => $store->id,
            'tab' => 'requests',
        ]))->assertForbidden();
        $this->actingAs($unassigned)->post(route('admin.erp.procurement.requests.store'), [
            'store_id' => $store->id,
            'currency_code' => 'KWD',
            'reason' => 'P111 no linked global role',
            'description' => 'Forbidden without actual assignment',
            'quantity' => '1.000',
            'unit' => 'case',
        ])->assertForbidden();
        $this->assertSame($before, DB::table('enterprise_purchase_requests')->count());
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_wholesale_global_role_and_retail_store_role_cannot_cross_channel_or_forge_assignment(): void
    {
        $this->app['env'] = 'production';
        $csrfToken = 'p111-role-channel-boundary-csrf';
        $this->withSession(['_token' => $csrfToken])->withHeader('X-CSRF-TOKEN', $csrfToken);
        $wholesaleA = $this->wholesaleStore();
        $wholesaleB = Store::query()->create([
            'store_type_id' => $wholesaleA->store_type_id,
            'code' => 'P111-GLOBAL-B2B-B',
            'name' => 'P111 B2B other store',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($wholesaleB);
        $retailType = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $retailA = Store::query()->create([
            'store_type_id' => $retailType,
            'code' => 'P111-ROLE-B2C-A',
            'name' => 'P111 assigned Retail A',
            'is_active' => true,
        ]);
        $retailB = Store::query()->create([
            'store_type_id' => $retailType,
            'code' => 'P111-ROLE-B2C-B',
            'name' => 'P111 unassigned Retail B',
            'is_active' => true,
        ]);
        $owner = $this->actor('SUPER_ADMIN');
        $buyer = $this->actor('PROCUREMENT_BUYER');
        $retailOnly = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $wrongScope = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $buyer->storeRoleAssignments()->create([
            'store_id' => $retailA->id,
            'role_id' => Role::query()->where('code', 'RETAIL_FINANCE')->firstOrFail()->id,
        ]);
        $retailOnly->storeRoleAssignments()->create([
            'store_id' => $retailA->id,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
        ]);
        // Even a forged store-role pivot pointing to a GLOBAL Procurement
        // role must not be interpreted as a valid STORE-scoped assignment.
        $wrongScope->storeRoleAssignments()->create([
            'store_id' => $retailB->id,
            'role_id' => Role::query()->where('code', 'PROCUREMENT_BUYER')->firstOrFail()->id,
        ]);
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        $entitlements = app(ModuleEntitlementManager::class);
        $entitlements->set('procurement', EntitlementScope::platform(), true, null, $owner);
        $entitlements->set(
            'procurement', new EntitlementScope(EntitlementScope::STORE, $wholesaleB->id),
            false, null, $owner,
        );
        $this->assertTrue($buyer->hasRole('PROCUREMENT_BUYER', $wholesaleA->id));
        $this->assertFalse($buyer->hasRole('PROCUREMENT_BUYER', $retailA->id));
        $this->assertFalse($wrongScope->hasRole('PROCUREMENT_BUYER', $retailB->id));
        $this->assertFalse($wrongScope->hasPermission('procurement.purchase_requests.view', $retailB->id));
        $this->assertFalse($retailOnly->hasPermission('procurement.purchase_requests.view', $retailA->id));

        $requestsBefore = DB::table('enterprise_purchase_requests')->count();
        $auditBefore = DB::table('audit_logs')->count();
        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            foreach ([$buyer, $retailOnly, $wrongScope] as $actor) {
                $actor->forceFill(['locale' => $locale])->save();
            }
            $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $wholesaleA->id, 'tab' => 'requests',
            ]))->assertOk()->assertSee('dir="'.$direction.'"', false);

            foreach ([$wholesaleB->id, $retailA->id, $retailB->id] as $forbiddenStoreId) {
                $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                    'store_id' => $forbiddenStoreId, 'tab' => 'requests',
                ]))->assertForbidden();
                $this->actingAs($buyer->fresh())->post(route('admin.erp.procurement.requests.store'), [
                    'store_id' => $forbiddenStoreId,
                    'currency_code' => 'KWD',
                    'reason' => 'P111 denied wholesale/retail crossover',
                    'description' => 'Never create outside authorized store',
                    'quantity' => '1.000',
                    'unit' => 'case',
                ])->assertForbidden();
            }
            foreach ([
                [$retailOnly, $retailA->id],
                [$retailOnly, $retailB->id],
                [$retailOnly, $wholesaleA->id],
                [$wrongScope, $retailB->id],
            ] as [$actor, $forbiddenStoreId]) {
                $this->actingAs($actor->fresh())->get(route('admin.erp.procurement.index', [
                    'store_id' => $forbiddenStoreId, 'tab' => 'requests',
                ]))->assertForbidden();
            }
        }
        $this->assertSame($requestsBefore, DB::table('enterprise_purchase_requests')->count());
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_inactive_archived_procurement_identity_and_archived_global_role_are_denied_with_audit_unchanged(): void
    {
        $store = $this->wholesaleStore();
        $owner = $this->actor('SUPER_ADMIN');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $owner,
        );
        $archivedIdentity = $this->actor('PROCUREMENT_MANAGER');
        $archivedIdentity->forceFill([
            'archived_at' => now(),
            'is_active' => true,
        ])->save();
        $this->assertTrue($archivedIdentity->hasRole('PROCUREMENT_MANAGER', $store->id));
        // Historical role remains linked, but archive must deny actual HTTP.
        $role = Role::query()->where('code', 'PROCUREMENT_BUYER')->firstOrFail();
        $archivedRoleUser = $this->actor('PROCUREMENT_BUYER');
        // Archive alone must revoke the role, even when an interrupted
        // lifecycle leaves its old is_active flag true.
        $role->forceFill(['is_active' => true, 'archived_at' => now()])->save();
        $this->assertTrue((bool) $role->fresh()->is_active);
        $this->assertNotNull($role->fresh()->archived_at);
        $this->assertDatabaseHas('role_user', [
            'user_id' => $archivedRoleUser->id, 'role_id' => $role->id,
        ]);
        // Assert the exact B2B store-chooser authority, independently of the
        // downstream HTTP permission checks that also fail closed.
        $this->assertSame([], (new \ReflectionMethod(EnterpriseProcurementController::class, 'storeOptions'))
            ->invoke(app(EnterpriseProcurementController::class), $archivedRoleUser->fresh()));
        $this->assertFalse($archivedRoleUser->fresh()->hasRole('PROCUREMENT_BUYER', $store->id));
        $this->assertFalse($archivedRoleUser->fresh()->hasPermission('procurement.purchase_requests.view', $store->id));
        $inactive = $this->actor('PROCUREMENT_APPROVER');
        $inactive->forceFill(['is_active' => false, 'deactivated_at' => now()])->save();
        $this->assertFalse($inactive->fresh()->hasPermission('procurement.purchase_requests.approve', $store->id));
        $requestsBefore = DB::table('enterprise_purchase_requests')->count();
        $auditBefore = DB::table('audit_logs')->count();
        foreach (['ar', 'en'] as $locale) {
            foreach ([$archivedIdentity, $archivedRoleUser, $inactive] as $user) {
                $user->forceFill(['locale' => $locale])->save();
                $this->actingAs($user->fresh())->get(route('admin.erp.procurement.index', [
                    'store_id' => $store->id, 'tab' => 'requests',
                ]))->assertForbidden();
                $this->actingAs($user->fresh())->post(route('admin.erp.procurement.requests.store'), [
                    'store_id' => $store->id,
                    'currency_code' => 'KWD',
                    'reason' => 'P111 inactive or archived role denies writes',
                    'description' => 'Historical assignments cannot authorize writes',
                    'quantity' => '1.000',
                    'unit' => 'case',
                ])->assertForbidden();
            }
        }
        $this->assertSame($requestsBefore, DB::table('enterprise_purchase_requests')->count());
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_multi_role_maker_checker_cannot_self_approve_or_issue_po_with_explicit_global_deny(): void
    {
        $this->app['env'] = 'production';
        $csrfToken = 'p111-multi-role-maker-checker-csrf';
        $this->withSession(['_token' => $csrfToken])->withHeader('X-CSRF-TOKEN', $csrfToken);
        $store = $this->wholesaleStore();
        $owner = $this->actor('SUPER_ADMIN');
        $maker = $this->actor('PROCUREMENT_MANAGER');
        $checker = $this->actor('PROCUREMENT_APPROVER');
        $buyerOnly = $this->actor('PROCUREMENT_BUYER');
        foreach (['PROCUREMENT_BUYER', 'PROCUREMENT_APPROVER'] as $code) {
            $maker->roles()->syncWithoutDetaching([
                Role::query()->where('code', $code)->firstOrFail()->id,
            ]);
        }
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $owner,
        );
        $maker = $maker->fresh();
        $this->assertTrue($maker->hasPermission('procurement.purchase_requests.approve', $store->id));
        $this->assertTrue($maker->hasPermission('procurement.purchase_orders.issue', $store->id));
        $this->assertFalse($buyerOnly->hasPermission('procurement.purchase_orders.issue', $store->id));

        $context = app(TenantContextResolver::class)->wholesale($maker, $store->id);
        $supplier = app(SupplierDirectory::class)->create($maker, $context, [
            'scope_type' => 'store',
            'code' => 'P111-SOD-GLOBAL-MULTIROLE',
            'name_en' => 'P111 separated duties supplier',
        ]);
        $requests = app(PurchaseRequestManager::class);
        $purchaseRequest = $requests->create($maker, $context, [
            'supplier_id' => $supplier->id,
            'currency_code' => 'KWD',
            'reason' => 'P111 manager dual role independent maker checker',
            'lines' => [[
                'item_code' => 'P111-SOD-SKU',
                'description' => 'P111 maker checker witness',
                'quantity' => 1,
                'unit' => 'case',
                'estimated_unit_cost' => 1.250,
            ]],
        ]);
        $requests->submit($maker, $context, $purchaseRequest->id);
        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $purchaseRequest->id,
            'status' => 'submitted',
            'created_by' => $maker->id,
            'submitted_by' => $maker->id,
        ]);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $maker->forceFill(['locale' => $locale])->save();
            $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.requests.decision', $purchaseRequest->id), [
                'store_id' => $store->id,
                'decision' => 'approve',
            ])->assertSessionHasErrors('decision');
        }
        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $purchaseRequest->id, 'status' => 'submitted', 'decided_by' => null,
        ]);
        $this->assertSame(0, DB::table('audit_logs')
            ->where('event', 'enterprise.purchase_request.approved')
            ->where('auditable_id', $purchaseRequest->id)
            ->count());

        $this->actingAs($checker->fresh())->post(route('admin.erp.procurement.requests.decision', $purchaseRequest->id), [
            'store_id' => $store->id, 'decision' => 'approve',
        ])->assertRedirect();
        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $purchaseRequest->id, 'status' => 'approved', 'decided_by' => $checker->id,
        ]);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('event', 'enterprise.purchase_request.approved')
            ->where('auditable_id', $purchaseRequest->id)
            ->count());

        $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.orders.create', $purchaseRequest->id), [
            'store_id' => $store->id,
        ])->assertRedirect();
        $order = DB::table('enterprise_purchase_orders')->where('purchase_request_id', $purchaseRequest->id)->first();
        $this->assertNotNull($order);
        $this->assertSame('draft', $order->status);
        $permission = Permission::query()->where('code', 'procurement.purchase_orders.issue')->firstOrFail();

        // A user with Manager + Buyer + Approver GLOBAL assignments must not
        // use those roles to overrule a narrower STORE-specific issue DENY.
        // A sibling B2B store is genuinely reachable for read operations,
        // so its issue refusal proves permission precedence, not a bad tenant.
        $sibling = Store::query()->create([
            'store_type_id' => $store->store_type_id,
            'code' => 'P111-MULTIROLE-SIBLING-B2B',
            'name' => 'P111 multirole sibling store',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($sibling);
        foreach (['PROCUREMENT_MANAGER', 'PROCUREMENT_BUYER', 'PROCUREMENT_APPROVER'] as $code) {
            $this->assertDatabaseHas('role_user', [
                'user_id' => $maker->id,
                'role_id' => Role::query()->where('code', $code)->firstOrFail()->id,
            ]);
        }
        DB::table('user_permission_overrides')->insert([
            'user_id' => $maker->id,
            'permission_id' => $permission->id,
            'scope_key' => 'store:'.$sibling->id,
            'store_id' => $sibling->id,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $maker->forceFill(['has_permission_overrides' => true])->save();
        $this->assertTrue($maker->fresh()->hasPermission('procurement.purchase_orders.issue', $store->id));
        $this->assertFalse($maker->fresh()->hasPermission('procurement.purchase_orders.issue', $sibling->id));
        $auditBeforeScopedDenial = DB::table('audit_logs')->count();
        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $maker->forceFill(['locale' => $locale])->save();
            $this->actingAs($maker->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $sibling->id,
                'tab' => 'orders',
            ]))->assertOk()->assertSee('dir="'.$direction.'"', false);
            $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.orders.issue', $order->id), [
                'store_id' => $sibling->id,
            ])->assertForbidden();
        }
        $this->assertDatabaseHas('enterprise_purchase_orders', [
            'id' => $order->id,
            'status' => 'draft',
            'issued_by' => null,
        ]);
        $this->assertSame($auditBeforeScopedDenial, DB::table('audit_logs')->count());

        // A later GLOBAL DENY must also defeat all three role grants in the
        // original Store A, without affecting the independent checker's path.
        DB::table('user_permission_overrides')->insert([
            'user_id' => $maker->id,
            'permission_id' => $permission->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $maker->forceFill(['has_permission_overrides' => true])->save();
        $this->assertFalse($maker->fresh()->hasPermission('procurement.purchase_orders.issue', $store->id));

        $auditBeforeForbiddenIssue = DB::table('audit_logs')->count();
        foreach ([$buyerOnly, $maker] as $actor) {
            $this->actingAs($actor->fresh())->post(route('admin.erp.procurement.orders.issue', $order->id), [
                'store_id' => $store->id,
            ])->assertForbidden();
        }
        $this->assertDatabaseHas('enterprise_purchase_orders', [
            'id' => $order->id, 'status' => 'draft', 'issued_by' => null,
        ]);
        $this->assertSame($auditBeforeForbiddenIssue, DB::table('audit_logs')->count());
        $this->assertSame(0, DB::table('audit_logs')
            ->where('event', 'enterprise.purchase_order.issued')
            ->where('auditable_id', $order->id)
            ->count());

        $this->actingAs($checker->fresh())->post(route('admin.erp.procurement.orders.issue', $order->id), [
            'store_id' => $store->id,
        ])->assertRedirect();
        $this->assertDatabaseHas('enterprise_purchase_orders', [
            'id' => $order->id, 'status' => 'issued', 'issued_by' => $checker->id,
        ]);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('event', 'enterprise.purchase_order.issued')
            ->where('auditable_id', $order->id)
            ->count());
    }

    public function test_all_twelve_canonical_procurement_tabs_have_one_localized_horizontal_http_rail(): void
    {
        $store = $this->wholesaleStore();
        $owner = $this->actor('SUPER_ADMIN');
        $manager = $this->actor('PROCUREMENT_MANAGER');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $owner,
        );

        $canonical = [
            'overview', 'suppliers', 'requests', 'rfqs', 'orders', 'contracts',
            'receipts', 'invoices', 'matches', 'returns', 'statements', 'reports',
        ];
        // The controller's actual private tab map, not a fixture-only
        // duplicate, is the authority for order and supported tabs.
        $tabsConstant = (new \ReflectionClass(EnterpriseProcurementController::class))
            ->getReflectionConstant('TABS');
        $this->assertInstanceOf(\ReflectionClassConstant::class, $tabsConstant);
        $this->assertSame($canonical, array_keys($tabsConstant->getValue()));

        // Catch silent Laravel translation-key fallbacks: comparing a rendered
        // fallback against the same missing key would otherwise pass.
        foreach (['ar', 'en'] as $locale) {
            $labels = trans('enterprise_procurement.tabs', [], $locale);
            $this->assertIsArray($labels);
            $this->assertSame($canonical, array_keys($labels), $locale.' must localize the actual canonical tabs in order');
            foreach ($labels as $tab => $label) {
                $this->assertIsString($label);
                $this->assertNotSame('', trim($label), $locale.':'.$tab);
                $this->assertNotSame('enterprise_procurement.tabs.'.$tab, $label);
                $this->assertSame(
                    1,
                    preg_match($locale === 'ar' ? '/[\x{0600}-\x{06FF}]/u' : '/[A-Za-z]/', $label),
                    $locale.':'.$tab.' needs a genuine localized caption',
                );
            }
        }

        $namedRoutes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(static fn ($route): bool => $route->getName() === 'admin.erp.procurement.index');
        $this->assertCount(1, $namedRoutes, 'Each tab must use the one canonical GET route');
        $auditBefore = DB::table('audit_logs')->count();
        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $manager->forceFill(['locale' => $locale])->save();
            foreach ($canonical as $tab) {
                $response = $this->actingAs($manager->fresh())->get(route('admin.erp.procurement.index', [
                    'store_id' => $store->id,
                    'tab' => $tab,
                ]));
                $response->assertOk()
                    ->assertViewHas('activeTab', $tab)
                    ->assertSee('dir="'.$direction.'"', false);
                $this->assertSame($store->id, $response->viewData('selectedStoreId'));
                $this->assertSame($canonical, collect($response->viewData('tabs'))->pluck('key')->all());

                $html = html_entity_decode($response->getContent(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $this->assertSame(1, substr_count($html, '<aside class="sidebar">'));
                $this->assertSame(1, substr_count($html, 'data-erp-procurement-workbench="true"'));
                $this->assertSame(1, preg_match(
                    '/<nav class="foodex-tabs erp-procurement-tabs" data-procurement-tabs[^>]*>(.*?)<\\/nav>/s',
                    $html,
                    $nav,
                ), $locale.'/'.$tab.' must reuse one horizontal nav');
                $this->assertSame(12, substr_count($nav[1], 'data-procurement-tab="'));
                $this->assertSame(1, substr_count($nav[1], 'aria-current="page"'));
                $this->assertStringContainsString(
                    'data-procurement-tab="'.$tab.'"',
                    $nav[1],
                );
                $this->assertStringContainsString(
                    __('enterprise_procurement.tabs.'.$tab, [], $locale),
                    $nav[1],
                );
                $this->assertSame(1, preg_match(
                    '/<a data-procurement-tab="'.preg_quote($tab, '/').'" href="([^"]+)"/',
                    $nav[1],
                    $link,
                ));
                parse_str((string) parse_url($link[1], PHP_URL_QUERY), $query);
                $this->assertSame($tab, $query['tab'] ?? null);
                $this->assertSame((string) $store->id, (string) ($query['store_id'] ?? ''));
            }
        }
        // GET for a list, dashboard or report may never issue a business
        // transition or append a successful write audit.
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_procurement_search_filters_and_single_pagination_preserve_tab_store_and_deep_link_query(): void
    {
        $store = $this->wholesaleStore();
        $owner = $this->actor('SUPER_ADMIN');
        $manager = $this->actor('PROCUREMENT_MANAGER');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $owner,
        );

        $statusByTab = [
            'suppliers' => 'active',
            'requests' => 'draft',
            'rfqs' => 'draft',
            'orders' => 'draft',
            'contracts' => 'draft',
            'receipts' => 'draft',
            'invoices' => 'draft',
            'matches' => 'pending',
            'returns' => 'draft',
        ];
        $embedded = ['rfqs', 'contracts', 'matches'];
        $auditBefore = DB::table('audit_logs')->count();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $manager->forceFill(['locale' => $locale])->save();
            foreach ($statusByTab as $tab => $status) {
                $response = $this->actingAs($manager->fresh())->get(route('admin.erp.procurement.index', [
                    'store_id' => $store->id,
                    'tab' => $tab,
                    'q' => 'SAFE-PREFIX',
                    'status' => $status,
                    'page' => 1,
                ]));
                $response->assertOk()->assertSee('dir="'.$direction.'"', false);
                $response->assertSee('name="q" value="SAFE-PREFIX"', false);
                $response->assertSee('name="tab" value="'.$tab.'"', false);
                $response->assertSee('name="store_id" value="'.$store->id.'"', false);
                $rows = $response->viewData('rows');
                $this->assertInstanceOf(LengthAwarePaginator::class, $rows);
                parse_str((string) parse_url($rows->url(2), PHP_URL_QUERY), $query);
                $this->assertSame($tab, $query['tab'] ?? null);
                $this->assertSame((string) $store->id, (string) ($query['store_id'] ?? ''));
                $this->assertSame('SAFE-PREFIX', $query['q'] ?? null);
                $this->assertSame($status, $query['status'] ?? null);
                $this->assertSame('2', (string) ($query['page'] ?? ''));

                $html = $response->getContent();
                if (in_array($tab, $embedded, true)) {
                    $this->assertSame(1, substr_count($html, 'class="erp-pagination"'));
                    $this->assertStringNotContainsString('data-procurement-footer-pagination', $html);
                } else {
                    $this->assertSame(1, substr_count($html, 'data-procurement-footer-pagination'));
                    $this->assertStringNotContainsString('class="erp-pagination"', $html);
                }
            }
        }
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_statements_filter_and_paginator_keep_supplier_dates_currency_and_scope_in_both_locales(): void
    {
        $store = $this->wholesaleStore();
        $owner = $this->actor('SUPER_ADMIN');
        $manager = $this->actor('PROCUREMENT_MANAGER');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $owner,
        );
        $supplier = app(SupplierDirectory::class)->create(
            $manager,
            app(TenantContextResolver::class)->wholesale($manager, $store->id),
            [
                'scope_type' => 'store',
                'code' => 'P111-STATEMENT-FILTER-SCOPE',
                'name_en' => 'P111 Statement Filter Supplier',
            ],
        );

        $query = [
            'tab' => 'statements',
            'store_id' => $store->id,
            'supplier_id' => $supplier->id,
            'from' => '2026-01-01',
            'to' => '2026-11-18',
            'as_of' => '2026-11-18',
            'currency_code' => 'KWD',
        ];
        $auditBefore = DB::table('audit_logs')->count();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $manager->forceFill(['locale' => $locale])->save();
            $response = $this->actingAs($manager->fresh())->get(
                route('admin.erp.procurement.index', $query),
            );
            $response->assertOk()->assertViewHas('activeTab', 'statements')
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('name="from" value="2026-01-01"', false)
                ->assertSee('name="to" value="2026-11-18"', false)
                ->assertSee('name="as_of" value="2026-11-18"', false)
                ->assertSee('name="currency_code" value="KWD"', false);

            $statement = $response->viewData('statement');
            $this->assertInstanceOf(LengthAwarePaginator::class, $statement);
            parse_str((string) parse_url($statement->url(2), PHP_URL_QUERY), $pageTwo);
            foreach ($query as $key => $value) {
                $this->assertSame((string) $value, (string) ($pageTwo[$key] ?? ''), $locale.':'.$key);
            }
            $this->assertSame('2', (string) ($pageTwo['page'] ?? ''));
            $this->assertSame(1, substr_count($response->getContent(), 'data-procurement-tabs'));
        }

        // Filtered financial GETs cannot post invoices or create audit events.
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_every_canonical_tab_hides_and_forbids_explicit_role_permission_deny_without_side_effects(): void
    {
        $store = $this->wholesaleStore();
        $owner = $this->actor('SUPER_ADMIN');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $owner,
        );

        $tabsConstant = (new \ReflectionClass(EnterpriseProcurementController::class))
            ->getReflectionConstant('TABS');
        $this->assertInstanceOf(\ReflectionClassConstant::class, $tabsConstant);
        $tabPermissions = $tabsConstant->getValue();
        $covered = [];
        $auditBefore = DB::table('audit_logs')->count();
        $requestsBefore = DB::table('enterprise_purchase_requests')->count();

        foreach (array_unique(array_values($tabPermissions)) as $deniedPermission) {
            $actor = $this->actor('PROCUREMENT_MANAGER');
            $this->assertTrue($actor->hasPermission($deniedPermission, $store->id));
            DB::table('user_permission_overrides')->insert([
                'user_id' => $actor->id,
                'permission_id' => Permission::query()->where('code', $deniedPermission)->firstOrFail()->id,
                'scope_key' => 'global',
                'store_id' => null,
                'effect' => 'deny',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $actor->forceFill(['has_permission_overrides' => true])->save();
            $this->assertFalse($actor->fresh()->hasPermission($deniedPermission, $store->id));

            // Keep another genuinely authorized tab visible; a user with one
            // denied permission must not be treated as entirely unauthenticated.
            $allowedTab = $deniedPermission === 'procurement.purchase_requests.view'
                ? 'suppliers' : 'requests';
            $this->assertTrue($actor->fresh()->hasPermission($tabPermissions[$allowedTab], $store->id));

            foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
                app()->setLocale($locale);
                $actor->forceFill(['locale' => $locale])->save();
                $allowed = $this->actingAs($actor->fresh())->get(route('admin.erp.procurement.index', [
                    'store_id' => $store->id,
                    'tab' => $allowedTab,
                ]))->assertOk()->assertSee('dir="'.$direction.'"', false);
                $visible = collect($allowed->viewData('tabs'))->pluck('key')->all();
                $this->assertContains($allowedTab, $visible);

                foreach ($tabPermissions as $tab => $permission) {
                    if ($permission !== $deniedPermission) {
                        continue;
                    }
                    $covered[] = $tab;
                    $this->assertNotContains($tab, $visible, $locale.':'.$tab);
                    $allowed->assertDontSee('data-procurement-tab="'.$tab.'"', false);
                    $this->actingAs($actor->fresh())->get(route('admin.erp.procurement.index', [
                        'store_id' => $store->id,
                        'tab' => $tab,
                    ]))->assertForbidden();
                }
            }
        }

        // Every real Controller::TABS entry was covered in both locales.
        $this->assertEqualsCanonicalizing(array_keys($tabPermissions), array_unique($covered));
        $this->assertSame($requestsBefore, DB::table('enterprise_purchase_requests')->count());
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_procurement_denied_tabs_are_hidden_and_direct_get_denied_while_unknown_tab_is_404(): void
    {
        $store = $this->wholesaleStore();
        $owner = $this->actor('SUPER_ADMIN');
        $buyer = $this->actor('PROCUREMENT_BUYER');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $owner,
        );

        $deniedPermissions = [
            'procurement.suppliers.view' => ['overview', 'suppliers'],
            'procurement.purchase_orders.view' => ['orders', 'contracts'],
            'procurement.purchase_invoices.view' => ['invoices', 'matches'],
        ];
        foreach (array_keys($deniedPermissions) as $code) {
            DB::table('user_permission_overrides')->insert([
                'user_id' => $buyer->id,
                'permission_id' => Permission::query()->where('code', $code)->firstOrFail()->id,
                'scope_key' => 'global',
                'store_id' => null,
                'effect' => 'deny',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $buyer->forceFill(['has_permission_overrides' => true])->save();
        $auditBefore = DB::table('audit_logs')->count();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $buyer->forceFill(['locale' => $locale])->save();
            $response = $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'tab' => 'requests',
                'store_id' => $store->id,
            ]))->assertOk()->assertSee('dir="'.$direction.'"', false);
            $visible = collect($response->viewData('tabs'))->pluck('key')->all();
            foreach ($deniedPermissions as $permission => $tabs) {
                $this->assertFalse($buyer->fresh()->hasPermission($permission, $store->id));
                foreach ($tabs as $tab) {
                    $this->assertNotContains($tab, $visible);
                    $response->assertDontSee('data-procurement-tab="'.$tab.'"', false);
                    $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                        'tab' => $tab,
                        'store_id' => $store->id,
                    ]))->assertForbidden();
                }
            }
            // BUYER's canonical job_roles bundle has no returns.view. Do not
            // invent a role grant merely to make a menu item visible.
            $this->assertNotContains('returns', $visible);
            $response->assertDontSee('data-procurement-tab="returns"', false);
            $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'tab' => 'returns',
                'store_id' => $store->id,
            ]))->assertForbidden();

            foreach (['requests', 'rfqs', 'receipts', 'statements', 'reports'] as $tab) {
                $this->assertContains($tab, $visible);
                $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                    'tab' => $tab,
                    'store_id' => $store->id,
                ]))->assertOk();
            }
            $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'tab' => 'forged_unregistered_tab',
                'store_id' => $store->id,
            ]))->assertNotFound();
            // Invalid scalar/array selectors are not permission scopes and
            // cannot be coerced to a fallback authorized tab.
            // Include the empty key explicitly: missing ?tab is an allowed
            // default selector, but an expressly blank ?tab= must be 404.
            $storeUrl = route('admin.erp.procurement.index', ['store_id' => $store->id]);
            $this->actingAs($buyer->fresh())->get($storeUrl)->assertOk();
            $this->actingAs($buyer->fresh())->get($storeUrl.'&tab=')->assertNotFound();
            foreach ([['requests'], ['requests', 'suppliers']] as $invalidTab) {
                $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                    'tab' => $invalidTab,
                    'store_id' => $store->id,
                ]))->assertNotFound();
            }
        }
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_authorized_purchase_request_deep_link_cannot_open_a_foreign_store_record(): void
    {
        $primary = $this->wholesaleStore();
        $sibling = Store::query()->create([
            'store_type_id' => $primary->store_type_id,
            'code' => 'P111-TAB-FOREIGN-DEEP-LINK',
            'name' => 'P111 separate B2B deep-link store',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($sibling);
        $owner = $this->actor('SUPER_ADMIN');
        $maker = $this->actor('PROCUREMENT_BUYER');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $owner,
        );
        $context = app(TenantContextResolver::class)->wholesale($maker, $primary->id);
        $record = app(PurchaseRequestManager::class)->create($maker, $context, [
            'currency_code' => 'KWD',
            'reason' => 'P111 authorized deep link never crosses Store A to B',
            'lines' => [[
                'description' => 'P111 scoped deep link',
                'quantity' => '1.000',
                'unit' => 'case',
                'estimated_unit_cost' => '1.000',
            ]],
        ]);
        $auditBefore = DB::table('audit_logs')->count();
        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $maker->forceFill(['locale' => $locale])->save();
            // List-to-record navigation retains last applied search/status/
            // pagination, but never changes the authorized store or tab.
            $prefix = substr($record->request_number, 0, 7);
            $filtered = $this->actingAs($maker->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $primary->id,
                'tab' => 'requests',
                'q' => $prefix,
                'status' => 'draft',
                'page' => 1,
            ]))->assertOk();
            $html = html_entity_decode($filtered->getContent(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $this->assertSame(1, preg_match(
                '/href="([^"]*\\brequest='.preg_quote((string) $record->id, '/').'[^"]*)"/',
                $html,
                $exactRecordLink,
            ));
            parse_str((string) parse_url($exactRecordLink[1], PHP_URL_QUERY), $preserved);
            $this->assertSame('requests', $preserved['tab'] ?? null);
            $this->assertSame((string) $primary->id, (string) ($preserved['store_id'] ?? ''));
            $this->assertSame($prefix, $preserved['q'] ?? null);
            $this->assertSame('draft', $preserved['status'] ?? null);
            $this->assertSame('1', (string) ($preserved['page'] ?? ''));
            $this->assertSame((string) $record->id, (string) ($preserved['request'] ?? ''));

            $this->actingAs($maker->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $primary->id,
                'tab' => 'requests',
                'request' => $record->id,
            ]))->assertOk()->assertSee('dir="'.$direction.'"', false)
                ->assertSee($record->request_number);

            $this->actingAs($maker->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $sibling->id,
                'tab' => 'requests',
                'request' => $record->id,
            ]))->assertNotFound();
        }
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_foreign_company_supplier_legal_identity_never_crosses_procurement_http_store_scope(): void
    {
        $storeA = $this->wholesaleStore();
        $storeB = Store::query()->create([
            'store_type_id' => $storeA->store_type_id,
            'code' => 'P111-SUPPLIER-FOREIGN-COMPANY-B',
            'name' => 'P111 Foreign Company B2B Store',
            'is_active' => true,
        ]);
        $hierarchy = app(OrganizationHierarchyService::class);
        $scopeA = $hierarchy->scopeForStore($storeA->id);
        $this->assertIsArray($scopeA);
        $foreignCompany = EnterpriseCompany::query()->create([
            'organization_id' => $scopeA['organization_id'],
            'code' => 'P111_SUPPLIER_FOREIGN_COMPANY',
            'name' => 'P111 Supplier Foreign Legal Company',
            'is_active' => true,
        ]);
        $foreignEntity = EnterpriseLegalEntity::query()->create([
            'company_id' => $foreignCompany->id,
            'code' => 'P111_SUPPLIER_FOREIGN_ENTITY',
            'name' => 'P111 Supplier Foreign Legal Entity',
            'is_active' => true,
        ]);
        $foreignUnit = EnterpriseBusinessUnit::query()->create([
            'legal_entity_id' => $foreignEntity->id,
            'code' => 'P111_SUPPLIER_FOREIGN_UNIT',
            'name' => 'P111 Supplier Foreign Business Unit',
            'is_active' => true,
        ]);
        $hierarchy->assignStore($storeB, $foreignUnit);
        $scopeB = $hierarchy->scopeForStore($storeB->id);
        $this->assertIsArray($scopeB);
        $this->assertNotSame($scopeA['company_id'], $scopeB['company_id']);

        $owner = $this->actor('SUPER_ADMIN');
        $buyer = $this->actor('PROCUREMENT_BUYER');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $owner,
        );

        $foreign = app(SupplierDirectory::class)->create(
            $buyer,
            app(TenantContextResolver::class)->wholesale($buyer, $storeB->id),
            [
                'scope_type' => 'company',
                'code' => 'P111-FOREIGN-COMPANY-LEGAL-SUP',
                'name_en' => 'P111 Foreign Company Legal Supplier',
                'name_ar' => 'مورد تابع لشركة أخرى',
                'tax_registration_number' => 'TAX-P111-FOREIGN-COMPANY',
                'commercial_registration_number' => 'CR-P111-FOREIGN-COMPANY',
            ],
        );
        $this->assertSame($scopeB['company_id'], (int) $foreign->company_id);
        $this->assertNull($foreign->store_id);
        $this->assertSame('TAX-P111-FOREIGN-COMPANY', $foreign->tax_registration_number);
        $this->assertSame('CR-P111-FOREIGN-COMPANY', $foreign->commercial_registration_number);
        $countBefore = DB::table('enterprise_purchase_requests')->count();
        $supplierCountBefore = DB::table('enterprise_suppliers')->count();
        $auditBefore = DB::table('audit_logs')->count();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $buyer->forceFill(['locale' => $locale])->save();

            // The same genuine global Buyer can view the supplier within
            // Company B, but cannot reuse that supplier ID in Company A.
            $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $storeB->id, 'tab' => 'suppliers', 'supplier' => $foreign->id,
            ]))->assertOk()->assertSee('dir="'.$direction.'"', false)
                ->assertSee('TAX-P111-FOREIGN-COMPANY');

            $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $storeA->id, 'tab' => 'suppliers', 'supplier' => $foreign->id,
            ]))->assertNotFound();

            $this->actingAs($buyer->fresh())->post(route('admin.erp.procurement.suppliers.store'), [
                'store_id' => $storeA->id,
                'supplier_id' => $foreign->id,
                'scope_type' => 'company',
                'name_en' => 'Foreign company edit must never apply',
                'tax_registration_number' => 'TAX-FORGED-FOREIGN-EDIT',
                'company_id' => $foreignCompany->id,
                'updated_by' => $owner->id,
            ])->assertNotFound();

            $this->actingAs($buyer->fresh())->post(
                route('admin.erp.procurement.suppliers.archive', $foreign->id),
                ['store_id' => $storeA->id, 'company_id' => $foreignCompany->id],
            )->assertNotFound();

            $this->actingAs($buyer->fresh())->post(route('admin.erp.procurement.requests.store'), [
                'store_id' => $storeA->id,
                'supplier_id' => $foreign->id,
                'currency_code' => 'KWD',
                'reason' => 'P111 foreign company supplier must not create request',
                'description' => 'No cross-company procurement request',
                'quantity' => '1.125',
                'unit' => 'case',
                'estimated_unit_cost' => '1.999',
                'company_id' => $foreignCompany->id,
                'created_by' => $owner->id,
            ])->assertNotFound();
        }

        $this->assertDatabaseHas('enterprise_suppliers', [
            'id' => $foreign->id,
            'company_id' => $foreignCompany->id,
            'store_id' => null,
            'status' => 'active',
            'tax_registration_number' => 'TAX-P111-FOREIGN-COMPANY',
        ]);
        $this->assertSame($supplierCountBefore, DB::table('enterprise_suppliers')->count());
        $this->assertSame($countBefore, DB::table('enterprise_purchase_requests')->count());
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_supplier_legal_identity_create_and_edit_http_and_archive_respect_company_store_scope_and_audit(): void
    {
        $storeA = $this->wholesaleStore();
        $storeB = Store::query()->create([
            'store_type_id' => $storeA->store_type_id,
            'code' => 'P111-SUPPLIER-SCOPE-B',
            'name' => 'P111 Supplier scope Store B',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($storeB);
        $owner = $this->actor('SUPER_ADMIN');
        $buyer = $this->actor('PROCUREMENT_BUYER');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $owner,
        );

        $contextA = app(TenantContextResolver::class)->wholesale($buyer, $storeA->id);
        $legal = [
            'store_id' => $storeA->id,
            'scope_type' => 'store',
            'code' => 'P111-LEGAL-SUP',
            'name_en' => 'P111 Legitimate Supplier',
            'name_ar' => 'مورد قانوني للاختبار',
            'tax_registration_number' => 'TAX-P111-001',
            'commercial_registration_number' => 'CR-P111-001',
            'payment_terms_days' => 30,
        ];
        $auditBefore = DB::table('audit_logs')->count();
        $this->actingAs($buyer)->post(route('admin.erp.procurement.suppliers.store'), $legal)
            ->assertRedirect()
            ->assertSessionHas('status');

        $supplier = DB::table('enterprise_suppliers')->where('code', $legal['code'])->first();
        $this->assertNotNull($supplier);
        $this->assertSame('active', $supplier->status);
        $this->assertSame((int) $storeA->id, (int) $supplier->store_id);
        $this->assertSame($contextA->enterpriseScope->companyId, (int) $supplier->company_id);
        $this->assertSame($contextA->enterpriseScope->organizationId, (int) $supplier->organization_id);
        $this->assertSame($buyer->id, (int) $supplier->created_by);
        $this->assertSame('TAX-P111-001', $supplier->tax_registration_number);
        $this->assertSame('CR-P111-001', $supplier->commercial_registration_number);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('event', 'enterprise.supplier.created')
            ->where('auditable_id', $supplier->id)
            ->count());

        // Supplier edits reuse the existing authorized collection POST; the
        // explicit ID selects a scoped edit and never falls back to creation.
        $editPayload = [
            'store_id' => $storeA->id,
            'supplier_id' => $supplier->id,
            'scope_type' => 'store',
            'code' => $legal['code'],
            'name_en' => 'P111 Updated Legal Supplier',
            'name_ar' => $legal['name_ar'],
            'tax_registration_number' => 'TAX-P111-002',
            'commercial_registration_number' => $legal['commercial_registration_number'],
            'payment_terms_days' => 45,
        ];
        $this->actingAs($buyer)->post(route('admin.erp.procurement.suppliers.store'), [
            ...$editPayload,
            'company_id' => 999999,
            'organization_id' => 999999,
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
        ])->assertRedirect()->assertSessionHas('status', __('p111_procurement.supplier.updated'));

        $updated = DB::table('enterprise_suppliers')->where('id', $supplier->id)->first();
        $this->assertSame('P111 Updated Legal Supplier', $updated->name_en);
        $this->assertSame('TAX-P111-002', $updated->tax_registration_number);
        $this->assertSame('CR-P111-001', $updated->commercial_registration_number);
        $this->assertSame((int) $storeA->id, (int) $updated->store_id);
        $this->assertSame($contextA->enterpriseScope->companyId, (int) $updated->company_id);
        $this->assertSame($contextA->enterpriseScope->organizationId, (int) $updated->organization_id);
        $this->assertSame($buyer->id, (int) $updated->updated_by);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('event', 'enterprise.supplier.updated')
            ->where('auditable_id', $supplier->id)
            ->count());

        $auditAfterLegalUpdate = DB::table('audit_logs')->count();
        $this->actingAs($buyer->fresh())->post(route('admin.erp.procurement.suppliers.store'), $editPayload)
            ->assertRedirect();
        $this->actingAs($buyer->fresh())->post(route('admin.erp.procurement.suppliers.store'), [
            ...$editPayload,
            'store_id' => $storeB->id,
            'name_en' => 'Foreign edit must not apply',
        ])->assertNotFound();
        $this->actingAs($buyer->fresh())->post(route('admin.erp.procurement.suppliers.store'), [
            ...$editPayload,
            'supplier_id' => 999999,
            'code' => 'P111-MISSING-SUPPLIER-EDIT',
        ])->assertNotFound();
        $this->assertSame($auditAfterLegalUpdate, DB::table('audit_logs')->count());
        $this->assertDatabaseMissing('enterprise_suppliers', [
            'code' => 'P111-MISSING-SUPPLIER-EDIT',
        ]);
        $this->assertDatabaseHas('enterprise_suppliers', [
            'id' => $supplier->id,
            'name_en' => 'P111 Updated Legal Supplier',
            'updated_by' => $buyer->id,
            'store_id' => $storeA->id,
        ]);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $buyer->forceFill(['locale' => $locale])->save();
            $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $storeA->id,
                'tab' => 'suppliers',
                'supplier' => $supplier->id,
            ]))->assertOk()->assertSee('dir="'.$direction.'"', false)
                ->assertSee('TAX-P111-002')
                ->assertSee(__('p111_procurement.supplier.edit_title'))
                ->assertSee('name="supplier_id"', false);

            // Global Buyer role can navigate B2B B, but the record is
            // explicitly store-owned and must never become visible there.
            $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $storeB->id,
                'tab' => 'suppliers',
                'supplier' => $supplier->id,
            ]))->assertNotFound();
            $this->actingAs($buyer->fresh())->post(route('admin.erp.procurement.suppliers.archive', $supplier->id), [
                'store_id' => $storeB->id,
                'user_id' => $owner->id,
                'company_id' => $contextA->enterpriseScope->companyId,
            ])->assertNotFound();
        }
        $this->assertDatabaseHas('enterprise_suppliers', [
            'id' => $supplier->id,
            'status' => 'active',
            'store_id' => $storeA->id,
        ]);
        $this->assertSame($auditAfterLegalUpdate, DB::table('audit_logs')->count());

        $this->actingAs($buyer->fresh())->post(route('admin.erp.procurement.suppliers.archive', $supplier->id), [
            'store_id' => $storeA->id,
        ])->assertRedirect();
        $this->assertDatabaseHas('enterprise_suppliers', [
            'id' => $supplier->id,
            'status' => 'inactive',
            'store_id' => $storeA->id,
            'commercial_registration_number' => 'CR-P111-001',
        ]);
        $this->assertSame(2, DB::table('audit_logs')
            ->where('event', 'enterprise.supplier.updated')
            ->where('auditable_id', $supplier->id)
            ->count());
        $this->assertSame($auditBefore + 3, DB::table('audit_logs')->count());
        $supplierAuditAfterArchive = DB::table('audit_logs')->count();

        // Retries (including by another authorized actor) must not replace
        // the historical updater or produce another successful audit.
        foreach ([$buyer, $owner] as $replayingActor) {
            $this->actingAs($replayingActor->fresh())->post(route('admin.erp.procurement.suppliers.archive', $supplier->id), [
                'store_id' => $storeA->id,
            ])->assertRedirect();
        }
        $this->assertSame($supplierAuditAfterArchive, DB::table('audit_logs')->count());
        $this->assertDatabaseHas('enterprise_suppliers', [
            'id' => $supplier->id,
            'status' => 'inactive',
            'updated_by' => $buyer->id,
        ]);

        // The company-wide natural key remains reserved even if the
        // inactive supplier was originally scoped to another B2B store.
        $this->actingAs($buyer->fresh())->post(route('admin.erp.procurement.suppliers.store'), [
            ...$legal,
            'store_id' => $storeB->id,
            'scope_type' => 'store',
        ])->assertSessionHasErrors(['code']);
        $this->assertSame($supplierAuditAfterArchive, DB::table('audit_logs')->count());
        $this->assertSame(1, DB::table('enterprise_suppliers')->where('code', $legal['code'])->count());

        // An archived supplier remains in audit/history but is not an active
        // source for newly created Purchase Requests, even to the old owner.
        $requestCountBeforeArchived = DB::table('enterprise_purchase_requests')->count();
        $this->actingAs($buyer->fresh())->post(route('admin.erp.procurement.requests.store'), [
            'store_id' => $storeA->id,
            'supplier_id' => $supplier->id,
            'currency_code' => 'KWD',
            'reason' => 'P111 archived supplier cannot source a new request',
            'item_code' => 'P111-ARCHIVED-SUPPLIER',
            'description' => 'Inactive legal supplier no new orders',
            'quantity' => '1.000',
            'unit' => 'case',
            'estimated_unit_cost' => '2.500',
        ])->assertNotFound();
        $this->assertSame($requestCountBeforeArchived, DB::table('enterprise_purchase_requests')->count());
        $this->assertSame($supplierAuditAfterArchive, DB::table('audit_logs')->count());
    }

    public function test_purchase_request_three_decimal_precision_create_edit_submit_and_replay_are_atomic(): void
    {
        $this->app['env'] = 'production';
        $csrfToken = 'p111-purchase-request-precision-csrf';
        $this->withSession(['_token' => $csrfToken])->withHeader('X-CSRF-TOKEN', $csrfToken);

        $store = $this->wholesaleStore();
        $owner = $this->actor('SUPER_ADMIN');
        $maker = $this->actor('PROCUREMENT_BUYER');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $owner,
        );
        $context = app(TenantContextResolver::class)->wholesale($maker, $store->id);
        $supplier = app(SupplierDirectory::class)->create($maker, $context, [
            'scope_type' => 'store',
            'code' => 'P111-PRECISION-LEGAL-SUPPLIER',
            'name_en' => 'P111 Precision Legal Supplier',
            'tax_registration_number' => 'TAX-P111-PRECISION',
            'commercial_registration_number' => 'CR-P111-PRECISION',
        ]);

        $lines = [[
            'item_code' => 'P111-PRECISION-SKU',
            'description' => 'Three decimal procurement unit',
            'quantity' => '2.125',
            'unit' => 'case',
            'estimated_unit_cost' => '123.456',
        ], [
            'item_code' => 'P111-PRECISION-SKU-2',
            'description' => 'Smallest three decimal procurement unit',
            'quantity' => '0.001',
            'unit' => 'box',
            'estimated_unit_cost' => '0.001',
        ]];
        $payload = [
            'store_id' => $store->id,
            'supplier_id' => $supplier->id,
            'idempotency_key' => '5e19dfbb-34a4-4c83-8c32-150e6a222118',
            'currency_code' => 'KWD',
            'reason' => 'P111 three decimal precision source fixture',
            'lines' => $lines,
            // Caller-provided financial/identity claims are not PR authority.
            'unit_price' => '0.0001',
            'tax_amount' => '999999.999',
            'credit_limit' => '999999.999',
            'created_by' => $owner->id,
            'company_id' => 999999,
            'user_id' => $owner->id,
        ];

        $requestsBefore = DB::table('enterprise_purchase_requests')->count();
        $linesBefore = DB::table('enterprise_purchase_request_lines')->count();
        $auditBefore = DB::table('audit_logs')->count();
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $maker->forceFill(['locale' => $locale])->save();

            foreach ([
                ['lines.0.quantity', 'quantity', '2.1251'],
                ['lines.0.estimated_unit_cost', 'estimated_unit_cost', '123.4567'],
            ] as [$errorKey, $attribute, $unrepresentable]) {
                $invalid = $payload;
                $invalid['lines'][0][$attribute] = $unrepresentable;
                $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.requests.store'), $invalid)
                    ->assertSessionHasErrors($errorKey);
            }

            // Flat single-line form and service callers must not bypass the
            // 3-decimal contract applied to the multi-line API shape.
            $flat = [
                ...$payload,
                'item_code' => 'P111-PRECISION-FLAT',
                'description' => 'Unrepresentable flat line',
                'quantity' => '2.1251',
                'unit' => 'case',
                'estimated_unit_cost' => '0.0009',
            ];
            unset($flat['lines']);
            $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.requests.store'), $flat)
                ->assertSessionHasErrors(['quantity', 'estimated_unit_cost']);
        }
        $this->assertSame($requestsBefore, DB::table('enterprise_purchase_requests')->count());
        $this->assertSame($linesBefore, DB::table('enterprise_purchase_request_lines')->count());
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());

        // The service is independently validated, including for future
        // non-HTTP callers. No database mutation is made by this exception.
        try {
            app(PurchaseRequestManager::class)->create($maker->fresh(), $context, [
                'supplier_id' => $supplier->id,
                'currency_code' => 'KWD',
                'lines' => [[
                    'description' => 'Direct service overprecision',
                    'quantity' => '1.0001',
                    'unit' => 'case',
                    'estimated_unit_cost' => '1.0001',
                ]],
            ]);
            $this->fail('Service must reject precision not representable by DECIMAL(14,3) and DECIMAL(18,3).');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines.0.quantity', $exception->errors());
            $this->assertArrayHasKey('lines.0.estimated_unit_cost', $exception->errors());
        }
        $this->assertSame($requestsBefore, DB::table('enterprise_purchase_requests')->count());
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());

        $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.requests.store'), $payload)
            ->assertRedirect()->assertSessionHas('status');
        $request = DB::table('enterprise_purchase_requests')
            ->where('reason', $payload['reason'])->first();
        $this->assertNotNull($request);
        $this->assertSame('draft', $request->status);
        $this->assertSame($maker->id, (int) $request->created_by);
        $this->assertSame($store->id, (int) $request->store_id);
        $this->assertSame($context->enterpriseScope->companyId, (int) $request->company_id);
        $this->assertSame($supplier->id, (int) $request->supplier_id);
        $persisted = DB::table('enterprise_purchase_request_lines')
            ->where('purchase_request_id', $request->id)->orderBy('line_no')->get();
        $this->assertCount(2, $persisted);
        $this->assertSame('2.125', (string) $persisted[0]->quantity);
        $this->assertSame('123.456', (string) $persisted[0]->estimated_unit_cost);
        $this->assertSame('case', $persisted[0]->unit);
        $this->assertSame('0.001', (string) $persisted[1]->quantity);
        $this->assertSame('0.001', (string) $persisted[1]->estimated_unit_cost);
        $this->assertSame('box', $persisted[1]->unit);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'enterprise.purchase_request.created')
            ->where('auditable_id', $request->id)->count());

        $auditAfterCreate = DB::table('audit_logs')->count();
        $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.requests.store'), $payload)
            ->assertRedirect();
        $this->assertSame(1, DB::table('enterprise_purchase_requests')
            ->where('idempotency_key', $payload['idempotency_key'])
            ->where('store_id', $store->id)->where('created_by', $maker->id)->count());
        $this->assertSame($auditAfterCreate, DB::table('audit_logs')->count());

        $edit = [
            ...$payload,
            'purchase_request_id' => $request->id,
            'reason' => 'P111 valid exact draft edit',
            'lines' => [[
                'item_code' => 'P111-PRECISION-EDIT-SKU',
                'description' => 'Edited exact cost and unit',
                'quantity' => '3.125',
                'unit' => 'box',
                'estimated_unit_cost' => '99.999',
            ]],
        ];
        $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.requests.store'), $edit)
            ->assertRedirect()->assertSessionHas('status');
        $auditAfterEdit = DB::table('audit_logs')->count();
        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $request->id, 'status' => 'draft', 'reason' => 'P111 valid exact draft edit',
        ]);
        $this->assertDatabaseHas('enterprise_purchase_request_lines', [
            'purchase_request_id' => $request->id, 'item_code' => 'P111-PRECISION-EDIT-SKU',
            'quantity' => '3.125', 'unit' => 'box', 'estimated_unit_cost' => '99.999',
        ]);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'enterprise.purchase_request.updated')
            ->where('auditable_id', $request->id)->count());

        foreach ([
            ['lines.0.quantity', 'quantity', '3.1251'],
            ['lines.0.estimated_unit_cost', 'estimated_unit_cost', '99.9991'],
        ] as [$errorKey, $attribute, $invalidValue]) {
            $invalid = $edit;
            $invalid['lines'][0][$attribute] = $invalidValue;
            $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.requests.store'), $invalid)
                ->assertSessionHasErrors($errorKey);
        }
        $this->assertDatabaseHas('enterprise_purchase_request_lines', [
            'purchase_request_id' => $request->id, 'item_code' => 'P111-PRECISION-EDIT-SKU',
            'quantity' => '3.125', 'estimated_unit_cost' => '99.999',
        ]);
        $this->assertSame($auditAfterEdit, DB::table('audit_logs')->count());
        $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.requests.store'), $edit)
            ->assertRedirect();
        $this->assertSame($auditAfterEdit, DB::table('audit_logs')->count());

        foreach (['ar', 'en'] as $locale) {
            $maker->forceFill(['locale' => $locale])->save();
            $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.requests.submit', $request->id), [
                'store_id' => $store->id,
                'submitted_by' => $owner->id,
                'status' => 'approved',
            ])->assertRedirect();
        }
        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $request->id, 'status' => 'submitted', 'submitted_by' => $maker->id,
        ]);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'enterprise.purchase_request.submitted')
            ->where('auditable_id', $request->id)->count());
        $this->assertSame($auditAfterEdit + 1, DB::table('audit_logs')->count());

        $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.requests.store'), $edit)
            ->assertForbidden();
        $this->assertSame($auditAfterEdit + 1, DB::table('audit_logs')->count());
        $this->assertDatabaseHas('enterprise_purchase_request_lines', [
            'purchase_request_id' => $request->id, 'quantity' => '3.125', 'estimated_unit_cost' => '99.999',
        ]);
    }

    public function test_purchase_request_http_create_submit_replay_and_forged_supplier_price_credit_actor_are_scoped_and_audited(): void
    {
        $storeA = $this->wholesaleStore();
        $storeB = Store::query()->create([
            'store_type_id' => $storeA->store_type_id,
            'code' => 'P111-PR-FORGED-B',
            'name' => 'P111 second procurement company store',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($storeB);
        $owner = $this->actor('SUPER_ADMIN');
        $maker = $this->actor('PROCUREMENT_BUYER');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $owner,
        );

        $supplierA = app(SupplierDirectory::class)->create(
            $maker, app(TenantContextResolver::class)->wholesale($maker, $storeA->id), [
                'scope_type' => 'store',
                'code' => 'P111-PR-LEGAL-A',
                'name_en' => 'P111 legal PR supplier A',
                'tax_registration_number' => 'TAX-P111-PR-A',
            ],
        );
        $supplierB = app(SupplierDirectory::class)->create(
            $maker, app(TenantContextResolver::class)->wholesale($maker, $storeB->id), [
                'scope_type' => 'store',
                'code' => 'P111-PR-FOREIGN-B',
                'name_en' => 'P111 foreign supplier B',
            ],
        );
        $payload = [
            'store_id' => $storeA->id,
            'supplier_id' => $supplierA->id,
            'currency_code' => 'KWD',
            'reason' => 'P111 precision and forged identity lifecycle',
            'item_code' => 'P111-REAL-SKU-001',
            'description' => 'P111 legal catalog line',
            'quantity' => '2.125',
            'unit' => 'case',
            'estimated_unit_cost' => '123.456',
            // The POST boundary uses its validated whitelist, never the
            // actor, amount, credit, tax or ownership asserted by the client.
            'created_by' => $owner->id,
            'submitted_by' => $owner->id,
            'company_id' => 999999,
            'organization_id' => 999999,
            'unit_price' => '0.001',
            'credit_limit' => '999999999.999',
            'tax_amount' => '0.001',
        ];
        $auditBefore = DB::table('audit_logs')->count();
        $this->actingAs($maker)->post(route('admin.erp.procurement.requests.store'), $payload)
            ->assertRedirect()
            ->assertSessionHas('status');

        $row = DB::table('enterprise_purchase_requests')->where('reason', $payload['reason'])->first();
        $this->assertNotNull($row);
        $this->assertSame('draft', $row->status);
        $this->assertSame((int) $maker->id, (int) $row->created_by);
        $this->assertSame((int) $maker->id, (int) $row->updated_by);
        $this->assertNull($row->submitted_by);
        $this->assertSame((int) $storeA->id, (int) $row->store_id);
        $this->assertSame((int) $supplierA->id, (int) $row->supplier_id);
        $scopeA = app(TenantContextResolver::class)->wholesale($maker, $storeA->id)->enterpriseScope;
        $this->assertSame($scopeA->companyId, (int) $row->company_id);
        $this->assertSame($scopeA->organizationId, (int) $row->organization_id);
        $this->assertSame('KWD', $row->currency_code);
        $line = DB::table('enterprise_purchase_request_lines')
            ->where('purchase_request_id', $row->id)->first();
        $this->assertNotNull($line);
        $this->assertSame('P111-REAL-SKU-001', $line->item_code);
        $this->assertSame('case', $line->unit);
        $this->assertSame('2.125', (string) $line->quantity);
        $this->assertSame('123.456', (string) $line->estimated_unit_cost);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('event', 'enterprise.purchase_request.created')
            ->where('auditable_id', $row->id)->count());
        $this->assertSame($auditBefore + 1, DB::table('audit_logs')->count());

        $auditAfterCreate = DB::table('audit_logs')->count();
        $requestsAfterCreate = DB::table('enterprise_purchase_requests')->count();
        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $maker->forceFill(['locale' => $locale])->save();
            $this->actingAs($maker->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $storeA->id,
                'tab' => 'requests',
                'request' => $row->id,
            ]))->assertOk()->assertSee('dir="'.$direction.'"', false)
                ->assertSee($row->request_number);

            $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.requests.store'), [
                ...$payload,
                'reason' => 'P111 foreign supplier must not create PR',
                'supplier_id' => $supplierB->id,
            ])->assertNotFound();

            $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.requests.store'), [
                ...$payload,
                'reason' => 'P111 negative estimated cost must not create PR',
                'estimated_unit_cost' => '-0.001',
            ])->assertSessionHasErrors('estimated_unit_cost');

            $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.requests.store'), [
                ...$payload,
                'reason' => 'P111 invalid decimal quantity must not create PR',
                'quantity' => '0',
            ])->assertSessionHasErrors('quantity');
        }
        $this->assertSame($requestsAfterCreate, DB::table('enterprise_purchase_requests')->count());
        $this->assertSame($auditAfterCreate, DB::table('audit_logs')->count());

        // The first submit transitions draft -> submitted under lock;
        // retrying that SAME transition must not append another audit.
        foreach (['ar', 'en'] as $locale) {
            $maker->forceFill(['locale' => $locale])->save();
            $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.requests.submit', $row->id), [
                'store_id' => $storeA->id,
                'submitted_by' => $owner->id,
                'status' => 'approved',
            ])->assertRedirect();
        }
        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $row->id,
            'status' => 'submitted',
            'created_by' => $maker->id,
            'submitted_by' => $maker->id,
            'decided_by' => null,
        ]);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('event', 'enterprise.purchase_request.submitted')
            ->where('auditable_id', $row->id)->count());

        // Revoke B while preserving the legitimate submitted request in A:
        // a forged cross-store submit cannot use a global Buyer identity.
        app(ModuleEntitlementManager::class)->set(
            'procurement', new EntitlementScope(EntitlementScope::STORE, $storeB->id),
            false, null, $owner,
        );
        $auditBeforeDeny = DB::table('audit_logs')->count();
        foreach (['ar', 'en'] as $locale) {
            $maker->forceFill(['locale' => $locale])->save();
            $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.requests.submit', $row->id), [
                'store_id' => $storeB->id,
                'user_id' => $owner->id,
            ])->assertForbidden();
        }
        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $row->id, 'status' => 'submitted', 'store_id' => $storeA->id,
        ]);
        $this->assertSame($auditBeforeDeny, DB::table('audit_logs')->count());
    }

    public function test_purchase_request_create_idempotency_replays_by_store_and_maker_and_rejects_changed_payload(): void
    {
        $store = $this->wholesaleStore();
        $otherStore = Store::query()->create([
            'store_type_id' => $store->store_type_id,
            'code' => 'P111-PR-REPLAY-OTHER',
            'name' => 'P111 other idempotency store',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($otherStore);
        $owner = $this->actor('SUPER_ADMIN');
        $maker = $this->actor('PROCUREMENT_BUYER');
        $otherMaker = $this->actor('PROCUREMENT_BUYER');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set('procurement', EntitlementScope::platform(), true, null, $owner);

        $payload = [
            'store_id' => $store->id,
            'idempotency_key' => '7bcdf271-6e11-4b2c-bfc8-8c0679e3ca12',
            'currency_code' => 'KWD',
            'reason' => 'P111 create replay remains one draft',
            'item_code' => 'P111-REPLAY-ITEM',
            'description' => 'Replay-safe request line',
            'quantity' => '2.100',
            'unit' => 'case',
            'estimated_unit_cost' => '4.000',
        ];
        $auditBefore = DB::table('audit_logs')->count();

        $this->actingAs($maker)->post(route('admin.erp.procurement.requests.store'), $payload)->assertRedirect();
        $request = DB::table('enterprise_purchase_requests')
            ->where('created_by', $maker->id)
            ->where('idempotency_key', $payload['idempotency_key'])
            ->first();
        $this->assertNotNull($request);

        // Decimal formatting differences map to the same database precision
        // and therefore the same request payload identity.
        $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.requests.store'), [
            ...$payload,
            'quantity' => '2.1',
            'estimated_unit_cost' => '4',
        ])->assertRedirect();
        $this->assertSame(1, DB::table('enterprise_purchase_requests')
            ->where('created_by', $maker->id)
            ->where('idempotency_key', $payload['idempotency_key'])
            ->count());
        $this->assertSame(1, DB::table('audit_logs')
            ->where('event', 'enterprise.purchase_request.created')
            ->where('auditable_id', $request->id)
            ->count());

        $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.requests.store'), [
            ...$payload,
            'quantity' => '3.000',
        ])->assertSessionHasErrors('idempotency_key');
        $this->assertSame(1, DB::table('enterprise_purchase_requests')
            ->where('created_by', $maker->id)
            ->where('idempotency_key', $payload['idempotency_key'])
            ->count());
        $this->assertSame($auditBefore + 1, DB::table('audit_logs')->count());

        // The same opaque key is independently available to a different
        // maker and to the original maker in a different authorized store.
        $this->actingAs($otherMaker)->post(route('admin.erp.procurement.requests.store'), $payload)->assertRedirect();
        $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.requests.store'), [
            ...$payload,
            'store_id' => $otherStore->id,
        ])->assertRedirect();
        $this->assertSame(1, DB::table('enterprise_purchase_requests')
            ->where('store_id', $store->id)
            ->where('created_by', $otherMaker->id)
            ->where('idempotency_key', $payload['idempotency_key'])
            ->count());
        $this->assertSame(1, DB::table('enterprise_purchase_requests')
            ->where('store_id', $otherStore->id)
            ->where('created_by', $maker->id)
            ->where('idempotency_key', $payload['idempotency_key'])
            ->count());
        $this->assertSame($auditBefore + 3, DB::table('audit_logs')->count());
    }

    public function test_purchase_request_draft_edit_is_maker_scoped_replay_safe_and_denies_foreign_or_approved_requests(): void
    {
        $storeA = $this->wholesaleStore();
        $storeB = Store::query()->create([
            'store_type_id' => $storeA->store_type_id,
            'code' => 'P111-PR-EDIT-FOREIGN',
            'name' => 'P111 foreign request edit store',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($storeB);
        $owner = $this->actor('SUPER_ADMIN');
        $maker = $this->actor('PROCUREMENT_BUYER');
        $otherMaker = $this->actor('PROCUREMENT_BUYER');
        $checker = $this->actor('PROCUREMENT_APPROVER');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set('procurement', EntitlementScope::platform(), true, null, $owner);

        $this->actingAs($maker)->post(route('admin.erp.procurement.requests.store'), [
            'store_id' => $storeA->id,
            'idempotency_key' => '8b5e510f-ec41-4d86-b0f7-e10c2da72053',
            'currency_code' => 'KWD',
            'reason' => 'P111 request draft before edit',
            'item_code' => 'P111-EDIT-OLD',
            'description' => 'Original draft line',
            'quantity' => '1.000',
            'unit' => 'case',
            'estimated_unit_cost' => '2.500',
        ])->assertRedirect();
        $request = DB::table('enterprise_purchase_requests')
            ->where('created_by', $maker->id)
            ->where('reason', 'P111 request draft before edit')
            ->first();
        $this->assertNotNull($request);

        $edit = [
            'store_id' => $storeA->id,
            'purchase_request_id' => $request->id,
            'currency_code' => 'KWD',
            'needed_by' => '2026-12-05',
            'reason' => 'P111 request draft edited',
            'notes' => 'Edited before submission',
            'lines' => [[
                'item_code' => 'P111-EDIT-NEW',
                'description' => 'Updated draft line',
                'quantity' => '3.125',
                'unit' => 'box',
                'estimated_unit_cost' => '6.750',
            ]],
        ];
        $auditBeforeEdit = DB::table('audit_logs')->count();
        $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.requests.store'), $edit)
            ->assertRedirect()->assertSessionHas('status');

        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $request->id,
            'created_by' => $maker->id,
            'updated_by' => $maker->id,
            'status' => 'draft',
            'reason' => 'P111 request draft edited',
            'notes' => 'Edited before submission',
        ]);
        $storedNeededBy = DB::table('enterprise_purchase_requests')
            ->where('id', $request->id)
            ->value('needed_by');
        $this->assertSame('2026-12-05', substr((string) $storedNeededBy, 0, 10));
        $this->assertDatabaseHas('enterprise_purchase_request_lines', [
            'purchase_request_id' => $request->id,
            'item_code' => 'P111-EDIT-NEW',
            'description' => 'Updated draft line',
            'quantity' => '3.125',
            'unit' => 'box',
            'estimated_unit_cost' => '6.750',
        ]);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('event', 'enterprise.purchase_request.updated')
            ->where('auditable_id', $request->id)
            ->count());
        $this->assertSame($auditBeforeEdit + 1, DB::table('audit_logs')->count());

        // Retrying the exact same edit is a no-op and cannot append a second audit.
        $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.requests.store'), $edit)->assertRedirect();
        $this->assertSame($auditBeforeEdit + 1, DB::table('audit_logs')->count());

        // A different buyer may have the same role but cannot edit this maker's draft.
        $this->actingAs($otherMaker)->post(route('admin.erp.procurement.requests.store'), $edit)->assertForbidden();

        // Selecting another authorized store cannot turn the foreign ID into an upsert.
        $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.requests.store'), [
            ...$edit,
            'store_id' => $storeB->id,
            'reason' => 'must not cross store',
        ])->assertNotFound();

        // A missing supplied ID is also a scoped lookup failure, never a create.
        $requestCount = DB::table('enterprise_purchase_requests')->count();
        $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.requests.store'), [
            ...$edit,
            'purchase_request_id' => 999999999,
        ])->assertNotFound();
        $this->assertSame($requestCount, DB::table('enterprise_purchase_requests')->count());

        $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.requests.submit', $request->id), [
            'store_id' => $storeA->id,
        ])->assertRedirect();
        $this->actingAs($checker)->post(route('admin.erp.procurement.requests.decision', $request->id), [
            'store_id' => $storeA->id,
            'decision' => 'approve',
        ])->assertRedirect();
        $this->assertDatabaseHas('enterprise_purchase_requests', ['id' => $request->id, 'status' => 'approved']);

        $approvedAuditCount = DB::table('audit_logs')->count();
        $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.requests.store'), [
            ...$edit,
            'reason' => 'must not edit approved request',
        ])->assertForbidden();
        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $request->id,
            'status' => 'approved',
            'reason' => 'P111 request draft edited',
        ]);
        $this->assertSame($approvedAuditCount, DB::table('audit_logs')->count());
    }

    public function test_independent_checker_approves_or_rejects_submitted_requests_once_with_immutable_decisions_and_scoped_audit(): void
    {
        $storeA = $this->wholesaleStore();
        $storeB = Store::query()->create([
            'store_type_id' => $storeA->store_type_id,
            'code' => 'P111-DECISION-FOREIGN-B2B',
            'name' => 'P111 separate checker tenant store',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($storeB);

        $owner = $this->actor('SUPER_ADMIN');
        $maker = $this->actor('PROCUREMENT_BUYER');
        $checker = $this->actor('PROCUREMENT_APPROVER');
        $secondChecker = $this->actor('PROCUREMENT_APPROVER');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $owner,
        );
        $supplier = app(SupplierDirectory::class)->create(
            $maker,
            app(TenantContextResolver::class)->wholesale($maker, $storeA->id),
            [
                'scope_type' => 'store',
                'code' => 'P111-DECISION-LEGAL-SUP',
                'name_en' => 'P111 independent checker legal supplier',
            ],
        );
        $this->assertFalse($maker->hasPermission('procurement.purchase_requests.approve', $storeA->id));
        $this->assertTrue($checker->hasPermission('procurement.purchase_requests.approve', $storeA->id));

        foreach (['approved' => 'approve', 'rejected' => 'reject'] as $expectedStatus => $decision) {
            $reason = 'P111 independent checker '.$expectedStatus;
            $this->actingAs($maker)->post(route('admin.erp.procurement.requests.store'), [
                'store_id' => $storeA->id,
                'supplier_id' => $supplier->id,
                'currency_code' => 'KWD',
                'reason' => $reason,
                'item_code' => 'P111-DECISION-SKU',
                'description' => 'P111 separately approved or rejected request',
                'quantity' => '1.250',
                'unit' => 'case',
                'estimated_unit_cost' => '9.875',
            ])->assertRedirect();
            $row = DB::table('enterprise_purchase_requests')->where('reason', $reason)->first();
            $this->assertNotNull($row);
            $this->assertSame('draft', $row->status);
            $this->assertSame((int) $supplier->id, (int) $row->supplier_id);
            $this->actingAs($maker)->post(route('admin.erp.procurement.requests.submit', $row->id), [
                'store_id' => $storeA->id,
            ])->assertRedirect();
            $submitted = DB::table('enterprise_purchase_requests')->where('id', $row->id)->first();
            $this->assertSame('submitted', $submitted->status);
            $this->assertNotNull($submitted->submitted_at);
            $this->assertSame((int) $maker->id, (int) $submitted->submitted_by);

            $auditBeforeDenied = DB::table('audit_logs')->count();
            foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
                $maker->forceFill(['locale' => $locale])->save();
                $checker->forceFill(['locale' => $locale])->save();

                // A valid form/header or forged decision-maker never grants
                // a Buyer approval authority. Authorization is server-side.
                $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.requests.decision', $row->id), [
                    'store_id' => $storeA->id,
                    'decision' => $decision,
                    'decided_by' => $owner->id,
                    'status' => $expectedStatus,
                ])->assertForbidden();

                // The checker is authorized in both wholesale stores, but
                // the persisted Store A request must not be found in Store B.
                $this->actingAs($checker->fresh())->post(route('admin.erp.procurement.requests.decision', $row->id), [
                    'store_id' => $storeB->id,
                    'decision' => $decision,
                ])->assertNotFound();

                $this->actingAs($checker->fresh())->get(route('admin.erp.procurement.index', [
                    'store_id' => $storeA->id,
                    'tab' => 'requests',
                    'request' => $row->id,
                ]))->assertOk()->assertSee('dir="'.$direction.'"', false);
            }
            $this->assertSame($auditBeforeDenied, DB::table('audit_logs')->count());
            $note = $expectedStatus === 'approved'
                ? 'Independent checker verified legal supply'
                : 'Independent checker rejected invalid requested terms';
            $this->actingAs($checker->fresh())->post(route('admin.erp.procurement.requests.decision', $row->id), [
                'store_id' => $storeA->id,
                'decision' => $decision,
                'note' => $note,
                'status' => $expectedStatus === 'approved' ? 'rejected' : 'approved',
                'decided_by' => $owner->id,
                'submitted_by' => $owner->id,
                'decided_at' => now()->addYear()->toDateTimeString(),
            ])->assertRedirect()->assertSessionHas('status');

            $decided = DB::table('enterprise_purchase_requests')->where('id', $row->id)->first();
            $this->assertSame($expectedStatus, $decided->status);
            $this->assertSame((int) $checker->id, (int) $decided->decided_by);
            $this->assertSame((int) $maker->id, (int) $decided->submitted_by);
            $this->assertSame((string) $submitted->submitted_at, (string) $decided->submitted_at);
            $this->assertNotNull($decided->decided_at);
            $this->assertSame($note, $decided->decision_note);
            $this->assertSame((int) $supplier->id, (int) $decided->supplier_id);
            $this->assertSame(1, DB::table('audit_logs')
                ->where('event', 'enterprise.purchase_request.'.$expectedStatus)
                ->where('auditable_id', $row->id)->count());

            $auditBeforeReplay = DB::table('audit_logs')->count();
            foreach ([$checker, $secondChecker] as $retryActor) {
                $this->actingAs($retryActor->fresh())->post(route('admin.erp.procurement.requests.decision', $row->id), [
                    'store_id' => $storeA->id,
                    'decision' => $decision,
                    'note' => 'Forged repeat should not overwrite historical decision',
                ])->assertRedirect();
            }
            $replayed = DB::table('enterprise_purchase_requests')->where('id', $row->id)->first();
            $this->assertSame((string) $decided->decided_at, (string) $replayed->decided_at);
            $this->assertSame((int) $checker->id, (int) $replayed->decided_by);
            $this->assertSame($note, $replayed->decision_note);
            $this->assertSame((int) $supplier->id, (int) $replayed->supplier_id);
            $this->assertSame($auditBeforeReplay, DB::table('audit_logs')->count());

            $opposite = $decision === 'approve' ? 'reject' : 'approve';
            $this->actingAs($checker->fresh())->post(route('admin.erp.procurement.requests.decision', $row->id), [
                'store_id' => $storeA->id,
                'decision' => $opposite,
            ])->assertSessionHasErrors('status');
            $this->assertDatabaseHas('enterprise_purchase_requests', [
                'id' => $row->id,
                'status' => $expectedStatus,
                'decided_by' => $checker->id,
            ]);
            $this->assertSame($auditBeforeReplay, DB::table('audit_logs')->count());
            $this->assertSame(0, DB::table('audit_logs')
                ->where('event', 'enterprise.purchase_request.'.($opposite === 'approve' ? 'approved' : 'rejected'))
                ->where('auditable_id', $row->id)->count());
        }
    }

    public function test_procurement_rfq_http_draft_publish_cancel_keeps_real_request_supplier_store_due_date_and_single_audit(): void
    {
        $storeA = $this->wholesaleStore();
        $storeB = Store::query()->create([
            'store_type_id' => $storeA->store_type_id,
            'code' => 'P111-RFQ-SCOPE-B',
            'name' => 'P111 RFQ foreign wholesale store',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($storeB);
        $owner = $this->actor('SUPER_ADMIN');
        $maker = $this->actor('PROCUREMENT_BUYER');
        $checker = $this->actor('PROCUREMENT_MANAGER');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $owner,
        );
        $supplierA = app(SupplierDirectory::class)->create(
            $maker, app(TenantContextResolver::class)->wholesale($maker, $storeA->id), [
                'scope_type' => 'store',
                'code' => 'P111-RFQ-SUP-A',
                'name_en' => 'P111 eligible RFQ supplier A',
            ],
        );
        $supplierB = app(SupplierDirectory::class)->create(
            $maker, app(TenantContextResolver::class)->wholesale($maker, $storeB->id), [
                'scope_type' => 'store',
                'code' => 'P111-RFQ-SUP-B',
                'name_en' => 'P111 foreign RFQ supplier B',
            ],
        );
        $this->actingAs($maker)->post(route('admin.erp.procurement.requests.store'), [
            'store_id' => $storeA->id,
            'supplier_id' => $supplierA->id,
            'currency_code' => 'KWD',
            'reason' => 'P111 RFQ status scope evidence',
            'description' => 'Source-linked RFQ line',
            'item_code' => 'P111-RFQ-ITEM',
            'quantity' => '2.125',
            'unit' => 'case',
            'estimated_unit_cost' => '4.125',
        ])->assertRedirect();
        $request = DB::table('enterprise_purchase_requests')
            ->where('reason', 'P111 RFQ status scope evidence')->first();
        $this->assertNotNull($request);
        $due = now()->addDays(5)->startOfMinute()->toDateTimeString();
        $payload = [
            'store_id' => $storeA->id,
            'purchase_request_id' => $request->id,
            'title' => 'P111 source-linked RFQ without premature approval',
            'response_due_at' => $due,
            'created_by' => $owner->id,
            'status' => 'awarded',
            'supplier_id' => $supplierB->id,
        ];
        $auditBefore = DB::table('audit_logs')->count();

        // A draft PR and a forged old RFQ deadline cannot be elevated into a
        // real published RFQ or an approved purchase order via form fields.
        $this->actingAs($maker)->post(route('admin.erp.procurement.rfqs.store'), $payload)
            ->assertSessionHasErrors('purchase_request_id');
        $this->actingAs($maker)->post(route('admin.erp.procurement.rfqs.store'), [
            ...$payload,
            'response_due_at' => now()->subDays(2)->toDateTimeString(),
        ])->assertSessionHasErrors('response_due_at');
        $this->assertDatabaseCount('enterprise_procurement_rfqs', 0);
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());

        $this->actingAs($maker)->post(route('admin.erp.procurement.requests.submit', $request->id), [
            'store_id' => $storeA->id,
        ])->assertRedirect();
        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $request->id, 'status' => 'submitted', 'submitted_by' => $maker->id,
        ]);
        $auditAfterSubmit = DB::table('audit_logs')->count();

        foreach (['ar', 'en'] as $locale) {
            $maker->forceFill(['locale' => $locale])->save();
            $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.rfqs.store'), [
                ...$payload,
                'store_id' => $storeB->id,
            ])->assertNotFound();
        }
        $this->assertSame($auditAfterSubmit, DB::table('audit_logs')->count());

        $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.rfqs.store'), $payload)
            ->assertRedirect()->assertSessionHas('status');
        $rfq = DB::table('enterprise_procurement_rfqs')
            ->where('purchase_request_id', $request->id)->first();
        $this->assertNotNull($rfq);
        $this->assertSame('draft', $rfq->status);
        $this->assertSame((int) $maker->id, (int) $rfq->created_by);
        $this->assertSame((int) $storeA->id, (int) $rfq->store_id);
        $this->assertSame('KWD', $rfq->currency_code);
        $this->assertSame($due, (string) $rfq->response_due_at);
        $rfqLine = DB::table('enterprise_procurement_rfq_lines')
            ->where('rfq_id', $rfq->id)->first();
        $this->assertNotNull($rfqLine);
        $this->assertSame('P111-RFQ-ITEM', $rfqLine->item_code);
        $this->assertSame('case', $rfqLine->unit);
        $this->assertSame('2.125', (string) $rfqLine->quantity);
        $this->assertSame('4.125', (string) $rfqLine->baseline_unit_cost);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.rfq.created')->count());

        $auditAfterRfqCreated = DB::table('audit_logs')->count();
        $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.rfqs.store'), [
            ...$payload,
            'title' => 'A replay cannot rewrite the original RFQ title',
            'created_by' => $owner->id,
        ])->assertRedirect();
        $this->assertDatabaseCount('enterprise_procurement_rfqs', 1);
        $this->assertDatabaseHas('enterprise_procurement_rfqs', [
            'id' => $rfq->id, 'title' => $payload['title'], 'status' => 'draft',
        ]);
        $this->assertSame($auditAfterRfqCreated, DB::table('audit_logs')->count());

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $maker->forceFill(['locale' => $locale])->save();
            $this->actingAs($maker->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $storeA->id, 'tab' => 'rfqs', 'rfq' => $rfq->id,
            ]))->assertOk()->assertSee('dir="'.$direction.'"', false)
                ->assertSee($rfq->rfq_number);
            $this->actingAs($maker->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $storeB->id, 'tab' => 'rfqs', 'rfq' => $rfq->id,
            ]))->assertNotFound();
        }
        $auditBeforeForeignSupplier = DB::table('audit_logs')->count();
        $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.rfqs.publish', $rfq->id), [
            'store_id' => $storeA->id,
            'supplier_ids' => [$supplierA->id, $supplierB->id],
        ])->assertSessionHasErrors('supplier_ids');
        $this->assertDatabaseHas('enterprise_procurement_rfqs', [
            'id' => $rfq->id, 'status' => 'draft',
        ]);
        $this->assertSame(0, DB::table('enterprise_procurement_rfq_invitations')
            ->where('rfq_id', $rfq->id)->count());
        $this->assertSame($auditBeforeForeignSupplier, DB::table('audit_logs')->count());

        $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.rfqs.publish', $rfq->id), [
            'store_id' => $storeA->id,
            'supplier_ids' => [$supplierA->id],
            'published_by' => $owner->id,
            'expires_at' => now()->addYears(4)->toDateTimeString(),
        ])->assertRedirect()->assertSessionHas('status');
        $this->assertDatabaseHas('enterprise_procurement_rfqs', [
            'id' => $rfq->id, 'status' => 'published',
            'published_by' => $maker->id,
        ]);
        $invitation = DB::table('enterprise_procurement_rfq_invitations')
            ->where('rfq_id', $rfq->id)->first();
        $this->assertNotNull($invitation);
        $this->assertSame((int) $supplierA->id, (int) $invitation->supplier_id);
        $this->assertSame('invited', $invitation->status);
        $this->assertSame($due, (string) $invitation->expires_at);
        $this->assertSame(64, strlen((string) $invitation->token_hash));
        $this->assertSame(1, DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.rfq.published')->count());

        $auditAfterPublish = DB::table('audit_logs')->count();
        $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.rfqs.publish', $rfq->id), [
            'store_id' => $storeA->id,
            'supplier_ids' => [$supplierA->id],
        ])->assertRedirect();
        $this->assertSame(1, DB::table('enterprise_procurement_rfq_invitations')
            ->where('rfq_id', $rfq->id)->count());
        $this->assertSame($auditAfterPublish, DB::table('audit_logs')->count());

        // Visible quote/award controls are not an authorization grant:
        // BUYER lacks approval rights, regardless of forged quote/actor IDs.
        $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.rfqs.award', [
            'rfq' => $rfq->id, 'quote' => 999999,
        ]), [
            'store_id' => $storeA->id,
            'awarded_by' => $owner->id,
        ])->assertForbidden();
        $this->assertDatabaseCount('enterprise_purchase_orders', 0);
        $this->assertSame($auditAfterPublish, DB::table('audit_logs')->count());

        $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.rfqs.cancel', $rfq->id), [
            'store_id' => $storeA->id,
        ])->assertRedirect();
        $cancelled = DB::table('enterprise_procurement_rfqs')->where('id', $rfq->id)->first();
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame((int) $maker->id, (int) $cancelled->cancelled_by);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertDatabaseHas('enterprise_procurement_rfq_invitations', [
            'id' => $invitation->id, 'status' => 'revoked',
        ]);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.rfq.cancelled')->count());

        $auditAfterCancel = DB::table('audit_logs')->count();
        $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.rfqs.cancel', $rfq->id), [
            'store_id' => $storeA->id,
        ])->assertRedirect();
        $this->assertSame($auditAfterCancel, DB::table('audit_logs')->count());
        $this->assertSame((string) $cancelled->cancelled_at, (string) DB::table('enterprise_procurement_rfqs')
            ->where('id', $rfq->id)->value('cancelled_at'));

        $this->actingAs($checker)->post(route('admin.erp.procurement.rfqs.award', [
            'rfq' => $rfq->id, 'quote' => 999999,
        ]), ['store_id' => $storeA->id])->assertSessionHasErrors('status');
        $this->assertDatabaseCount('enterprise_purchase_orders', 0);
        $this->assertSame($auditAfterCancel, DB::table('audit_logs')->count());
    }

    public function test_approved_purchase_request_http_po_create_issue_cancel_detail_and_replay_preserve_server_scope(): void
    {
        $storeA = $this->wholesaleStore();
        $storeB = Store::query()->create([
            'store_type_id' => $storeA->store_type_id,
            'code' => 'P111-PO-SECOND-B2B',
            'name' => 'P111 distinct PO scope B',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($storeB);

        $owner = $this->actor('SUPER_ADMIN');
        $maker = $this->actor('PROCUREMENT_BUYER');
        $checker = $this->actor('PROCUREMENT_APPROVER');
        $secondChecker = $this->actor('PROCUREMENT_APPROVER');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $owner,
        );
        $supplier = app(SupplierDirectory::class)->create(
            $maker,
            app(TenantContextResolver::class)->wholesale($maker, $storeA->id),
            [
                'scope_type' => 'store',
                'code' => 'P111-PO-REAL-SUP-A',
                'name_en' => 'P111 source-approved supplier',
            ],
        );
        $reason = 'P111 approved PO HTTP full server authority';
        $this->actingAs($maker)->post(route('admin.erp.procurement.requests.store'), [
            'store_id' => $storeA->id,
            'supplier_id' => $supplier->id,
            'currency_code' => 'KWD',
            'reason' => $reason,
            'needed_by' => now()->addDays(8)->toDateString(),
            'item_code' => 'P111-PO-SKU',
            'description' => 'P111 linked purchase-order line',
            'quantity' => '2.125',
            'unit' => 'case',
            'estimated_unit_cost' => '12.375',
        ])->assertRedirect();
        $request = DB::table('enterprise_purchase_requests')->where('reason', $reason)->first();
        $this->assertNotNull($request);
        $this->assertSame('draft', $request->status);
        $auditBeforeEarlyIssue = DB::table('audit_logs')->count();

        $this->actingAs($maker)->post(route('admin.erp.procurement.orders.create', $request->id), [
            'store_id' => $storeA->id,
            'status' => 'issued',
            'issued_by' => $checker->id,
        ])->assertSessionHasErrors('purchase_request_id');
        $this->actingAs($maker)->post(route('admin.erp.procurement.requests.submit', $request->id), [
            'store_id' => $storeA->id,
        ])->assertRedirect();
        $this->actingAs($maker)->post(route('admin.erp.procurement.orders.create', $request->id), [
            'store_id' => $storeA->id,
            'status' => 'issued',
        ])->assertSessionHasErrors('purchase_request_id');
        $this->assertDatabaseCount('enterprise_purchase_orders', 0);
        $this->assertSame($auditBeforeEarlyIssue + 1, DB::table('audit_logs')->count());

        $this->actingAs($checker)->post(route('admin.erp.procurement.requests.decision', $request->id), [
            'store_id' => $storeA->id,
            'decision' => 'approve',
            'note' => 'P111 independent checker approved the source request',
        ])->assertRedirect();
        $approved = DB::table('enterprise_purchase_requests')->where('id', $request->id)->first();
        $this->assertSame('approved', $approved->status);
        $this->assertSame((int) $checker->id, (int) $approved->decided_by);
        $this->assertNotNull($approved->decided_at);

        $poRoute = route('admin.erp.procurement.orders.create', $request->id);
        $auditBeforeDenied = DB::table('audit_logs')->count();
        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $maker->forceFill(['locale' => $locale])->save();
            $this->actingAs($maker->fresh())->post($poRoute, [
                'store_id' => $storeB->id,
                'issued_by' => $owner->id,
                'status' => 'issued',
            ])->assertNotFound();
            $this->actingAs($maker->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $storeA->id,
                'tab' => 'orders',
            ]))->assertOk()->assertSee('dir="'.$direction.'"', false);
        }
        $this->assertDatabaseCount('enterprise_purchase_orders', 0);
        $this->assertSame($auditBeforeDenied, DB::table('audit_logs')->count());

        $this->actingAs($maker->fresh())->post($poRoute, [
            'store_id' => $storeA->id,
            'status' => 'issued',
            'created_by' => $owner->id,
            'issued_by' => $owner->id,
            'supplier_id' => 999999,
            'company_id' => 999999,
            'order_number' => 'P111-FORGED-DUPLICATE-NUMBER',
            'total_amount' => '-99999.999',
            'unit_price' => '-15.125',
            'discount_percent' => 99,
            'procurement_contract_id' => 999999,
        ])->assertRedirect()->assertSessionHas('status');

        $po = DB::table('enterprise_purchase_orders')->where('purchase_request_id', $request->id)->first();
        $this->assertNotNull($po);
        $this->assertSame('draft', $po->status);
        $this->assertSame((int) $maker->id, (int) $po->created_by);
        $this->assertNull($po->issued_by);
        $this->assertSame((int) $supplier->id, (int) $po->supplier_id);
        $this->assertSame((int) $storeA->id, (int) $po->store_id);
        $this->assertSame((int) $approved->company_id, (int) $po->company_id);
        $this->assertSame((int) $approved->organization_id, (int) $po->organization_id);
        $this->assertSame($request->request_number, $po->source_request_number);
        $this->assertSame('KWD', $po->currency_code);
        $this->assertNull($po->procurement_contract_id);
        $this->assertNotSame('P111-FORGED-DUPLICATE-NUMBER', $po->order_number);
        $this->assertSame((string) $approved->needed_by, (string) $po->needed_by);

        $prLine = DB::table('enterprise_purchase_request_lines')
            ->where('purchase_request_id', $request->id)->first();
        $poLine = DB::table('enterprise_purchase_order_lines')
            ->where('purchase_order_id', $po->id)->first();
        $this->assertNotNull($prLine);
        $this->assertNotNull($poLine);
        $this->assertSame((int) $prLine->id, (int) $poLine->source_purchase_request_line_id);
        $this->assertSame('P111-PO-SKU', $poLine->item_code);
        $this->assertSame('case', $poLine->unit);
        $this->assertSame('2.125', (string) $poLine->quantity);
        $this->assertSame('12.375', (string) $poLine->unit_price);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('event', 'enterprise.purchase_order.created')
            ->where('auditable_id', $po->id)->count());

        $auditAfterCreate = DB::table('audit_logs')->count();
        $this->actingAs($maker->fresh())->post($poRoute, [
            'store_id' => $storeA->id,
            'order_number' => 'P111-FORGED-DUPLICATE-NUMBER',
            'status' => 'issued',
        ])->assertRedirect();
        $this->assertSame(1, DB::table('enterprise_purchase_orders')
            ->where('purchase_request_id', $request->id)->count());
        $this->assertDatabaseHas('enterprise_purchase_orders', [
            'id' => $po->id, 'order_number' => $po->order_number, 'status' => 'draft',
        ]);
        $this->assertSame($auditAfterCreate, DB::table('audit_logs')->count());

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $maker->forceFill(['locale' => $locale])->save();
            $this->actingAs($maker->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $storeA->id,
                'tab' => 'orders',
                'order' => $po->id,
            ]))->assertOk()->assertSee('dir="'.$direction.'"', false)
                ->assertSee($po->order_number)->assertSee($supplier->code);
            $this->actingAs($maker->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $storeB->id,
                'tab' => 'orders',
                'order' => $po->id,
            ]))->assertNotFound();
        }

        $auditBeforeDeniedIssue = DB::table('audit_logs')->count();
        $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.orders.issue', $po->id), [
            'store_id' => $storeA->id,
            'issued_by' => $checker->id,
        ])->assertForbidden();
        $this->actingAs($checker)->post(route('admin.erp.procurement.orders.issue', $po->id), [
            'store_id' => $storeB->id,
        ])->assertNotFound();
        $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.orders.cancel', $po->id), [
            'store_id' => $storeB->id,
        ])->assertNotFound();
        $this->assertSame($auditBeforeDeniedIssue, DB::table('audit_logs')->count());
        $this->assertDatabaseHas('enterprise_purchase_orders', [
            'id' => $po->id, 'status' => 'draft', 'issued_by' => null,
        ]);

        $this->actingAs($checker)->post(route('admin.erp.procurement.orders.issue', $po->id), [
            'store_id' => $storeA->id,
            'issued_by' => $owner->id,
            'status' => 'cancelled',
            'order_number' => 'P111-FORGED-DUPLICATE-NUMBER',
        ])->assertRedirect();
        $issued = DB::table('enterprise_purchase_orders')->where('id', $po->id)->first();
        $this->assertSame('issued', $issued->status);
        $this->assertSame((int) $checker->id, (int) $issued->issued_by);
        $this->assertNotNull($issued->issued_at);
        $this->assertSame((int) $maker->id, (int) $issued->created_by);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('event', 'enterprise.purchase_order.issued')
            ->where('auditable_id', $po->id)->count());

        $auditAfterIssue = DB::table('audit_logs')->count();
        $this->actingAs($secondChecker)->post(route('admin.erp.procurement.orders.issue', $po->id), [
            'store_id' => $storeA->id,
        ])->assertRedirect();
        $this->assertSame($auditAfterIssue, DB::table('audit_logs')->count());
        $replayedIssue = DB::table('enterprise_purchase_orders')->where('id', $po->id)->first();
        $this->assertSame((int) $checker->id, (int) $replayedIssue->issued_by);
        $this->assertSame((string) $issued->issued_at, (string) $replayedIssue->issued_at);

        $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.orders.cancel', $po->id), [
            'store_id' => $storeA->id,
            'cancelled_by' => $owner->id,
            'status' => 'issued',
        ])->assertRedirect();
        $cancelled = DB::table('enterprise_purchase_orders')->where('id', $po->id)->first();
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame((int) $maker->id, (int) $cancelled->cancelled_by);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertSame((int) $checker->id, (int) $cancelled->issued_by);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('event', 'enterprise.purchase_order.cancelled')
            ->where('auditable_id', $po->id)->count());

        $auditAfterCancel = DB::table('audit_logs')->count();
        $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.orders.cancel', $po->id), [
            'store_id' => $storeA->id,
        ])->assertRedirect();
        $afterRetry = DB::table('enterprise_purchase_orders')->where('id', $po->id)->first();
        $this->assertSame((string) $cancelled->cancelled_at, (string) $afterRetry->cancelled_at);
        $this->assertSame((int) $maker->id, (int) $afterRetry->cancelled_by);
        $this->assertSame($auditAfterCancel, DB::table('audit_logs')->count());

        $this->actingAs($checker)->post(route('admin.erp.procurement.orders.issue', $po->id), [
            'store_id' => $storeA->id,
        ])->assertSessionHasErrors('status');
        $this->assertDatabaseHas('enterprise_purchase_orders', [
            'id' => $po->id, 'status' => 'cancelled',
        ]);
        $this->assertSame($auditAfterCancel, DB::table('audit_logs')->count());
    }

    public function test_issued_po_http_partial_grn_scoped_post_replay_and_over_receipt_never_duplicate_stock_ledger(): void
    {
        $storeA = $this->wholesaleStore();
        $storeB = Store::query()->create([
            'store_type_id' => $storeA->store_type_id,
            'code' => 'P111-GRN-FOREIGN-STORE',
            'name' => 'P111 distinct receiving store',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($storeB);

        $owner = $this->actor('SUPER_ADMIN');
        $buyer = $this->actor('PROCUREMENT_BUYER');
        $checker = $this->actor('PROCUREMENT_MANAGER');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $owner,
        );

        $catalog = Catalog::query()->create([
            'store_id' => $storeA->id,
            'channel' => 'b2c',
            'code' => 'P111-GRN-CATALOG',
            'name' => 'P111 receiving catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
        ]);
        $unitId = (int) DB::table('units')->insertGetId([
            'store_id' => null,
            'scope' => 'global',
            'scope_key' => 'global',
            'code' => 'P111-GRN-EACH',
            'name' => 'Each',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $product = Product::query()->create([
            'catalog_id' => $catalog->id,
            'unit_id' => $unitId,
            'sku' => 'P111-GRN-SKU',
            'name' => 'P111 stocked product',
            'is_active' => true,
        ]);
        $warehouseA = Warehouse::query()->create([
            'store_id' => $storeA->id,
            'code' => 'P111-GRN-WH-A',
            'name' => 'P111 authorized warehouse',
            'is_active' => true,
        ]);
        $warehouseB = Warehouse::query()->create([
            'store_id' => $storeB->id,
            'code' => 'P111-GRN-WH-B',
            'name' => 'P111 foreign warehouse',
            'is_active' => true,
        ]);

        $supplier = app(SupplierDirectory::class)->create(
            $buyer,
            app(TenantContextResolver::class)->wholesale($buyer, $storeA->id),
            [
                'scope_type' => 'store',
                'code' => 'P111-GRN-SUP',
                'name_en' => 'P111 supplier is not stock legal title',
            ],
        );
        $this->actingAs($buyer)->post(route('admin.erp.procurement.requests.store'), [
            'store_id' => $storeA->id,
            'supplier_id' => $supplier->id,
            'currency_code' => 'KWD',
            'reason' => 'P111 real fractional receipt HTTP source',
            'item_code' => $product->sku,
            'description' => 'P111 fractional stock received',
            'quantity' => '1.500',
            'unit' => 'each',
            'estimated_unit_cost' => '1.250',
        ])->assertRedirect();
        $request = DB::table('enterprise_purchase_requests')
            ->where('reason', 'P111 real fractional receipt HTTP source')->first();
        $this->assertNotNull($request);
        $this->actingAs($buyer)->post(route('admin.erp.procurement.requests.submit', $request->id), [
            'store_id' => $storeA->id,
        ])->assertRedirect();
        $this->actingAs($checker)->post(route('admin.erp.procurement.requests.decision', $request->id), [
            'store_id' => $storeA->id,
            'decision' => 'approve',
        ])->assertRedirect();
        $this->actingAs($checker)->post(route('admin.erp.procurement.orders.create', $request->id), [
            'store_id' => $storeA->id,
        ])->assertRedirect();
        $po = DB::table('enterprise_purchase_orders')
            ->where('purchase_request_id', $request->id)->first();
        $this->assertNotNull($po);
        $this->assertSame('draft', $po->status);
        $poLine = DB::table('enterprise_purchase_order_lines')
            ->where('purchase_order_id', $po->id)->first();
        $this->assertNotNull($poLine);
        $this->assertSame('1.500', number_format((float) $poLine->quantity, 3, '.', ''));
        $this->assertSame('1.250', number_format((float) $poLine->unit_price, 3, '.', ''));
        $receiveUrl = route('admin.erp.procurement.receipts.store', $po->id);
        $firstPayload = [
            'store_id' => $storeA->id,
            'warehouse_id' => $warehouseA->id,
            'lines' => [[
                'purchase_order_line_id' => $poLine->id,
                'quantity' => '0.625',
                'unit' => 'case',
                'unit_price' => '-900.000',
                'discount_percent' => 100,
                'owner_key' => 'FOODEX',
            ]],
            'supplier_id' => 999999,
            'owner_key' => 'SUPPLIER:'.$supplier->id,
            'status' => 'posted',
            'posted_by' => $owner->id,
        ];
        $auditBeforeDraft = DB::table('audit_logs')->count();
        $this->actingAs($checker)->post($receiveUrl, $firstPayload)
            ->assertSessionHasErrors('purchase_order_id');
        $this->assertDatabaseCount('enterprise_goods_receipts', 0);
        $this->assertSame($auditBeforeDraft, DB::table('audit_logs')->count());

        $this->actingAs($checker)->post(route('admin.erp.procurement.orders.issue', $po->id), [
            'store_id' => $storeA->id,
        ])->assertRedirect();
        $this->assertDatabaseHas('enterprise_purchase_orders', [
            'id' => $po->id,
            'status' => 'issued',
            'issued_by' => $checker->id,
        ]);
        $auditBeforeInvalid = DB::table('audit_logs')->count();
        foreach (['ar', 'en'] as $locale) {
            $buyer->forceFill(['locale' => $locale])->save();
            $checker->forceFill(['locale' => $locale])->save();

            // Read permission or an HTML form does not grant GRN management.
            $this->actingAs($buyer->fresh())->post($receiveUrl, $firstPayload)
                ->assertForbidden();
            $this->actingAs($checker->fresh())->post($receiveUrl, [
                ...$firstPayload,
                'store_id' => $storeB->id,
            ])->assertNotFound();
            $this->actingAs($checker->fresh())->post($receiveUrl, [
                ...$firstPayload,
                'warehouse_id' => $warehouseB->id,
            ])->assertSessionHasErrors('lines');
            $this->actingAs($checker->fresh())->post($receiveUrl, [
                ...$firstPayload,
                'lines' => [[
                    'purchase_order_line_id' => $poLine->id,
                    'quantity' => '1.501',
                ]],
            ])->assertSessionHasErrors('lines');
        }
        $this->assertSame($auditBeforeInvalid, DB::table('audit_logs')->count());
        $this->assertDatabaseCount('enterprise_goods_receipts', 0);
        $this->assertSame(0, DB::table('stock_movements')
            ->where('reference_type', 'enterprise_goods_receipt_line')->count());

        // A draft receipt can be cancelled (the only receipt-side
        // rejection transition in this domain). It must not create stock;
        // a cancelled document can never be posted by forging the route.
        $this->actingAs($checker->fresh())->post($receiveUrl, [
            ...$firstPayload,
            'lines' => [[
                ...$firstPayload['lines'][0],
                'quantity' => '0.125',
            ]],
        ])->assertRedirect();
        $cancelledReceipt = DB::table('enterprise_goods_receipts')
            ->where('purchase_order_id', $po->id)->first();
        $this->assertNotNull($cancelledReceipt);
        $this->assertSame('draft', $cancelledReceipt->status);
        $cancelUrl = route('admin.erp.procurement.receipts.cancel', $cancelledReceipt->id);
        $this->actingAs($checker->fresh())->post($cancelUrl, [
            'store_id' => $storeA->id,
            'cancelled_by' => $owner->id,
        ])->assertRedirect();
        $cancelledRow = DB::table('enterprise_goods_receipts')
            ->where('id', $cancelledReceipt->id)->first();
        $this->assertSame('cancelled', $cancelledRow->status);
        $this->assertSame((int) $checker->id, (int) $cancelledRow->cancelled_by);
        $this->assertNotNull($cancelledRow->cancelled_at);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('event', 'enterprise.goods_receipt.cancelled')
            ->where('auditable_id', $cancelledReceipt->id)->count());
        $auditBeforeCancelRetry = DB::table('audit_logs')->count();
        $this->actingAs($checker->fresh())->post($cancelUrl, [
            'store_id' => $storeA->id,
        ])->assertRedirect();
        $this->assertSame($auditBeforeCancelRetry, DB::table('audit_logs')->count());
        $this->assertSame((string) $cancelledRow->cancelled_at,
            (string) DB::table('enterprise_goods_receipts')
                ->where('id', $cancelledReceipt->id)->value('cancelled_at'));
        $this->actingAs($checker->fresh())->post(route('admin.erp.procurement.receipts.post', $cancelledReceipt->id), [
            'store_id' => $storeA->id,
        ])->assertSessionHasErrors('status');
        $this->assertSame($auditBeforeCancelRetry, DB::table('audit_logs')->count());
        $this->assertSame(0, DB::table('stock_movements')
            ->where('reference_type', 'enterprise_goods_receipt_line')->count());

        $receipts = [];
        foreach (['0.625', '0.875'] as $quantity) {
            $this->actingAs($checker->fresh())->post($receiveUrl, [
                ...$firstPayload,
                'lines' => [[
                    ...$firstPayload['lines'][0],
                    'quantity' => $quantity,
                ]],
            ])->assertRedirect();
            $receipt = DB::table('enterprise_goods_receipts')
                ->where('purchase_order_id', $po->id)
                ->orderByDesc('id')->first();
            $this->assertNotNull($receipt);
            $this->assertSame('draft', $receipt->status);
            $this->assertSame((int) $supplier->id, (int) $receipt->supplier_id);
            $this->assertSame((int) $checker->id, (int) $receipt->created_by);
            $this->assertNull($receipt->posted_by);
            $this->assertSame($po->order_number, $receipt->source_order_number);
            $this->assertSame('KWD', $receipt->currency_code);
            $line = DB::table('enterprise_goods_receipt_lines')
                ->where('goods_receipt_id', $receipt->id)->first();
            $this->assertNotNull($line);
            $this->assertSame('each', $line->unit);
            $this->assertSame('1.250', number_format((float) $line->unit_price, 3, '.', ''));
            $this->assertSame($quantity, number_format((float) $line->received_quantity, 3, '.', ''));
            $this->assertSame((int) $warehouseA->id, (int) $line->warehouse_id);
            $this->assertSame((int) $poLine->id, (int) $line->purchase_order_line_id);
            $this->assertNull($line->stock_movement_id);

            $postUrl = route('admin.erp.procurement.receipts.post', $receipt->id);
            $auditBeforeDeniedPost = DB::table('audit_logs')->count();
            $this->actingAs($buyer->fresh())->post($postUrl, [
                'store_id' => $storeA->id,
                'posted_by' => $owner->id,
            ])->assertForbidden();
            $this->actingAs($checker->fresh())->post($postUrl, [
                'store_id' => $storeB->id,
            ])->assertNotFound();
            $this->assertSame($auditBeforeDeniedPost, DB::table('audit_logs')->count());

            $this->actingAs($checker->fresh())->post($postUrl, [
                'store_id' => $storeA->id,
                'posted_by' => $owner->id,
                'status' => 'cancelled',
                'owner_key' => 'FOODEX',
            ])->assertRedirect();
            $posted = DB::table('enterprise_goods_receipts')->where('id', $receipt->id)->first();
            $this->assertSame('posted', $posted->status);
            $this->assertSame((int) $checker->id, (int) $posted->posted_by);
            $this->assertNotNull($posted->posted_at);
            $postedLine = DB::table('enterprise_goods_receipt_lines')
                ->where('goods_receipt_id', $receipt->id)->first();
            $this->assertNotNull($postedLine->stock_movement_id);
            $this->assertNotNull($postedLine->inventory_id);
            $this->assertSame(1, DB::table('stock_movements')
                ->where('type', 'purchase_receipt')
                ->where('reference_type', 'enterprise_goods_receipt_line')
                ->where('reference_id', $postedLine->id)->count());
            $this->assertSame(1, DB::table('audit_logs')
                ->where('event', 'enterprise.goods_receipt.posted')
                ->where('auditable_id', $receipt->id)->count());

            $auditBeforeRetry = DB::table('audit_logs')->count();
            $this->actingAs($checker->fresh())->post($postUrl, [
                'store_id' => $storeA->id,
                'posted_by' => $buyer->id,
            ])->assertRedirect();
            $retried = DB::table('enterprise_goods_receipts')->where('id', $receipt->id)->first();
            $this->assertSame((int) $checker->id, (int) $retried->posted_by);
            $this->assertSame((string) $posted->posted_at, (string) $retried->posted_at);
            $this->assertSame($auditBeforeRetry, DB::table('audit_logs')->count());
            $this->assertSame(1, DB::table('stock_movements')
                ->where('type', 'purchase_receipt')
                ->where('reference_type', 'enterprise_goods_receipt_line')
                ->where('reference_id', $postedLine->id)->count());
            $this->actingAs($checker->fresh())->post(route('admin.erp.procurement.receipts.cancel', $receipt->id), [
                'store_id' => $storeA->id,
            ])->assertSessionHasErrors('status');

            $receipts[] = $receipt->id;
        }
        $inventory = DB::table('inventories')
            ->where('warehouse_id', $warehouseA->id)
            ->where('product_id', $product->id)->first();
        $this->assertNotNull($inventory);
        $this->assertSame('1.500', number_format((float) $inventory->quantity, 3, '.', ''));
        $this->assertSame('0.000', number_format((float) $inventory->reserved_quantity, 3, '.', ''));
        $this->assertSame(2, DB::table('stock_movements')
            ->where('inventory_id', $inventory->id)
            ->where('type', 'purchase_receipt')
            ->where('reference_type', 'enterprise_goods_receipt_line')
            ->count());
        $this->assertSame('1.500', number_format((float) DB::table('stock_movements')
            ->where('inventory_id', $inventory->id)
            ->where('type', 'purchase_receipt')
            ->where('reference_type', 'enterprise_goods_receipt_line')
            ->sum('quantity'), 3, '.', ''));
        $this->assertSame(2, DB::table('audit_logs')
            ->where('event', 'enterprise.goods_receipt.posted')
            ->whereIn('auditable_id', $receipts)->count());

        $auditBeforeOver = DB::table('audit_logs')->count();
        $this->actingAs($checker->fresh())->post($receiveUrl, [
            ...$firstPayload,
            'lines' => [[
                'purchase_order_line_id' => $poLine->id,
                'quantity' => '0.001',
            ]],
        ])->assertSessionHasErrors('lines');
        $this->assertSame(2, DB::table('enterprise_goods_receipts')
            ->where('purchase_order_id', $po->id)->where('status', 'posted')->count());
        $this->assertSame(1, DB::table('enterprise_goods_receipts')
            ->where('purchase_order_id', $po->id)->where('status', 'cancelled')->count());
        $this->assertSame('1.500', number_format((float) DB::table('inventories')
            ->where('id', $inventory->id)->value('quantity'), 3, '.', ''));
        $this->assertSame(2, DB::table('stock_movements')
            ->where('inventory_id', $inventory->id)
            ->where('type', 'purchase_receipt')->count());
        $this->assertSame($auditBeforeOver, DB::table('audit_logs')->count());
    }

    /**
     * @return array{Store, array<string, int>, Store, array<string, int>}
     */
    private function companyScopedStores(string $channel, string $prefix): array
    {
        $hierarchy = app(OrganizationHierarchyService::class);
        $primary = $this->storeInDefaultCompany($channel, $prefix.'_PRIMARY');
        $primaryScope = $hierarchy->scopeForStore($primary->id);
        $this->assertIsArray($primaryScope);

        $foreign = $this->storeInForeignCompany($primary, $channel, $prefix.'_FOREIGN');
        $foreignScope = $hierarchy->scopeForStore($foreign->id);
        $this->assertIsArray($foreignScope);
        $this->assertNotSame($primaryScope['company_id'], $foreignScope['company_id']);

        return [$primary, $primaryScope, $foreign, $foreignScope];
    }

    private function storeInDefaultCompany(string $channel, string $code): Store
    {
        $store = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', strtoupper($channel))->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    private function storeInBusinessUnit(
        string $channel,
        string $code,
        EnterpriseBusinessUnit $businessUnit,
    ): Store {
        $store = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', strtoupper($channel))->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->assignStore($store, $businessUnit);

        return $store;
    }

    private function storeInForeignCompany(Store $reference, string $channel, string $code): Store
    {
        $hierarchy = app(OrganizationHierarchyService::class);
        $referenceScope = $hierarchy->scopeForStore($reference->id);
        $this->assertIsArray($referenceScope);

        $store = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', strtoupper($channel))->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
        $company = EnterpriseCompany::query()->create([
            'organization_id' => $referenceScope['organization_id'],
            'code' => $code.'_COMPANY',
            'name' => $code.' company',
            'is_active' => true,
        ]);
        $legalEntity = EnterpriseLegalEntity::query()->create([
            'company_id' => $company->id,
            'code' => $code.'_ENTITY',
            'name' => $code.' legal entity',
            'is_active' => true,
        ]);
        $businessUnit = EnterpriseBusinessUnit::query()->create([
            'legal_entity_id' => $legalEntity->id,
            'code' => $code.'_UNIT',
            'name' => $code.' business unit',
            'is_active' => true,
        ]);
        $hierarchy->assignStore($store, $businessUnit);

        return $store;
    }

    private function enableAndCertifyModule(string $module, User $actor): void
    {
        $registry = app(ModuleRegistry::class);
        config()->set(
            'enterprise_module_readiness.certified_versions.'.$module,
            $registry->manifest($module)->version,
        );
        app(EnterpriseModuleAdministration::class)->perform($module, 'enable', $actor);
    }

    private function financeActor(): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->grantPlatformErpAccess($actor);
        $role = Role::query()->where('code', 'FINANCE')->where('scope', 'global')->firstOrFail();
        $role->permissions()->syncWithoutDetaching(
            Permission::query()->whereIn('code', ['finance.view', 'finance.manage'])->pluck('id'),
        );
        $actor->roles()->syncWithoutDetaching([$role->id]);

        return $actor->fresh();
    }

    private function cashboxFor(Store $store, string $code): EnterpriseCashbox
    {
        $scope = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertIsArray($scope);

        return EnterpriseCashbox::query()->create([
            'company_id' => $scope['company_id'],
            'store_id' => $store->id,
            'code' => $code,
            'name' => $code.' cashbox',
            'currency_code' => 'KWD',
            'operator_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'approval_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'is_active' => true,
        ]);
    }

    /** @return array{warehouse_id: int, product_id: int, inventory_id: int} */
    private function wmsStockFor(Store $store, int $unitId, string $suffix): array
    {
        $catalog = Catalog::query()->create([
            'store_id' => $store->id,
            'channel' => 'b2c',
            'code' => strtolower($suffix),
            'name' => $suffix.' catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
        ]);
        $product = Product::query()->create([
            'catalog_id' => $catalog->id,
            'unit_id' => $unitId,
            'sku' => $suffix.'-SKU',
            'name' => $suffix.' product',
            'is_active' => true,
        ]);
        $warehouse = Warehouse::query()->create([
            'store_id' => $store->id,
            'code' => $suffix.'-WH',
            'name' => $suffix.' warehouse',
            'is_active' => true,
        ]);
        $inventoryId = (int) DB::table('inventories')->insertGetId([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 10,
            'reserved_quantity' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'warehouse_id' => (int) $warehouse->id,
            'product_id' => (int) $product->id,
            'inventory_id' => $inventoryId,
        ];
    }

    private function wholesaleStore(): Store
    {
        $store = Store::query()->findOrFail(app(WholesalePrincipal::class)->storeId());
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

        return $user->fresh();
    }
}
