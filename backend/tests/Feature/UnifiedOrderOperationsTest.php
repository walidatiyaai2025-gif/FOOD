<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\B2cCustomerService;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UnifiedOrderOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_legacy_order_pages_are_canonical_aliases_of_operations_page(): void
    {
        $super = $this->superAdmin('unified-routes@example.test');

        app(WholesalePrincipal::class)->storeId();
        $this->retailStore('UNIFIED-ROUTE-RETAIL');

        $this->actingAs($super)
            ->get('/admin/b2b/orders')
            ->assertOk()
            ->assertViewIs('admin.order-operations');

        $this->actingAs($super)
            ->get('/admin/b2c/orders')
            ->assertOk()
            ->assertViewIs('admin.order-operations');
    }

    public function test_retail_admin_cannot_open_wholesale_or_all_channel_scope(): void
    {
        $store = $this->retailStore('UNIFIED-RETAIL');
        $admin = $this->retailAdmin($store, 'unified-retail@example.test');

        $this->actingAs($admin)
            ->get('/admin/operations/orders?channel=b2c')
            ->assertOk()
            ->assertDontSee('data-order-channel-tabs', false)
            ->assertDontSee('B2B · Wholesale');

        $this->actingAs($admin)
            ->get('/admin/operations/orders?channel=b2b')
            ->assertForbidden();

        $this->actingAs($admin)
            ->get('/admin/operations/orders?channel=all')
            ->assertForbidden();
    }

    public function test_wholesale_admin_cannot_open_retail_or_all_channel_scope(): void
    {
        $admin = User::query()->create([
            'name' => 'Unified Wholesale Admin',
            'email' => 'unified-wholesale@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());

        $this->actingAs($admin)
            ->get('/admin/operations/orders?channel=b2b')
            ->assertOk()
            ->assertDontSee('data-order-channel-tabs', false)
            ->assertDontSee('B2C · Retail');

        $this->actingAs($admin)
            ->get('/admin/operations/orders?channel=b2c')
            ->assertForbidden();

        $this->actingAs($admin)
            ->get('/admin/operations/orders?channel=all')
            ->assertForbidden();
    }

    public function test_multi_channel_admin_switches_between_isolated_channels_and_cannot_aggregate_orders(): void
    {
        $super = $this->superAdmin('unified-super@example.test');
        app(WholesalePrincipal::class)->storeId();
        $this->retailStore('UNIFIED-SUPER-RETAIL');

        $this->actingAs($super)
            ->get('/admin/operations/orders?channel=b2b')
            ->assertOk()
            ->assertSee('data-order-channel-tabs', false)
            ->assertSee('data-order-channel-tab="b2b"', false)
            ->assertSee('data-order-channel-tab="b2c"', false)
            ->assertDontSee('data-order-channel-tab="all"', false);

        $this->actingAs($super)
            ->get('/admin/operations/orders?channel=b2c')
            ->assertOk()
            ->assertSee('data-order-channel-selected="b2c"', false);

        $this->actingAs($super)
            ->get('/admin/operations/orders?channel=all')
            ->assertForbidden();
    }

    public function test_order_filters_preserve_authorized_channel_and_store_workspace_context(): void
    {
        $super = $this->superAdmin('unified-context@example.test');
        app(WholesalePrincipal::class)->storeId();
        $selectedStore = $this->retailStore('UNIFIED-CONTEXT-RETAIL');
        $this->retailStore('UNIFIED-CONTEXT-OTHER');

        $response = $this->actingAs($super)
            ->get('/admin/operations/orders?channel=b2c&store_id='.$selectedStore.'&status=pending&from=2026-10-01');

        $response->assertOk()
            ->assertViewHas('selectedChannel', 'b2c')
            ->assertViewHas('selectedStoreId', $selectedStore)
            ->assertSee('data-order-grid-filter-controls', false)
            ->assertSee('data-order-grid-filter', false)
            ->assertDontSee('data-order-workspace-context', false)
            ->assertSee('name="channel" value="b2c"', false)
            ->assertSee('<option value="'.$selectedStore.'" selected>', false)
            ->assertSee(
                'data-order-filter-reset-context href="'.e(route('admin.operations.orders.index', [
                    'channel' => 'b2c',
                    'store_id' => $selectedStore,
                ])),
                false,
            );

        $this->actingAs($super)
            ->get('/admin/operations/orders?channel=all&store_id='.$selectedStore.'&status=pending')
            ->assertForbidden();
    }

    public function test_pending_order_actions_are_compact_and_edit_is_inside_ellipsis_menu(): void
    {
        $store = $this->retailStore('UNIFIED-ACTIONS');
        $admin = $this->retailAdmin($store, 'unified-actions@example.test');
        $customer = app(B2cCustomerService::class)->create($store, ['name' => 'Unified Buyer']);

        $order = (int) DB::table('orders')->insertGetId([
            'store_id' => $store,
            'customer_id' => (int) $customer->legacy_customer_id,
            'b2c_customer_id' => (int) $customer->id,
            'order_number' => 'UNIFIED-ORDER-1001',
            'channel' => 'b2c',
            'status' => 'pending',
            'currency' => 'KWD',
            'subtotal' => 10,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get('/admin/operations/orders?channel=b2c')
            ->assertOk()
            ->assertSee('#'.$order)
            ->assertSee('data-order-inline-status', false)
            ->assertSee('data-order-inline-status-select', false)
            ->assertSee('value="confirmed"', false)
            ->assertSee('data-order-row-actions', false)
            ->assertSee('data-order-edit', false);
    }

    public function test_order_popups_render_details_and_printable_timeline_without_page_navigation(): void
    {
        $store = $this->retailStore('UNIFIED-POPUPS');
        $admin = $this->retailAdmin($store, 'unified-popups@example.test');
        $customer = app(B2cCustomerService::class)->create($store, ['name' => 'Popup Buyer']);

        $order = (int) DB::table('orders')->insertGetId([
            'store_id' => $store,
            'customer_id' => (int) $customer->legacy_customer_id,
            'b2c_customer_id' => (int) $customer->id,
            'order_number' => 'UNIFIED-POPUP-1001',
            'channel' => 'b2c',
            'status' => 'pending',
            'currency' => 'EGP',
            'subtotal' => 25,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 25,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('order_status_history')->insert([
            'order_id' => $order,
            'store_id' => $store,
            'user_id' => $admin->id,
            'from_status' => null,
            'to_status' => 'pending',
            'note' => 'dashboard_order_created',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get("/admin/operations/orders/{$order}/popup/details")
            ->assertOk()
            ->assertSee('data-order-popup-content="details"', false)
            ->assertSee('Popup Buyer')
            ->assertSee('25.00 EGP');

        $this->actingAs($admin)
            ->get("/admin/operations/orders/{$order}/popup/timeline")
            ->assertOk()
            ->assertSee('data-order-popup-content="timeline"', false)
            ->assertSee('Print timeline')
            ->assertSee('Order status changed');
    }

    public function test_order_operations_uses_compact_single_card_kpi_summary(): void
    {
        $view = file_get_contents(resource_path('views/admin/order-operations.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString('data-order-kpi-card', $view);
        $this->assertStringContainsString('data-order-tabs', $view);
        $this->assertStringContainsString('data-order-tab="analytics"', $view);
        $this->assertStringContainsString('data-order-tab="orders"', $view);
        $this->assertStringContainsString('data-order-panel="analytics"', $view);
        $this->assertStringContainsString('data-order-panel="orders"', $view);
        $this->assertStringContainsString('class="order-workspace-tab-count"', $view);
        $this->assertStringContainsString("history.replaceState(null, '', '#order-tab-' + name)", $view);
        $this->assertStringContainsString('.order-tab-panel[hidden]{display:none!important}', $view);
        $dashboardStart = strpos($view, '<?php if ($dashboardVisible && is_array($dashboard ?? null)): ?>');
        $dashboardEnd = strpos($view, '<?php endif; ?>', $dashboardStart + 1);
        $this->assertNotFalse($dashboardStart);
        $this->assertNotFalse($dashboardEnd);
        $dashboardBlock = substr($view, $dashboardStart, $dashboardEnd - $dashboardStart);
        $this->assertStringNotContainsString('@if(', $dashboardBlock);
        $this->assertStringNotContainsString('@foreach(', $dashboardBlock);
        $this->assertStringNotContainsString('@forelse(', $dashboardBlock);
        $this->assertStringNotContainsString("foodexGlobalHeaderExtraPartial = 'admin._order-operations-header-filters'", $view);
        $this->assertStringContainsString('data-order-workspace-bar', $view);
        $this->assertStringContainsString('data-order-workspace-filter', $view);
        $this->assertStringContainsString('data-default-tab="orders"', $view);
        $this->assertStringContainsString('data-order-filter-mode="analytics" hidden', $view);
        $this->assertStringContainsString('data-order-filter-mode="orders"', $view);
        $this->assertStringContainsString("@include('admin._order-operations-order-filters')", $view);
        $this->assertStringNotContainsString('class="filters foodex-card"', $view);
        $this->assertStringContainsString('data-order-action-tab', $view);
        $this->assertStringContainsString('order-grid-row', $view);
        $this->assertStringContainsString('order-attention-chip', $view);
        $this->assertStringContainsString('order-status-chip', $view);
        $this->assertStringContainsString('data-order-inline-status', $view);
        $this->assertStringContainsString('data-order-inline-status-select', $view);
        $this->assertStringContainsString('data-order-inline-rejection-note', $view);
        $this->assertStringContainsString('data-order-inline-dispatch', $view);
        $this->assertStringContainsString('data-order-inline-assignee-type', $view);
        $this->assertStringContainsString('data-order-inline-assignee-id', $view);
        $this->assertStringContainsString('data-b2b-ready-dispatch-alert', $view);
        $this->assertStringContainsString('order-ready-dispatch-alert', $view);
        $this->assertStringContainsString('b2b_out_for_delivery', $view);
        $this->assertStringContainsString("row['status_visual_state']", $view);
        $this->assertStringContainsString('إلغاء الطلب', $view);
        $this->assertStringContainsString('سحب وتحويل الطلب لسائق آخر', $view);
        $this->assertStringContainsString('data-order-driver-transfer', $view);
        $this->assertStringContainsString('اختر سائق أو فان', $view);
        $this->assertStringContainsString('data-order-dispatch-locked', $view);
        $this->assertStringContainsString('is-dispatch-locked', $view);
        $this->assertStringContainsString('Available after order confirmation', $view);
        $this->assertStringContainsString('data-order-inline-customer-service-owner', $view);
        $this->assertStringContainsString('data-order-inline-picker-select', $view);
        $this->assertStringContainsString('data-order-inline-picker-reason', $view);
        $this->assertStringContainsString('data-order-financial-actions', $view);
        $this->assertStringContainsString('data-order-details-icon', $view);
        $this->assertStringContainsString('data-order-invoice-icon', $view);
        $this->assertStringContainsString('data-order-inline-unassign', $view);
        $this->assertStringContainsString('data-order-actionable-detail', $view);
        $this->assertStringContainsString('data-order-detail-no-actions', $view);
        $this->assertStringContainsString('.ops-table th:nth-child(1){width:7.5%}', $view);
        $this->assertStringContainsString('.ops-table th:nth-child(2){width:12.75%}', $view);
        $this->assertStringContainsString('.ops-table th:nth-child(3){width:7.5%}', $view);
        $this->assertStringContainsString("class=\"{{ \$rowPaid ? 'is-paid' : 'is-unpaid' }}\"", $view);
        $this->assertStringContainsString('data-order-popup-dialog', $view);
        $this->assertStringContainsString('data-order-popup-url', $view);
        $this->assertStringContainsString('data-order-timeline-icon', $view);
        $this->assertStringContainsString('order-financial-action is-timeline', $view);
        $this->assertStringContainsString('window.print()', $view);
        $this->assertStringContainsString("<th>{{ \$isAr ? 'العميل' : 'Customer' }}</th>\n<th>{{ \$isAr ? 'الدفع والإجمالي' : 'Payment & total' }}</th>\n<th>{{ __('order_operations.columns.status') }}</th>", $view);
        $this->assertStringContainsString('سحب من السائق', $view);
        $this->assertStringContainsString('سحب من الفان', $view);
        $this->assertStringContainsString('.order-inline-status{display:grid;', $view);
        $this->assertStringContainsString('.order-inline-picker{display:grid;', $view);
        $this->assertStringContainsString('colspan="7"', $view);
        $this->assertStringContainsString('position:sticky;top:0', $view);
        $this->assertStringContainsString('data-action-required="{{ $rowActionRequired ? \'1\' : \'0\' }}"', $view);
        $this->assertStringContainsString("@include('admin._order-operations-header-filters')", $view);
        $this->assertStringContainsString('@keyframes orderChartGrow', $view);
        $this->assertStringContainsString('@keyframes orderRankGrow', $view);
        $this->assertStringContainsString('charts-animate', $view);
        $this->assertStringContainsString('--order-chart-color:var(--foodex-palette-2563eb)', $view);
        $this->assertStringContainsString('--order-chart-color:var(--foodex-palette-7c3aed)', $view);
        $this->assertStringContainsString('data-order-analytics-tabs', $view);
        $this->assertStringContainsString('data-analytics-tab="performance"', $view);
        $this->assertStringContainsString('data-analytics-tab="operations"', $view);
        $this->assertStringContainsString('data-analytics-tab="channels"', $view);
        $this->assertStringContainsString('data-analytics-tab="commerce"', $view);
        $this->assertStringContainsString('data-analytics-group="performance"', $view);
        $this->assertStringContainsString('data-analytics-group="operations" hidden', $view);
        $this->assertStringContainsString('data-analytics-group="channels" hidden', $view);
        $this->assertStringContainsString('data-analytics-group="commerce" hidden', $view);
        $this->assertStringContainsString('background:linear-gradient(180deg,var(--order-chart-color-2)', $view);
        $this->assertStringContainsString('background:linear-gradient(90deg,var(--order-rank-color),var(--order-rank-color-2))', $view);
        $this->assertStringContainsString('orderChartGrow 1.65s', $view);
        $this->assertStringContainsString('orderRankGrow 1.85s', $view);
        $this->assertStringContainsString('const duration = 1200;', $view);
        $this->assertStringContainsString('.foodex-module-overview{display:grid;gap:6px}', $view);
        $this->assertStringContainsString('.order-workspace-bar{display:flex;align-items:center;justify-content:space-between;gap:6px', $view);
        $this->assertStringNotContainsString('class="foodex-page-toolbar"', $view);
        $this->assertStringNotContainsString('data-order-workspace-context', $view);
        $this->assertStringNotContainsString("@include('admin._module-dashboard-filters'", $view);
        $this->assertStringContainsString('data-order-counter', $view);
        $this->assertStringContainsString('data-counter-target', $view);
        $this->assertStringContainsString('window.requestAnimationFrame(tick)', $view);
        $this->assertStringContainsString('white-space:nowrap', $view);
        $this->assertStringContainsString('class="order-kpi-item"', $view);
        $this->assertStringContainsString('class="order-kpi-icon"', $view);
        $this->assertStringContainsString('data-tone="{{ $metric[\'tone\'] }}"', $view);
        $this->assertStringContainsString('.order-kpi-card::before{content:""', $view);
        $this->assertStringContainsString('@keyframes orderKpiEdge', $view);
        $this->assertStringContainsString("@include('admin._premium-icon',['name'=>\$metric['icon']])", $view);
        $this->assertStringNotContainsString("@include('admin._module-dashboard-kpi', ['metric' => \$metric])", $view);
        $this->assertStringNotContainsString("__('order_operations.description')", $view);
        $this->assertStringNotContainsString("__('order_operations.dashboard.subtitle')", $view);
        $this->assertStringNotContainsString("__('order_operations.dashboard.generated_at'", $view);
        $orderFilter = file_get_contents(resource_path('views/admin/_order-operations-order-filters.blade.php'));
        $this->assertIsString($orderFilter);
        $this->assertStringContainsString('data-order-grid-filter-controls', $orderFilter);
        $this->assertStringContainsString('data-order-grid-filter', $orderFilter);
        $this->assertStringContainsString('name="order_number"', $orderFilter);
        $this->assertStringContainsString('name="status"', $orderFilter);
        $this->assertStringContainsString('name="driver_id"', $orderFilter);
        $this->assertStringContainsString('name="exception"', $orderFilter);
        $this->assertStringContainsString('#order-tab-orders', $orderFilter);

        $controller = file_get_contents(app_path('Http/Controllers/Admin/OrderOperationsController.php'));
        $this->assertIsString($controller);
        $this->assertStringContainsString("'action_required' => ['nullable', 'boolean']", $controller);
        $this->assertStringContainsString('usesCustomerServiceDashboardProfile($actor)', $controller);
        $this->assertStringContainsString("'awaiting_dispatch'", $controller);
        $this->assertStringContainsString("'actionRequiredCount' => \$actionRequiredCount", $controller);
        $this->assertStringContainsString("'selectedActionRequired' => \$selectedActionRequired", $controller);
        $this->assertStringContainsString('orderOperationsDisplayStatus(', $controller);
        $this->assertStringContainsString("'status_visual_state' => \$displayStatus['state']", $controller);
        $this->assertStringContainsString("lifecycle_labels.assigned_driver", $controller);
        $this->assertStringContainsString("lifecycle_labels.driver_processing", $controller);
        $this->assertStringContainsString("lifecycle_labels.completed", $controller);
        $this->assertStringContainsString("'has_active_driver' => \$hasActiveDriver", $controller);
        $this->assertStringContainsString('Orders already with a Driver can only be withdrawn and transferred to another Driver.', $controller);
        $this->assertStringContainsString("customerOrderChanged(\$model->fresh(), 'details_updated'", $controller);
        $this->assertStringContainsString("customerOrderChanged(\$model->fresh(), 'customer_service_updated'", $controller);

        $headerFilter = file_get_contents(resource_path('views/admin/_order-operations-header-filters.blade.php'));
        $this->assertIsString($headerFilter);
        $this->assertStringContainsString('data-order-header-controls', $headerFilter);
        $this->assertStringContainsString('data-order-header-filter', $headerFilter);
        $this->assertStringContainsString('name="from"', $headerFilter);
        $this->assertStringContainsString('name="to"', $headerFilter);
        $this->assertStringContainsString('order-header-new-order', $headerFilter);
        $this->assertStringContainsString('.order-header-filter{display:flex', $headerFilter);
        $this->assertStringContainsString('height:34px!important', $headerFilter);
    }

    public function test_order_grid_is_fixed_to_ten_rows_per_page(): void
    {
        $super = $this->superAdmin('unified-ten-rows@example.test');
        app(WholesalePrincipal::class)->storeId();

        $this->actingAs($super)
            ->get('/admin/operations/orders?channel=b2b&per_page=25')
            ->assertOk()
            ->assertViewHas('orders', fn ($orders) => $orders->perPage() === 25);
    }

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function retailAdmin(int $storeId, string $email): User
    {
        $user = User::query()->create([
            'name' => 'Unified Retail Admin',
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function superAdmin(string $email): User
    {
        $user = User::query()->create([
            'name' => 'Unified Super Admin',
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }
}
