<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\BuiltInJobRoleCatalog;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * V2 T122R10: Procurement Approver is an independent checker/PO issuer.
 * Approval is an actual server-side permission, not a sidebar or a role name.
 */
final class V2ProcurementApproverAuthorityTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const GRANTS = [
        'procurement.suppliers.view',
        'procurement.purchase_requests.view',
        'procurement.purchase_requests.approve',
        'procurement.purchase_orders.view',
        'procurement.purchase_orders.issue',
        'procurement.purchase_invoices.view',
        'procurement.supplier_statements.view',
        'reports.view',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
        config()->set(
            'enterprise_module_readiness.certified_versions.procurement',
            app(ModuleRegistry::class)->manifest('procurement')->version,
        );
    }

    public function test_approver_role_has_exact_bilingual_checker_permissions_and_procurement_module_gate(): void
    {
        $catalog = app(BuiltInJobRoleCatalog::class);
        $definition = $catalog->definition('PROCUREMENT_APPROVER');

        $this->assertIsArray($definition);
        $this->assertSame('procurement', $definition['domain']);
        $this->assertSame('global', $definition['scope']);
        $this->assertSame('tenant', $definition['business_scope']);
        $this->assertSame(['procurement'], $definition['required_modules']);
        $this->assertEqualsCanonicalizing(self::GRANTS, $definition['permissions']);

        $role = Role::query()->where('code', 'PROCUREMENT_APPROVER')->firstOrFail();
        $this->assertTrue((bool) $role->is_system);
        $this->assertEqualsCanonicalizing(self::GRANTS, $role->permissions()->pluck('code')->all());

        foreach (['ar' => 'معتمد المشتريات', 'en' => 'Procurement Approver'] as $locale => $name) {
            app()->setLocale($locale);
            $presentation = $catalog->presentation('PROCUREMENT_APPROVER');

            $this->assertSame($name, $presentation['name']);
            $this->assertNotEmpty($presentation['description']);
            $this->assertNotEmpty($presentation['access_boundaries']);
        }
    }

    public function test_approver_fails_closed_when_module_is_disabled_and_has_authorized_ar_en_workbench_when_enabled(): void
    {
        $store = $this->wholesaleStore();
        $checker = $this->userWithRole('PROCUREMENT_APPROVER');
        $catalog = app(BuiltInJobRoleCatalog::class);

        $this->assertFalse($catalog->isAvailable('PROCUREMENT_APPROVER'));
        $this->assertFalse($checker->hasPermission('procurement.purchase_requests.approve'));
        $this->actingAs($checker)
            ->get(route('admin.erp.procurement.index', ['store_id' => $store->id], false))
            ->assertForbidden();

        $this->enableProcurement();
        $this->assertTrue($catalog->isAvailable('PROCUREMENT_APPROVER'));
        $this->assertTrue($checker->fresh()->hasPermission('procurement.purchase_requests.approve', $store->id));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $checker->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);
            app()->forgetInstance(AdminNavigation::class);

            $items = collect(app(AdminNavigation::class)->groupsFor($checker->fresh()))
                ->flatMap(static fn (array $group): array => $group['children'])
                ->keyBy('key');

            $this->assertTrue($items->has('erp_procurement'), $locale.' must discover the real procurement destination');
            $this->assertSame('admin.erp.procurement.index', $items['erp_procurement']['route']);
            $this->assertTrue(Route::has($items['erp_procurement']['route']));
            $this->assertFalse($items->has('erp_finance'));
            $this->assertFalse($items->has('treasury_master'));
            $this->assertFalse($items->has('sidebar_role_access'));

            $this->actingAs($checker->fresh())
                ->get(route('admin.erp.procurement.index', [
                    'store_id' => $store->id,
                    'tab' => 'requests',
                ], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            $this->actingAs($checker->fresh())
                ->get(route('admin.erp.procurement.index', [
                    'store_id' => $store->id,
                    'tab' => 'receipts',
                ], false))
                ->assertForbidden();
        }
    }

    public function test_distinct_buyer_and_checker_can_approve_real_request_and_issue_order_without_maker_rights(): void
    {
        $store = $this->wholesaleStore();
        $this->enableProcurement();

        $buyer = $this->userWithRole('PROCUREMENT_BUYER');
        $checker = $this->userWithRole('PROCUREMENT_APPROVER');
        $this->assertNotSame($buyer->id, $checker->id);

        foreach ([
            'procurement.suppliers.manage',
            'procurement.purchase_requests.manage',
            'procurement.purchase_orders.manage',
            'procurement.goods_receipts.manage',
            'procurement.goods_receipts.post',
            'procurement.purchase_invoices.handoff',
            'procurement.purchase_returns.post',
            'finance.approve',
            'finance.post',
            'orders.dispatch',
            'roles.manage',
        ] as $forbidden) {
            $this->assertFalse($checker->hasPermission($forbidden, $store->id), $forbidden);
        }

        $supplierPayload = [
            'store_id' => $store->id,
            'scope_type' => 'store',
            'code' => 'V2-P2690-SUP',
            'name_en' => 'Checker boundary supplier',
            'name_ar' => 'مورد فصل الاعتماد',
            'payment_terms_days' => 30,
        ];
        $this->actingAs($checker)
            ->post(route('admin.erp.procurement.suppliers.store'), $supplierPayload)
            ->assertForbidden();
        $this->assertDatabaseMissing('enterprise_suppliers', ['code' => 'V2-P2690-SUP']);

        $this->actingAs($buyer)
            ->post(route('admin.erp.procurement.suppliers.store'), $supplierPayload)
            ->assertRedirect();
        $supplierId = (int) DB::table('enterprise_suppliers')->where('code', 'V2-P2690-SUP')->value('id');
        $this->assertGreaterThan(0, $supplierId);

        $requestPayload = [
            'store_id' => $store->id,
            'supplier_id' => $supplierId,
            'currency_code' => 'KWD',
            'needed_by' => now()->addDays(5)->toDateString(),
            'reason' => 'V2 2690 independent checker proof',
            'item_code' => 'V2-P2690-LINE',
            'description' => 'Independent purchase approval line',
            'quantity' => 2,
            'unit' => 'case',
            'estimated_unit_cost' => 8.250,
        ];

        $this->actingAs($checker)
            ->post(route('admin.erp.procurement.requests.store'), $requestPayload)
            ->assertForbidden();

        $this->actingAs($buyer)
            ->post(route('admin.erp.procurement.requests.store'), $requestPayload)
            ->assertRedirect();
        $requestId = (int) DB::table('enterprise_purchase_requests')
            ->where('reason', 'V2 2690 independent checker proof')->value('id');
        $this->assertGreaterThan(0, $requestId);
        $this->actingAs($buyer)
            ->post(route('admin.erp.procurement.requests.submit', $requestId), [
                'store_id' => $store->id,
            ])->assertRedirect();

        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $requestId,
            'status' => 'submitted',
            'created_by' => $buyer->id,
            'submitted_by' => $buyer->id,
        ]);

        $this->actingAs($buyer)
            ->post(route('admin.erp.procurement.requests.decision', $requestId), [
                'store_id' => $store->id,
                'decision' => 'approve',
            ])->assertForbidden();

        $this->actingAs($checker)
            ->post(route('admin.erp.procurement.requests.decision', $requestId), [
                'store_id' => $store->id,
                'decision' => 'approve',
                'note' => 'Independent checker approved',
            ])->assertRedirect();

        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $requestId,
            'status' => 'approved',
            'decided_by' => $checker->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.purchase_request.approved',
            'auditable_id' => $requestId,
        ]);

        // Only the maker can create the draft order. The checker can issue it.
        $this->actingAs($checker)
            ->post(route('admin.erp.procurement.orders.create', $requestId), [
                'store_id' => $store->id,
            ])->assertForbidden();

        $this->actingAs($buyer)
            ->post(route('admin.erp.procurement.orders.create', $requestId), [
                'store_id' => $store->id,
            ])->assertRedirect();

        $orderId = (int) DB::table('enterprise_purchase_orders')
            ->where('purchase_request_id', $requestId)->value('id');
        $this->assertGreaterThan(0, $orderId);
        $this->assertDatabaseHas('enterprise_purchase_orders', [
            'id' => $orderId,
            'status' => 'draft',
        ]);

        $this->actingAs($buyer)
            ->post(route('admin.erp.procurement.orders.issue', $orderId), [
                'store_id' => $store->id,
            ])->assertForbidden();

        $this->actingAs($checker)
            ->post(route('admin.erp.procurement.orders.issue', $orderId), [
                'store_id' => $store->id,
            ])->assertRedirect();

        $this->assertDatabaseHas('enterprise_purchase_orders', [
            'id' => $orderId,
            'status' => 'issued',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.purchase_order.issued',
            'auditable_id' => $orderId,
        ]);
    }

    public function test_checker_is_denied_retail_scope_security_and_user_override_revocation(): void
    {
        $wholesale = $this->wholesaleStore();
        $this->enableProcurement();
        $checker = $this->userWithRole('PROCUREMENT_APPROVER');

        $retail = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'V2-P2690-RETAIL',
            'name' => 'Unrelated Retail',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($retail);

        $this->assertFalse($checker->hasPermission('procurement.purchase_requests.approve', $retail->id));
        $this->actingAs($checker)
            ->get(route('admin.erp.procurement.index', [
                'store_id' => $retail->id,
                'tab' => 'requests',
            ], false))
            ->assertForbidden();
        $this->actingAs($checker)
            ->get(route('admin.security.index', [], false))
            ->assertForbidden();

        $permission = Permission::query()->where('code', 'procurement.purchase_requests.approve')->firstOrFail();
        DB::table('user_permission_overrides')->insert([
            'user_id' => $checker->id,
            'permission_id' => $permission->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $checker->forceFill(['has_permission_overrides' => true])->save();

        $this->assertFalse($checker->fresh()->hasPermission('procurement.purchase_requests.approve', $wholesale->id));
        $this->actingAs($checker->fresh())
            ->post(route('admin.erp.procurement.requests.decision', 9999999), [
                'store_id' => $wholesale->id,
                'decision' => 'approve',
            ])->assertForbidden();

        $inactive = $this->userWithRole('PROCUREMENT_APPROVER');
        $inactive->forceFill(['is_active' => false])->save();
        $this->actingAs($inactive->fresh())
            ->get(route('admin.erp.procurement.index', [
                'store_id' => $wholesale->id,
                'tab' => 'requests',
            ], false))
            ->assertForbidden();
    }

    private function userWithRole(string $code): User
    {
        $user = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', $code)->firstOrFail());

        return $user->fresh();
    }

    private function wholesaleStore(): Store
    {
        $store = Store::query()->findOrFail(app(WholesalePrincipal::class)->storeId());
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    private function enableProcurement(): void
    {
        $lifecycle = app(ModuleLifecycleManager::class);
        $lifecycle->transition('procurement', ModuleState::ENABLING);
        $lifecycle->transition('procurement', ModuleState::ENABLED);

        app(ModuleEntitlementManager::class)->set(
            'procurement',
            EntitlementScope::platform(),
            true,
        );
    }
}
