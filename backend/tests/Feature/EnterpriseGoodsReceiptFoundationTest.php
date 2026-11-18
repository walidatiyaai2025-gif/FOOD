<?php

namespace Tests\Feature;

use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Enterprise\Procurement\GoodsReceiptManager;
use App\Enterprise\Procurement\PurchaseOrderManager;
use App\Enterprise\Procurement\PurchaseRequestManager;
use App\Enterprise\Procurement\SupplierDirectory;
use App\Models\Catalog;
use App\Models\EnterprisePurchaseOrder;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\ResolvedTenantContext;
use App\Support\TenantContextResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\ProcurementSecondApprover;
use Tests\TestCase;

final class EnterpriseGoodsReceiptFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_procurement_performs_no_grn_query_work(): void
    {
        [$actor, , $context] = $this->scopedUser($this->allPermissions(), 'GRN-OFF');

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower((string) $query->sql);
        });

        try {
            app(GoodsReceiptManager::class)->paginate($actor, $context);
            $this->fail('Disabled Procurement must reject GRN reads.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertFalse(
            collect($queries)->contains(
                static fn (string $sql): bool => str_contains($sql, 'enterprise_goods_receipt'),
            ),
        );
    }

    public function test_posted_grn_hands_inventory_off_once_and_is_retry_safe(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'GRN-A');
        [$product, $warehouse] = $this->stockTarget($store, 'GRN-A-SKU');
        $order = $this->issuedOrder($actor, $context, 'GRN-A-SUP', 'GRN-A-SKU', 5);

        $manager = app(GoodsReceiptManager::class);
        $receipt = $manager->createFromIssuedOrder($actor, $context, $order->id, [
            'lines' => [[
                'purchase_order_line_id' => $order->lines()->firstOrFail()->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => 2,
            ]],
        ]);

        $posted = $manager->post($actor, $context, $receipt->id);
        $retry = $manager->post($actor, $context, $receipt->id);

        $this->assertSame('posted', $posted->status);
        $this->assertSame($posted->posted_at?->toISOString(), $retry->posted_at?->toISOString());
        $this->assertSame($order->order_number, $posted->source_order_number);
        $this->assertSame('GRN-A-SUP', $posted->supplier_code);
        $this->assertSame('5.000', $posted->lines[0]->ordered_quantity);
        $this->assertSame('2.000', $posted->lines[0]->received_quantity);

        $inventory = DB::table('inventories')
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->first();

        $this->assertNotNull($inventory);
        $this->assertSame(2.0, (float) $inventory->quantity);
        // Repeated GRN post does not double-post either stock or UNKNOWN title.
        $this->assertDatabaseHas('enterprise_inventory_owner_positions', [
            'inventory_id' => $inventory->id,
            'owner_key' => 'UNKNOWN',
            'owner_kind' => 'UNKNOWN',
            'supplier_id' => null,
            'quantity' => 2,
            'reserved_quantity' => 0,
            'certified_by' => null,
        ]);
        $this->assertSame(1, DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $inventory->id)->count());
        $this->assertSame(
            1,
            DB::table('stock_movements')
                ->where('store_id', $store->id)
                ->where('type', 'purchase_receipt')
                ->where('reference_type', 'enterprise_goods_receipt_line')
                ->where('reference_id', $posted->lines[0]->id)
                ->count(),
        );
        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('event', 'enterprise.goods_receipt.posted')
                ->where('auditable_id', $receipt->id)
                ->count(),
        );
    }

    public function test_partial_receipts_cannot_over_receive_purchase_order_remaining(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'GRN-B');
        [$product, $warehouse] = $this->stockTarget($store, 'GRN-B-SKU');
        $order = $this->issuedOrder($actor, $context, 'GRN-B-SUP', 'GRN-B-SKU', 5);
        $poLineId = $order->lines()->firstOrFail()->id;
        $manager = app(GoodsReceiptManager::class);

        $first = $manager->createFromIssuedOrder($actor, $context, $order->id, [
            'lines' => [[
                'purchase_order_line_id' => $poLineId,
                'warehouse_id' => $warehouse->id,
                'quantity' => 2,
            ]],
        ]);
        $manager->post($actor, $context, $first->id);

        try {
            $manager->createFromIssuedOrder($actor, $context, $order->id, [
                'lines' => [[
                    'purchase_order_line_id' => $poLineId,
                    'warehouse_id' => $warehouse->id,
                    'quantity' => 4,
                ]],
            ]);
            $this->fail('Draft creation must reject quantities beyond the current ordered remainder.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines', $exception->errors());
        }

        $staleA = $manager->createFromIssuedOrder($actor, $context, $order->id, [
            'lines' => [[
                'purchase_order_line_id' => $poLineId,
                'warehouse_id' => $warehouse->id,
                'quantity' => 3,
            ]],
        ]);
        $staleB = $manager->createFromIssuedOrder($actor, $context, $order->id, [
            'lines' => [[
                'purchase_order_line_id' => $poLineId,
                'warehouse_id' => $warehouse->id,
                'quantity' => 3,
            ]],
        ]);

        $manager->post($actor, $context, $staleA->id);

        try {
            $manager->post($actor, $context, $staleB->id);
            $this->fail('Posting a stale draft must re-check the remaining quantity under the PO lock.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines', $exception->errors());
        }

        $this->assertDatabaseHas('enterprise_goods_receipts', [
            'id' => $staleB->id,
            'status' => 'draft',
        ]);
        $this->assertSame(
            5.0,
            (float) DB::table('inventories')
                ->where('warehouse_id', $warehouse->id)
                ->where('product_id', $product->id)
                ->value('quantity'),
        );
        $this->assertSame(
            2,
            DB::table('stock_movements')
                ->where('store_id', $store->id)
                ->where('type', 'purchase_receipt')
                ->where('reference_type', 'enterprise_goods_receipt_line')
                ->count(),
        );
    }

    /**
     * P110: the supplier on a commercial PO/GRN is not legal stock title.
     * Partial milliquantity postings must only advance canonical stock and
     * the unverified UNKNOWN projection once, even with forged owner/price
     * fields in an otherwise valid procurement receipt payload.
     */
    public function test_partial_milli_receipts_replay_and_forged_title_never_double_post_or_grant_ownership(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'GRN-P110-MILLI');
        [$product, $warehouse] = $this->stockTarget($store, 'GRN-P110-MILLI-SKU');
        $order = $this->issuedOrder($actor, $context, 'GRN-P110-MILLI-SUP', 'GRN-P110-MILLI-SKU', 1.500);
        $orderLineId = $order->lines()->firstOrFail()->id;
        $manager = app(GoodsReceiptManager::class);
        $receiptIds = [];
        $receiptLineIds = [];

        foreach ([0.625, 0.875] as $receivedQuantity) {
            $receipt = $manager->createFromIssuedOrder($actor, $context, $order->id, [
                // Never accept asserted legal title, a substitute supplier,
                // price or discount from an untrusted GRN request.
                'supplier_id' => 999999,
                'owner_key' => 'FOODEX',
                'discount_percent' => 100,
                'lines' => [[
                    'purchase_order_line_id' => $orderLineId,
                    'warehouse_id' => $warehouse->id,
                    'quantity' => $receivedQuantity,
                    'owner_key' => 'SUPPLIER:'.$order->supplier_id,
                    'unit' => 'case',
                    'unit_price' => 0,
                ]],
            ]);
            $posted = $manager->post($actor, $context, $receipt->id);
            $retry = $manager->post($actor, $context, $receipt->id);

            $this->assertSame('posted', $posted->status);
            $this->assertSame($posted->posted_at?->toISOString(), $retry->posted_at?->toISOString());
            $this->assertSame((int) $order->supplier_id, (int) $posted->supplier_id);
            $this->assertSame($order->order_number, $posted->source_order_number);
            $this->assertSame('each', $posted->lines[0]->unit);
            $this->assertSame('1.250', $posted->lines[0]->unit_price);

            $receiptIds[] = $receipt->id;
            $receiptLineIds[] = $posted->lines[0]->id;
        }

        $inventory = DB::table('inventories')
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->assertSame('1.500', number_format((float) $inventory->quantity, 3, '.', ''));
        $this->assertDatabaseHas('enterprise_inventory_owner_positions', [
            'inventory_id' => $inventory->id,
            'owner_key' => 'UNKNOWN',
            'owner_kind' => 'UNKNOWN',
            'supplier_id' => null,
            'certified_by' => null,
        ]);
        $this->assertSame(1, DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $inventory->id)->count());
        $this->assertSame('1.500', number_format((float) DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $inventory->id)
            ->value('quantity'), 3, '.', ''));
        $this->assertDatabaseMissing('enterprise_inventory_owner_positions', [
            'inventory_id' => $inventory->id,
            'owner_key' => 'FOODEX',
        ]);
        $this->assertDatabaseMissing('enterprise_inventory_owner_positions', [
            'inventory_id' => $inventory->id,
            'owner_key' => 'SUPPLIER:'.$order->supplier_id,
        ]);

        $movements = DB::table('stock_movements')
            ->where('inventory_id', $inventory->id)
            ->where('type', 'purchase_receipt')
            ->where('reference_type', 'enterprise_goods_receipt_line')
            ->whereIn('reference_id', $receiptLineIds);
        $this->assertSame(2, $movements->count());
        $this->assertSame('1.500', number_format((float) $movements->sum('quantity'), 3, '.', ''));
        $movementIds = $movements->pluck('id');
        $ownerEvents = DB::table('enterprise_inventory_owner_movement_events')
            ->whereIn('source_stock_movement_id', $movementIds);
        $this->assertSame(2, $ownerEvents->count(), 'Each posted GRN movement must have exactly one immutable event, including replay.');
        $this->assertSame(2, (clone $ownerEvents)->where('owner_snapshot_state', 'UNKNOWN')->count());
        $this->assertSame(2, (clone $ownerEvents)->where('owner_position_count', 1)->count());
        $this->assertSame(2, DB::table('audit_logs')
            ->where('event', 'enterprise.goods_receipt.posted')
            ->whereIn('auditable_id', $receiptIds)
            ->count());

        try {
            $manager->createFromIssuedOrder($actor, $context, $order->id, [
                'lines' => [[
                    'purchase_order_line_id' => $orderLineId,
                    'warehouse_id' => $warehouse->id,
                    'quantity' => 0.001,
                ]],
            ]);
            $this->fail('Even one milliquantity beyond the already-posted PO remainder must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines', $exception->errors());
        }

        $this->assertSame(2, DB::table('enterprise_goods_receipts')
            ->where('purchase_order_id', $order->id)->count());
        $this->assertSame(2, $movements->count());
        $this->assertSame('1.500', number_format((float) DB::table('inventories')
            ->where('id', $inventory->id)->value('quantity'), 3, '.', ''));
    }

    public function test_grn_requires_issued_po_and_store_owned_receiving_warehouse(): void
    {
        $this->enableProcurement();
        [$actorA, $storeA, $contextA] = $this->scopedUser($this->allPermissions(), 'GRN-C-A');
        [, $storeB] = $this->scopedUser($this->allPermissions(), 'GRN-C-B');
        [, $foreignWarehouse] = $this->stockTarget($storeB, 'GRN-C-B-SKU');
        $order = $this->approvedOrder($actorA, $contextA, 'GRN-C-SUP', 'GRN-C-A-SKU', 2);
        $lineId = $order->lines()->firstOrFail()->id;
        $manager = app(GoodsReceiptManager::class);

        try {
            $manager->createFromIssuedOrder($actorA, $contextA, $order->id, [
                'lines' => [[
                    'purchase_order_line_id' => $lineId,
                    'warehouse_id' => $foreignWarehouse->id,
                    'quantity' => 1,
                ]],
            ]);
            $this->fail('Draft PO must not source a GRN.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('purchase_order_id', $exception->errors());
        }

        app(PurchaseOrderManager::class)->issue($actorA, $contextA, $order->id);

        $this->expectException(ValidationException::class);
        $manager->createFromIssuedOrder($actorA, $contextA, $order->id, [
            'lines' => [[
                'purchase_order_line_id' => $lineId,
                'warehouse_id' => $foreignWarehouse->id,
                'quantity' => 1,
            ]],
        ]);
    }

    public function test_grn_reads_are_bounded_and_cross_store_isolated(): void
    {
        $this->enableProcurement();
        [$actorA, $storeA, $contextA] = $this->scopedUser($this->allPermissions(), 'GRN-D-A');
        [$actorB, , $contextB] = $this->scopedUser($this->allPermissions(), 'GRN-D-B');
        [, $warehouseA] = $this->stockTarget($storeA, 'GRN-D-A-SKU');
        $order = $this->issuedOrder($actorA, $contextA, 'GRN-D-SUP', 'GRN-D-A-SKU', 1);
        $receipt = app(GoodsReceiptManager::class)->createFromIssuedOrder($actorA, $contextA, $order->id, [
            'lines' => [[
                'purchase_order_line_id' => $order->lines()->firstOrFail()->id,
                'warehouse_id' => $warehouseA->id,
                'quantity' => 1,
            ]],
        ]);

        $manager = app(GoodsReceiptManager::class);
        $page = $manager->paginate($actorA, $contextA, ['status' => 'draft'], 500);

        $this->assertSame(100, $page->perPage());
        $this->assertSame(1, $page->total());
        $this->assertSame(0, $manager->paginate($actorB, $contextB)->total());

        try {
            $manager->detail($actorB, $contextB, $receipt->id);
            $this->fail('Cross-store GRN detail must be invisible.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }
    }

    public function test_grn_number_prefix_search_is_literal_scoped_and_read_only(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'GRN-LIT-A');
        [$otherActor, $otherStore, $otherContext] = $this->scopedUser(
            $this->allPermissions(),
            'GRN-LIT-B',
        );
        [, $warehouse] = $this->stockTarget($store, 'GRN-LIT-A-SKU');
        [, $otherWarehouse] = $this->stockTarget($otherStore, 'GRN-LIT-B-SKU');
        $order = $this->issuedOrder($actor, $context, 'GRN-LIT-A-SUP', 'GRN-LIT-A-SKU', 10);
        $otherOrder = $this->issuedOrder(
            $otherActor, $otherContext, 'GRN-LIT-B-SUP', 'GRN-LIT-B-SKU', 2,
        );
        $manager = app(GoodsReceiptManager::class);
        $orderLine = $order->lines()->firstOrFail()->id;
        $newReceipt = function () use ($manager, $actor, $context, $order, $orderLine, $warehouse): int {
            return (int) $manager->createFromIssuedOrder($actor, $context, $order->id, [
                'lines' => [[
                    'purchase_order_line_id' => $orderLine,
                    'warehouse_id' => $warehouse->id,
                    'quantity' => 1,
                ]],
            ])->id;
        };

        $target = $newReceipt();
        $decoy = $newReceipt();
        $leadingSymbols = [];
        foreach ([
            ['%', '%P-001'],
            ['_', '_U-001'],
            ['!', '!B-001'],
            ['\\', '\\S-001'],
        ] as [$symbol, $number]) {
            $leadingSymbols[$symbol] = $newReceipt();
            DB::table('enterprise_goods_receipts')
                ->where('id', $leadingSymbols[$symbol])
                ->update(['receipt_number' => $number]);
        }
        $foreign = $manager->createFromIssuedOrder(
            $otherActor,
            $otherContext,
            $otherOrder->id,
            ['lines' => [[
                'purchase_order_line_id' => $otherOrder->lines()->firstOrFail()->id,
                'warehouse_id' => $otherWarehouse->id,
                'quantity' => 1,
            ]]],
        );
        foreach ([
            [$target, 'GRN_%!\\-A'],
            [$decoy, 'GRNX-OTHER'],
            [$foreign->id, 'GRN_%!\\-FOREIGN'],
        ] as [$id, $number]) {
            DB::table('enterprise_goods_receipts')
                ->where('id', $id)
                ->update(['receipt_number' => $number]);
        }

        $receiptCount = DB::table('enterprise_goods_receipts')->count();
        $auditCount = DB::table('audit_logs')->count();
        $movementCount = DB::table('stock_movements')->count();
        $search = fn (string $q): array => $manager->paginate($actor, $context, ['q' => $q])
            ->getCollection()->pluck('id')->all();

        foreach (['GRN_', 'GRN_%', 'GRN_%!', 'GRN_%!\\-'] as $q) {
            $this->assertSame([$target], $search($q), 'Incorrect literal GRN prefix: '.$q);
        }
        foreach ($leadingSymbols as $symbol => $id) {
            $this->assertSame([$id], $search($symbol), 'Symbol search widened: '.$symbol);
        }
        $this->assertSame([$decoy, $target], $search('GRN'));
        $this->assertSame([], $search('GRN%'));
        $this->assertSame(1, $manager->paginate($otherActor, $otherContext, [
            'q' => 'GRN_%!',
        ])->total());
        $this->assertSame(0, $manager->paginate($actor, $context, [
            'q' => 'GRN', 'status' => 'posted',
        ])->total());
        $this->assertSame(0, $manager->paginate($actor, $context, [
            'q' => 'GRN', 'purchase_order_id' => $otherOrder->id,
        ])->total());
        $this->assertSame(10, $manager->paginate($actor, $context, [], 1)->perPage());

        $roleless = User::factory()->create(['is_active' => true]);
        try {
            $manager->paginate($roleless, $context, ['q' => 'GRN_%']);
            $this->fail('Roleless actors cannot read GRN records.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame($receiptCount, DB::table('enterprise_goods_receipts')->count());
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
        $this->assertSame($movementCount, DB::table('stock_movements')->count());
    }

    public function test_draft_cancel_is_retry_safe_and_posted_cancel_is_rejected(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'GRN-E');
        [, $warehouse] = $this->stockTarget($store, 'GRN-E-SKU');
        $order = $this->issuedOrder($actor, $context, 'GRN-E-SUP', 'GRN-E-SKU', 2);
        $lineId = $order->lines()->firstOrFail()->id;
        $manager = app(GoodsReceiptManager::class);

        $draft = $manager->createFromIssuedOrder($actor, $context, $order->id, [
            'lines' => [[
                'purchase_order_line_id' => $lineId,
                'warehouse_id' => $warehouse->id,
                'quantity' => 1,
            ]],
        ]);
        $cancelled = $manager->cancel($actor, $context, $draft->id);
        $again = $manager->cancel($actor, $context, $draft->id);

        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame($cancelled->cancelled_at?->toISOString(), $again->cancelled_at?->toISOString());
        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('event', 'enterprise.goods_receipt.cancelled')
                ->where('auditable_id', $draft->id)
                ->count(),
        );

        $postedReceipt = $manager->createFromIssuedOrder($actor, $context, $order->id, [
            'lines' => [[
                'purchase_order_line_id' => $lineId,
                'warehouse_id' => $warehouse->id,
                'quantity' => 1,
            ]],
        ]);
        $manager->post($actor, $context, $postedReceipt->id);

        $this->expectException(ValidationException::class);
        $manager->cancel($actor, $context, $postedReceipt->id);
    }

    public function test_grn_migration_is_reversible(): void
    {
        $migration = require database_path(
            'migrations/2026_10_16_071500_create_enterprise_goods_receipt_foundation.php',
        );

        $migration->down();
        $this->assertFalse(Schema::hasTable('enterprise_goods_receipt_lines'));
        $this->assertFalse(Schema::hasTable('enterprise_goods_receipts'));

        $migration->up();
        $this->assertTrue(Schema::hasColumns('enterprise_goods_receipts', [
            'purchase_order_id',
            'organization_id',
            'company_id',
            'store_id',
            'status',
            'posted_at',
        ]));
        $this->assertTrue(Schema::hasColumns('enterprise_goods_receipt_lines', [
            'purchase_order_line_id',
            'received_quantity',
            'warehouse_id',
            'inventory_id',
            'stock_movement_id',
        ]));
    }

    private function enableProcurement(): void
    {
        $lifecycle = app(ModuleLifecycleManager::class);
        $lifecycle->transition('procurement', ModuleState::ENABLING);
        $lifecycle->transition('procurement', ModuleState::ENABLED);
    }

    private function issuedOrder(
        User $actor,
        ResolvedTenantContext $context,
        string $supplierCode,
        string $sku,
        float $quantity,
    ): EnterprisePurchaseOrder {
        $order = $this->approvedOrder($actor, $context, $supplierCode, $sku, $quantity);

        return app(PurchaseOrderManager::class)->issue($actor, $context, $order->id);
    }

    private function approvedOrder(
        User $actor,
        ResolvedTenantContext $context,
        string $supplierCode,
        string $sku,
        float $quantity,
    ): EnterprisePurchaseOrder {
        $supplier = app(SupplierDirectory::class)->create($actor, $context, [
            'scope_type' => 'store',
            'code' => $supplierCode,
            'name_en' => $supplierCode,
        ]);

        $requests = app(PurchaseRequestManager::class);
        $request = $requests->create($actor, $context, [
            'supplier_id' => $supplier->id,
            'currency_code' => 'KWD',
            'reason' => 'GRN source order',
            'lines' => [[
                'item_code' => $sku,
                'description' => 'Stock item '.$sku,
                'quantity' => $quantity,
                'unit' => 'each',
                'estimated_unit_cost' => 1.250,
            ]],
        ]);
        $requests->submit($actor, $context, $request->id);
        [$checker, $checkerContext] = ProcurementSecondApprover::forSameStore($actor, $context);
        $requests->approve($checker, $checkerContext, $request->id);

        return app(PurchaseOrderManager::class)->createFromApprovedRequest($actor, $context, $request->id);
    }

    /** @return array{Product,Warehouse} */
    private function stockTarget(Store $store, string $sku): array
    {
        $catalog = Catalog::query()->create([
            'store_id' => $store->id,
            'channel' => 'b2c',
            'code' => 'grn-'.strtolower($sku),
            'name' => $sku.' Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
        ]);

        $unitCode = 'EA-'.substr(md5($sku), 0, 10);
        $unitId = (int) DB::table('units')->insertGetId([
            'store_id' => null,
            'scope' => 'global',
            'scope_key' => 'global',
            'code' => $unitCode,
            'name' => 'Each',
            'name_ar' => 'قطعة',
            'name_en' => 'Each',
            'decimal_places' => 3,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $product = Product::query()->create([
            'catalog_id' => $catalog->id,
            'unit_id' => $unitId,
            'sku' => $sku,
            'name' => $sku,
            'is_active' => true,
        ]);

        $warehouse = Warehouse::query()->create([
            'store_id' => $store->id,
            'code' => 'WH-'.substr(md5($sku), 0, 12),
            'name' => $sku.' Receiving',
            'is_active' => true,
        ]);

        return [$product, $warehouse];
    }

    /** @return list<string> */
    private function allPermissions(): array
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
        ];
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

        return [
            $user,
            $store,
            app(TenantContextResolver::class)->retail($user, $store->id),
        ];
    }

    private function createRetailStore(string $code): Store
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

        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }
}
