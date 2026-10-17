<?php

namespace Tests\Feature;

use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class EnterpriseProcurementWorkbenchTest extends TestCase
{
    use RefreshDatabase;

    public function test_workbench_fails_closed_while_procurement_module_is_disabled(): void
    {
        [$user, $store] = $this->operator('ERP-UI-DISABLED');

        $this->actingAs($user)
            ->get(route('admin.erp.procurement.index', ['store_id' => $store->id]))
            ->assertNotFound();
    }

    public function test_authorized_operator_can_run_request_to_issued_order_from_workbench(): void
    {
        $this->enableProcurement();
        [$user, $store] = $this->operator('ERP-UI-FLOW');

        $this->actingAs($user)
            ->get(route('admin.erp.procurement.index', ['store_id' => $store->id, 'tab' => 'suppliers']))
            ->assertOk()
            ->assertSee('مركز عمل المشتريات')
            ->assertSee('طلبات الشراء')
            ->assertSee('أوامر الشراء');

        $this->actingAs($user)->post(route('admin.erp.procurement.suppliers.store'), [
            'store_id' => $store->id,
            'scope_type' => 'store',
            'code' => 'UI-SUP-1',
            'name_en' => 'UI Supplier',
            'name_ar' => 'مورد الواجهة',
            'payment_terms_days' => 30,
        ])->assertRedirect();

        $supplierId = (int) DB::table('enterprise_suppliers')->where('code', 'UI-SUP-1')->value('id');
        $this->assertGreaterThan(0, $supplierId);

        $this->actingAs($user)->post(route('admin.erp.procurement.requests.store'), [
            'store_id' => $store->id,
            'supplier_id' => $supplierId,
            'currency_code' => 'KWD',
            'needed_by' => now()->addDays(5)->toDateString(),
            'reason' => 'Workbench lifecycle proof',
            'item_code' => 'UI-SKU-1',
            'description' => 'Workbench line',
            'quantity' => 3,
            'unit' => 'case',
            'estimated_unit_cost' => 5.250,
        ])->assertRedirect();

        $requestId = (int) DB::table('enterprise_purchase_requests')->where('reason', 'Workbench lifecycle proof')->value('id');
        $this->assertGreaterThan(0, $requestId);

        $this->actingAs($user)->post(route('admin.erp.procurement.requests.submit', $requestId), [
            'store_id' => $store->id,
        ])->assertRedirect();
        $this->assertDatabaseHas('enterprise_purchase_requests', ['id' => $requestId, 'status' => 'submitted']);

        $this->actingAs($user)->post(route('admin.erp.procurement.requests.decision', $requestId), [
            'store_id' => $store->id,
            'decision' => 'approve',
            'note' => 'UI approval',
        ])->assertRedirect();
        $this->assertDatabaseHas('enterprise_purchase_requests', ['id' => $requestId, 'status' => 'approved']);

        $this->actingAs($user)->post(route('admin.erp.procurement.orders.create', $requestId), [
            'store_id' => $store->id,
        ])->assertRedirect();

        $orderId = (int) DB::table('enterprise_purchase_orders')->where('purchase_request_id', $requestId)->value('id');
        $this->assertGreaterThan(0, $orderId);

        $this->actingAs($user)->post(route('admin.erp.procurement.orders.issue', $orderId), [
            'store_id' => $store->id,
        ])->assertRedirect();
        $this->assertDatabaseHas('enterprise_purchase_orders', ['id' => $orderId, 'status' => 'issued']);

        $this->actingAs($user)
            ->get(route('admin.erp.procurement.index', ['store_id' => $store->id, 'tab' => 'orders']))
            ->assertOk()
            ->assertSee('UI-SUP-1')
            ->assertSee('مُصدر');
    }

    public function test_completed_procurement_tabs_render_inside_one_canonical_workbench(): void
    {
        $this->enableProcurement();
        [$user, $store] = $this->operator('ERP-UI-COMPLETE');

        foreach ([
            'overview' => 'نظرة عامة على المشتريات',
            'rfqs' => 'طلبات العروض والمناقصات',
            'contracts' => 'العقود',
            'matches' => 'المطابقة الثلاثية',
            'reports' => 'تقارير المشتريات',
        ] as $tab => $expected) {
            $this->actingAs($user)
                ->get(route('admin.erp.procurement.index', [
                    'store_id' => $store->id,
                    'tab' => $tab,
                ]))
                ->assertOk()
                ->assertSee($expected);
        }
    }

    public function test_store_selector_rejects_cross_store_scope(): void
    {
        $this->enableProcurement();
        [$user, $store] = $this->operator('ERP-UI-A');
        [, $otherStore] = $this->operator('ERP-UI-B');

        $this->actingAs($user)
            ->get(route('admin.erp.procurement.index', [
                'store_id' => $otherStore->id,
                'tab' => 'suppliers',
            ]))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('admin.erp.procurement.index', [
                'store_id' => $store->id,
                'tab' => 'suppliers',
            ]))
            ->assertOk();
    }

    public function test_full_procurement_workbench_route_surface_is_registered(): void
    {
        foreach ([
            'admin.erp.procurement.index',
            'admin.erp.procurement.suppliers.store',
            'admin.erp.procurement.requests.store',
            'admin.erp.procurement.requests.submit',
            'admin.erp.procurement.requests.decision',
            'admin.erp.procurement.rfqs.store',
            'admin.erp.procurement.rfqs.publish',
            'admin.erp.procurement.rfqs.cancel',
            'admin.erp.procurement.rfqs.award',
            'admin.erp.procurement.rfqs.documents.download',
            'admin.erp.procurement.orders.create',
            'admin.erp.procurement.orders.issue',
            'admin.erp.procurement.orders.contract',
            'admin.erp.procurement.contracts.store',
            'admin.erp.procurement.contracts.activate',
            'admin.erp.procurement.contracts.terminate',
            'admin.erp.procurement.contracts.documents.store',
            'admin.erp.procurement.contracts.documents.download',
            'admin.erp.procurement.receipts.store',
            'admin.erp.procurement.receipts.post',
            'admin.erp.procurement.invoices.store',
            'admin.erp.procurement.invoices.handoff',
            'admin.erp.procurement.matches.review',
            'admin.erp.procurement.reports.export',
            'supplier.rfq.show',
            'supplier.rfq.submit',
            'admin.erp.procurement.returns.store',
            'admin.erp.procurement.returns.post',
        ] as $name) {
            $this->assertTrue(Route::has($name), 'Missing procurement UI route: '.$name);
        }
    }

    private function enableProcurement(): void
    {
        $lifecycle = app(ModuleLifecycleManager::class);
        $lifecycle->transition('procurement', ModuleState::ENABLING);
        $lifecycle->transition('procurement', ModuleState::ENABLED);
    }

    /** @return array{User,Store} */
    private function operator(string $storeCode): array
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        if ($typeId < 1) {
            $typeId = (int) DB::table('store_types')->insertGetId([
                'code' => 'B2C',
                'name' => 'Retail',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $store = Store::query()->create([
            'store_type_id' => $typeId,
            'code' => $storeCode,
            'name' => $storeCode,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        $role = Role::query()->firstOrCreate(
            ['code' => 'B2C_STORE_ADMIN'],
            [
                'name' => 'B2C Store Admin',
                'scope' => 'store',
                'is_active' => true,
                'is_system' => true,
            ],
        );

        foreach ($this->permissions() as $code) {
            $permission = Permission::query()->firstOrCreate(['code' => $code], ['name' => $code]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user = User::factory()->create([
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $this->grantPlatformErpAccess($user);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$user, $store];
    }

    /** @return list<string> */
    private function permissions(): array
    {
        return [
            'procurement.suppliers.view',
            'procurement.suppliers.manage',
            'procurement.purchase_requests.view',
            'procurement.purchase_requests.manage',
            'procurement.purchase_requests.approve',
            'procurement.purchase_orders.view',
            'procurement.purchase_orders.manage',
            'procurement.purchase_orders.issue',
            'procurement.goods_receipts.view',
            'procurement.goods_receipts.manage',
            'procurement.goods_receipts.post',
            'procurement.purchase_invoices.view',
            'procurement.purchase_invoices.manage',
            'procurement.purchase_invoices.handoff',
            'procurement.purchase_returns.view',
            'procurement.purchase_returns.manage',
            'procurement.purchase_returns.post',
            'procurement.supplier_statements.view',
            'reports.view',
            'reports.export',
        ];
    }
}
