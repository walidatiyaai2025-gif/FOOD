<?php

namespace App\Http\Controllers\Admin;

use App\Enterprise\Wms\InventoryAdjustmentService;
use App\Enterprise\Wms\InventoryCountService;
use App\Enterprise\Wms\InventoryLotService;
use App\Enterprise\Wms\InventoryValuationService;
use App\Enterprise\Wms\ReorderPolicyService;
use App\Enterprise\Wms\StockReservationService;
use App\Enterprise\Wms\WarehouseScope;
use App\Enterprise\Wms\WarehouseTransferService;
use App\Enterprise\Wms\WmsReportingService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OperationalTenantScope;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class EnterpriseWmsController extends Controller
{
    private const TABS = [
        'overview',
        'movements',
        'transfers',
        'counts',
        'adjustments',
        'lots',
        'reservations',
        'reorder',
        'valuation',
        'aging',
    ];

    public function __construct(
        private readonly WarehouseScope $warehouseScope,
        private readonly WarehouseTransferService $transfers,
        private readonly InventoryCountService $counts,
        private readonly InventoryAdjustmentService $adjustments,
        private readonly InventoryLotService $lots,
        private readonly StockReservationService $reservations,
        private readonly ReorderPolicyService $reorder,
        private readonly InventoryValuationService $valuation,
        private readonly WmsReportingService $reports,
        private readonly OperationalTenantScope $tenantScope,
        private readonly AdminNavigation $navigation,
    ) {}

    public function index(Request $request): View
    {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'tab' => ['nullable', Rule::in(self::TABS)],
            'store_id' => ['nullable', 'integer', 'min:1'],
            'warehouse_id' => ['nullable', 'integer', 'min:1'],
            'count_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $storeIds = $this->tenantScope->allowedStoreIds($actor, 'inventory.view');
        abort_if($storeIds === [], 403);

        if (isset($validated['store_id'])) {
            $requestedStoreId = (int) $validated['store_id'];
            $this->tenantScope->assertStore($actor, $requestedStoreId, 'inventory.view');
            $storeId = $requestedStoreId;
        } else {
            $storeId = $storeIds[0];
        }

        $warehouseIds = $this->warehouseScope->authorizedWarehouseIds($actor, $storeId, 'inventory.view');
        $warehouseId = isset($validated['warehouse_id']) ? (int) $validated['warehouse_id'] : ($warehouseIds[0] ?? null);
        if ($warehouseId !== null) {
            abort_unless(in_array($warehouseId, $warehouseIds, true), 404);
        }

        $stores = DB::table('stores')
            ->whereIn('id', $storeIds)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $warehouses = DB::table('warehouses')
            ->whereIn('id', $warehouseIds)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        $inventoryLookup = $warehouseId === null
            ? collect()
            : DB::table('inventories as inventory')
                ->join('products as product', 'product.id', '=', 'inventory.product_id')
                ->where('inventory.warehouse_id', $warehouseId)
                ->orderBy('product.name')
                ->limit(250)
                ->get([
                    'inventory.id as inventory_id',
                    'inventory.product_id',
                    'inventory.quantity',
                    'inventory.reserved_quantity',
                    'product.name as product_name',
                    'product.sku',
                ]);

        $tab = (string) ($validated['tab'] ?? 'overview');
        $data = $this->tabData(
            $actor,
            $tab,
            $storeId,
            $warehouseId,
            isset($validated['count_id']) ? (int) $validated['count_id'] : null,
        );

        return view('admin.enterprise-wms', array_merge($data, [
            'user' => $actor,
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'erp_wms',
            'tabs' => self::TABS,
            'tab' => $tab,
            'stores' => $stores,
            'storeId' => $storeId,
            'warehouses' => $warehouses,
            'warehouseId' => $warehouseId,
            'inventoryLookup' => $inventoryLookup,
        ]));
    }

    public function createTransfer(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'store_id' => ['required', 'integer', 'min:1'],
            'source_warehouse_id' => ['required', 'integer', 'min:1'],
            'target_warehouse_id' => ['required', 'integer', 'different:source_warehouse_id'],
            'product_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'request_key' => ['required', 'string', 'max:120'],
        ]);

        $this->assertStore($actor, (int) $data['store_id'], 'inventory.manage');
        $this->transfers->create(
            $actor,
            (int) $data['source_warehouse_id'],
            (int) $data['target_warehouse_id'],
            [['product_id' => (int) $data['product_id'], 'quantity' => (float) $data['quantity']]],
            (string) $data['request_key'],
        );

        return $this->backTo('transfers', $data, __('enterprise_wms.messages.transfer_created'));
    }

    public function dispatchTransfer(Request $request, int $transfer): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate(['store_id' => ['required', 'integer', 'min:1']]);
        $this->assertStore($actor, (int) $data['store_id'], 'inventory.manage');
        $this->transfers->dispatch($actor, $transfer);

        return $this->backTo('transfers', $data, __('enterprise_wms.messages.transfer_dispatched'));
    }

    public function receiveTransfer(Request $request, int $transfer): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate(['store_id' => ['required', 'integer', 'min:1']]);
        $this->assertStore($actor, (int) $data['store_id'], 'inventory.manage');
        $this->transfers->receive($actor, $transfer);

        return $this->backTo('transfers', $data, __('enterprise_wms.messages.transfer_received'));
    }

    public function startCount(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'store_id' => ['required', 'integer', 'min:1'],
            'warehouse_id' => ['required', 'integer', 'min:1'],
            'inventory_ids' => ['required', 'array', 'min:1', 'max:100'],
            'inventory_ids.*' => ['required', 'integer', 'min:1'],
            'request_key' => ['required', 'string', 'max:120'],
        ]);
        $this->assertStore($actor, (int) $data['store_id'], 'inventory.manage');

        $count = $this->counts->start(
            $actor,
            (int) $data['warehouse_id'],
            array_map('intval', $data['inventory_ids']),
            (string) $data['request_key'],
        );

        return $this->backTo('counts', $data, __('enterprise_wms.messages.count_started'), [
            'count_id' => $count->getKey(),
        ]);
    }

    public function completeCount(Request $request, int $count): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'store_id' => ['required', 'integer', 'min:1'],
            'warehouse_id' => ['required', 'integer', 'min:1'],
            'counts' => ['required', 'array', 'min:1', 'max:100'],
            'counts.*' => ['required', 'numeric', 'min:0'],
        ]);
        $this->assertStore($actor, (int) $data['store_id'], 'inventory.manage');
        $this->counts->complete($actor, $count, $data['counts']);

        return $this->backTo('counts', $data, __('enterprise_wms.messages.count_completed'));
    }

    public function postAdjustment(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'store_id' => ['required', 'integer', 'min:1'],
            'warehouse_id' => ['required', 'integer', 'min:1'],
            'product_id' => ['required', 'integer', 'min:1'],
            'quantity_delta' => ['required', 'numeric', 'not_in:0'],
            'classification' => ['required', Rule::in(['correction', 'damage', 'loss'])],
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'request_key' => ['required', 'string', 'max:120'],
        ]);
        $this->assertStore($actor, (int) $data['store_id'], 'inventory.manage');

        $this->adjustments->post(
            $actor,
            (int) $data['warehouse_id'],
            (int) $data['product_id'],
            (float) $data['quantity_delta'],
            (string) $data['classification'],
            'wms_workbench_adjustment',
            $this->referenceId((string) $data['request_key']),
            (string) $data['reason'],
            isset($data['notes']) ? (string) $data['notes'] : null,
        );

        return $this->backTo('adjustments', $data, __('enterprise_wms.messages.adjustment_posted'));
    }

    public function saveLot(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'store_id' => ['required', 'integer', 'min:1'],
            'warehouse_id' => ['required', 'integer', 'min:1'],
            'product_id' => ['required', 'integer', 'min:1'],
            'identifier' => ['required', 'string', 'max:120'],
            'manufactured_on' => ['nullable', 'date'],
            'received_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date'],
        ]);
        $this->assertStore($actor, (int) $data['store_id'], 'inventory.manage');

        $this->lots->upsert($actor, (int) $data['warehouse_id'], (int) $data['product_id'], [
            'identifier' => $data['identifier'],
            'manufactured_on' => $data['manufactured_on'] ?? null,
            'received_on' => $data['received_on'] ?? null,
            'expires_on' => $data['expires_on'] ?? null,
        ]);

        return $this->backTo('lots', $data, __('enterprise_wms.messages.lot_saved'));
    }

    public function transitionLot(Request $request, int $lot): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'store_id' => ['required', 'integer', 'min:1'],
            'warehouse_id' => ['required', 'integer', 'min:1'],
            'status' => ['required', Rule::in(['active', 'quarantined', 'closed'])],
        ]);
        $this->assertStore($actor, (int) $data['store_id'], 'inventory.manage');
        $this->lots->transitionStatus($actor, $lot, (string) $data['status']);

        return $this->backTo('lots', $data, __('enterprise_wms.messages.lot_updated'));
    }

    public function saveReservation(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'store_id' => ['required', 'integer', 'min:1'],
            'warehouse_id' => ['required', 'integer', 'min:1'],
            'product_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'numeric', 'min:0'],
            'business_reference' => ['required', 'string', 'max:120'],
        ]);
        $this->assertStore($actor, (int) $data['store_id'], 'inventory.manage');

        $this->reservations->ensureReservation(
            $actor,
            (int) $data['warehouse_id'],
            (int) $data['product_id'],
            (float) $data['quantity'],
            'wms_workbench',
            $this->referenceId(
                (int) $data['warehouse_id'].'|'.(int) $data['product_id'].'|'.trim((string) $data['business_reference']),
            ),
            trim((string) $data['business_reference']),
        );

        return $this->backTo('reservations', $data, __('enterprise_wms.messages.reservation_saved'));
    }

    public function saveReorderPolicy(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'store_id' => ['required', 'integer', 'min:1'],
            'warehouse_id' => ['required', 'integer', 'min:1'],
            'product_id' => ['required', 'integer', 'min:1'],
            'minimum_quantity' => ['required', 'numeric', 'min:0'],
            'reorder_quantity' => ['required', 'numeric', 'gt:0'],
            'maximum_quantity' => ['nullable', 'numeric', 'min:0'],
        ]);
        $this->assertStore($actor, (int) $data['store_id'], 'inventory.manage');

        $this->reorder->upsert($actor, (int) $data['warehouse_id'], (int) $data['product_id'], [
            'minimum_quantity' => $data['minimum_quantity'],
            'reorder_quantity' => $data['reorder_quantity'],
            'maximum_quantity' => $data['maximum_quantity'] ?? null,
            'is_active' => true,
        ]);

        return $this->backTo('reorder', $data, __('enterprise_wms.messages.reorder_saved'));
    }

    public function disableReorderPolicy(Request $request, int $policy): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'store_id' => ['required', 'integer', 'min:1'],
            'warehouse_id' => ['required', 'integer', 'min:1'],
        ]);
        $this->assertStore($actor, (int) $data['store_id'], 'inventory.manage');
        $this->reorder->disable($actor, $policy);

        return $this->backTo('reorder', $data, __('enterprise_wms.messages.reorder_disabled'));
    }

    public function saveValuationPolicy(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'store_id' => ['required', 'integer', 'min:1'],
            'warehouse_id' => ['required', 'integer', 'min:1'],
            'product_id' => ['required', 'integer', 'min:1'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
        ]);
        $this->assertStore($actor, (int) $data['store_id'], 'inventory.manage');

        $this->valuation->upsertPolicy($actor, (int) $data['warehouse_id'], (int) $data['product_id'], [
            'cost_method' => 'standard',
            'unit_cost' => $data['unit_cost'],
            'is_active' => true,
        ]);

        return $this->backTo('valuation', $data, __('enterprise_wms.messages.valuation_saved'));
    }

    /** @return array<string, mixed> */
    private function tabData(User $actor, string $tab, int $storeId, ?int $warehouseId, ?int $countId): array
    {
        $empty = collect();
        $data = [
            'inventoryRows' => $empty,
            'movementRows' => $empty,
            'transferRows' => $empty,
            'countRows' => $empty,
            'countLines' => $empty,
            'selectedCountId' => null,
            'adjustmentRows' => $empty,
            'lotRows' => $empty,
            'expiryRows' => $empty,
            'reorderRows' => $empty,
            'lowStockRows' => $empty,
            'valuationRows' => $empty,
            'agingRows' => $empty,
            'slowStockRows' => $empty,
        ];

        if ($tab === 'overview') {
            $data['inventoryRows'] = $this->reports->inventorySummary($actor, $storeId, $warehouseId, null, null, 50);
            $data['lowStockRows'] = $this->reorder->lowStock($actor, $storeId, $warehouseId, 10);

            return $data;
        }

        if ($tab === 'movements' && $warehouseId !== null) {
            $this->warehouseScope->assertWarehouse($actor, $warehouseId, 'inventory.view');
            $data['movementRows'] = DB::table('stock_movements as movement')
                ->join('inventories as inventory', 'inventory.id', '=', 'movement.inventory_id')
                ->join('products as product', 'product.id', '=', 'inventory.product_id')
                ->where('inventory.warehouse_id', $warehouseId)
                ->orderByDesc('movement.id')
                ->limit(100)
                ->get([
                    'movement.id',
                    'movement.type',
                    'movement.quantity',
                    'movement.reference_type',
                    'movement.reference_id',
                    'movement.reason',
                    'movement.created_at',
                    'product.name as product_name',
                    'product.sku',
                ]);

            return $data;
        }

        if ($tab === 'transfers') {
            $data['transferRows'] = $this->transfers->listForStore($actor, $storeId, null, 50);

            return $data;
        }

        if ($tab === 'counts') {
            $data['countRows'] = $this->counts->listForStore($actor, $storeId, null, 50);
            if ($countId !== null && $data['countRows']->contains(fn ($row): bool => (int) $row->getKey() === $countId)) {
                $data['selectedCountId'] = $countId;
                $data['countLines'] = DB::table('enterprise_inventory_count_lines as line')
                    ->join('products as product', 'product.id', '=', 'line.product_id')
                    ->where('line.count_id', $countId)
                    ->orderBy('line.id')
                    ->get([
                        'line.inventory_id',
                        'line.expected_quantity',
                        'line.counted_quantity',
                        'line.variance_quantity',
                        'product.name as product_name',
                        'product.sku',
                    ]);
            }

            return $data;
        }

        if ($tab === 'adjustments' && $warehouseId !== null) {
            $data['adjustmentRows'] = $this->adjustments->recentHistory($actor, $warehouseId, null, null, 100);

            return $data;
        }

        if ($tab === 'lots' && $warehouseId !== null) {
            $data['lotRows'] = $this->lots->lotsForWarehouse($actor, $warehouseId, null, null, 50);
            $data['expiryRows'] = $this->lots->expiryWindow(
                $actor,
                $storeId,
                now()->toDateString(),
                now()->addDays(30)->toDateString(),
                $warehouseId,
                50,
            );

            return $data;
        }

        if ($tab === 'reservations') {
            $data['inventoryRows'] = $this->reports->inventorySummary($actor, $storeId, $warehouseId, null, null, 100);

            return $data;
        }

        if ($tab === 'reorder') {
            if ($warehouseId !== null) {
                $data['reorderRows'] = $this->reorder->policiesForWarehouse($actor, $warehouseId, 100);
            }
            $data['lowStockRows'] = $this->reorder->lowStock($actor, $storeId, $warehouseId, 100);

            return $data;
        }

        if ($tab === 'valuation') {
            $data['valuationRows'] = $this->valuation->valuation($actor, $storeId, $warehouseId, 100);

            return $data;
        }

        if ($tab === 'aging') {
            $data['agingRows'] = $this->valuation->aging($actor, $storeId, $warehouseId, 100);
            $data['slowStockRows'] = $this->valuation->slowOrDeadStock($actor, $storeId, 90, $warehouseId, 100);

            return $data;
        }

        return $data;
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    private function assertStore(User $actor, int $storeId, string $permission): void
    {
        $this->tenantScope->assertStore($actor, $storeId, $permission);
    }

    /** @param array<string, mixed> $data
     * @param  array<string, mixed>  $extra
     */
    private function backTo(string $tab, array $data, string $message, array $extra = []): RedirectResponse
    {
        return redirect()
            ->route('admin.erp.wms.index', array_filter(array_merge([
                'tab' => $tab,
                'store_id' => isset($data['store_id']) ? (int) $data['store_id'] : null,
                'warehouse_id' => isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null,
            ], $extra), static fn ($value): bool => $value !== null))
            ->with('status', $message);
    }

    private function referenceId(string $key): int
    {
        $value = hexdec(substr(hash('sha256', $key), 0, 8)) & 0x7FFFFFFF;

        return max(1, (int) $value);
    }
}
