<?php

namespace Tests\Feature;

use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Enterprise\Procurement\GoodsReceiptManager;
use App\Enterprise\Procurement\ProcurementCompletionManager;
use App\Enterprise\Procurement\PurchaseInvoiceHandoffManager;
use App\Enterprise\Procurement\PurchaseOrderManager;
use App\Enterprise\Procurement\PurchaseRequestManager;
use App\Enterprise\Procurement\PurchaseReturnManager;
use App\Enterprise\Procurement\SupplierDirectory;
use App\Models\Catalog;
use App\Models\EnterpriseGoodsReceipt;
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
use Tests\TestCase;

final class EnterprisePurchaseInvoiceHandoffFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_procurement_performs_no_purchase_invoice_query_work(): void
    {
        [$actor, , $context] = $this->scopedUser($this->allPermissions(), 'PINV-OFF');

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower((string) $query->sql);
        });

        try {
            app(PurchaseInvoiceHandoffManager::class)->paginate($actor, $context);
            $this->fail('Disabled Procurement must reject purchase invoice reads.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertFalse(
            collect($queries)->contains(
                static fn (string $sql): bool => str_contains($sql, 'enterprise_purchase_invoice'),
            ),
        );
    }

    public function test_handoff_is_transactional_retry_safe_and_emits_one_outbox_event(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'PINV-A');
        [, $warehouse] = $this->stockTarget($store, 'PINV-A-SKU');
        $receipt = $this->postedReceipt($actor, $context, $warehouse, 'PINV-A-SUP', 'PINV-A-SKU', 5);

        $manager = app(PurchaseInvoiceHandoffManager::class);
        $invoice = $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
            'supplier_invoice_number' => 'SUP-INV-A',
            'invoice_date' => '2026-10-16',
            'due_date' => '2026-11-15',
            'lines' => [[
                'goods_receipt_line_id' => $receipt->lines()->firstOrFail()->id,
                'quantity' => 2,
            ]],
        ]);

        $handedOff = $manager->handoff($actor, $context, $invoice->id);
        $retry = $manager->handoff($actor, $context, $invoice->id);

        $this->assertSame('handed_off', $handedOff->status);
        $this->assertNotNull($handedOff->outbox_event_id);
        $this->assertSame($handedOff->outbox_event_id, $retry->outbox_event_id);
        $this->assertSame($handedOff->handed_off_at?->toISOString(), $retry->handed_off_at?->toISOString());
        $this->assertSame('2.500', $handedOff->total_amount);
        $this->assertSame(
            1,
            DB::table('enterprise_outbox_messages')
                ->where('event_id', $handedOff->outbox_event_id)
                ->where('event_type', 'ProcurementPurchaseInvoiceHandedOff')
                ->where('event_version', 1)
                ->count(),
        );
        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('event', 'enterprise.purchase_invoice.handed_off')
                ->where('auditable_id', $invoice->id)
                ->count(),
        );
    }

    public function test_posted_returns_reduce_net_invoiceable_quantity(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'PINV-B');
        [, $warehouse] = $this->stockTarget($store, 'PINV-B-SKU');
        $receipt = $this->postedReceipt($actor, $context, $warehouse, 'PINV-B-SUP', 'PINV-B-SKU', 5);
        $receiptLine = $receipt->lines()->firstOrFail();

        $returns = app(PurchaseReturnManager::class);
        $return = $returns->createFromPostedReceipt($actor, $context, $receipt->id, [
            'lines' => [[
                'goods_receipt_line_id' => $receiptLine->id,
                'quantity' => 2,
            ]],
        ]);
        $returns->post($actor, $context, $return->id);

        $manager = app(PurchaseInvoiceHandoffManager::class);

        try {
            $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
                'supplier_invoice_number' => 'SUP-INV-B-BAD',
                'invoice_date' => '2026-10-16',
                'lines' => [[
                    'goods_receipt_line_id' => $receiptLine->id,
                    'quantity' => 4,
                ]],
            ]);
            $this->fail('Invoice quantity must not exceed received quantity net of posted returns.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines', $exception->errors());
        }

        $invoice = $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
            'supplier_invoice_number' => 'SUP-INV-B',
            'invoice_date' => '2026-10-16',
            'lines' => [[
                'goods_receipt_line_id' => $receiptLine->id,
                'quantity' => 3,
            ]],
        ]);
        $manager->handoff($actor, $context, $invoice->id);

        $this->expectException(ValidationException::class);
        $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
            'supplier_invoice_number' => 'SUP-INV-B-SECOND',
            'invoice_date' => '2026-10-16',
            'lines' => [[
                'goods_receipt_line_id' => $receiptLine->id,
                'quantity' => 1,
            ]],
        ]);
    }

    public function test_stale_overlapping_draft_is_rechecked_at_handoff(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'PINV-C');
        [, $warehouse] = $this->stockTarget($store, 'PINV-C-SKU');
        $receipt = $this->postedReceipt($actor, $context, $warehouse, 'PINV-C-SUP', 'PINV-C-SKU', 5);
        $lineId = $receipt->lines()->firstOrFail()->id;
        $manager = app(PurchaseInvoiceHandoffManager::class);

        $first = $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
            'supplier_invoice_number' => 'SUP-INV-C-1',
            'invoice_date' => '2026-10-16',
            'lines' => [[
                'goods_receipt_line_id' => $lineId,
                'quantity' => 4,
            ]],
        ]);
        $stale = $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
            'supplier_invoice_number' => 'SUP-INV-C-2',
            'invoice_date' => '2026-10-16',
            'lines' => [[
                'goods_receipt_line_id' => $lineId,
                'quantity' => 4,
            ]],
        ]);

        $manager->handoff($actor, $context, $first->id);

        try {
            $manager->handoff($actor, $context, $stale->id);
            $this->fail('Handoff must re-check invoiceable quantity under the source GRN lock.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines', $exception->errors());
        }

        $this->assertDatabaseHas('enterprise_purchase_invoices', [
            'id' => $stale->id,
            'status' => 'draft',
            'outbox_event_id' => null,
        ]);
        $this->assertSame(
            1,
            DB::table('enterprise_outbox_messages')
                ->where('event_type', 'ProcurementPurchaseInvoiceHandedOff')
                ->count(),
        );
    }

    public function test_supplier_invoice_number_is_unique_and_reads_are_scope_bounded(): void
    {
        $this->enableProcurement();
        [$actorA, $storeA, $contextA] = $this->scopedUser($this->allPermissions(), 'PINV-D-A');
        [$actorB, , $contextB] = $this->scopedUser($this->allPermissions(), 'PINV-D-B');
        [, $warehouseA] = $this->stockTarget($storeA, 'PINV-D-A-SKU');
        $receipt = $this->postedReceipt($actorA, $contextA, $warehouseA, 'PINV-D-SUP', 'PINV-D-A-SKU', 2);
        $lineId = $receipt->lines()->firstOrFail()->id;
        $manager = app(PurchaseInvoiceHandoffManager::class);

        $invoice = $manager->createFromPostedReceipt($actorA, $contextA, $receipt->id, [
            'supplier_invoice_number' => 'SUP-INV-D',
            'invoice_date' => '2026-10-16',
            'lines' => [[
                'goods_receipt_line_id' => $lineId,
                'quantity' => 1,
            ]],
        ]);

        try {
            $manager->createFromPostedReceipt($actorA, $contextA, $receipt->id, [
                'supplier_invoice_number' => 'SUP-INV-D',
                'invoice_date' => '2026-10-16',
                'lines' => [[
                    'goods_receipt_line_id' => $lineId,
                    'quantity' => 1,
                ]],
            ]);
            $this->fail('Supplier invoice numbers must remain unique within company and supplier scope.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('supplier_invoice_number', $exception->errors());
        }

        $page = $manager->paginate($actorA, $contextA, ['status' => 'draft'], 500);
        $this->assertSame(100, $page->perPage());
        $this->assertSame(1, $page->total());
        $this->assertSame(0, $manager->paginate($actorB, $contextB)->total());

        try {
            $manager->detail($actorB, $contextB, $invoice->id);
            $this->fail('Cross-store purchase invoice detail must be invisible.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }
    }

    public function test_draft_cancel_is_retry_safe_and_handed_off_invoice_is_immutable(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'PINV-E');
        [, $warehouse] = $this->stockTarget($store, 'PINV-E-SKU');
        $receipt = $this->postedReceipt($actor, $context, $warehouse, 'PINV-E-SUP', 'PINV-E-SKU', 2);
        $lineId = $receipt->lines()->firstOrFail()->id;
        $manager = app(PurchaseInvoiceHandoffManager::class);

        $draft = $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
            'supplier_invoice_number' => 'SUP-INV-E-CANCEL',
            'invoice_date' => '2026-10-16',
            'lines' => [[
                'goods_receipt_line_id' => $lineId,
                'quantity' => 1,
            ]],
        ]);
        $cancelled = $manager->cancel($actor, $context, $draft->id);
        $again = $manager->cancel($actor, $context, $draft->id);

        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame($cancelled->cancelled_at?->toISOString(), $again->cancelled_at?->toISOString());

        $handed = $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
            'supplier_invoice_number' => 'SUP-INV-E-HAND',
            'invoice_date' => '2026-10-16',
            'lines' => [[
                'goods_receipt_line_id' => $lineId,
                'quantity' => 1,
            ]],
        ]);
        $manager->handoff($actor, $context, $handed->id);

        $this->expectException(ValidationException::class);
        $manager->cancel($actor, $context, $handed->id);
    }

    public function test_value_variance_requires_explicit_three_way_match_review_before_finance_handoff(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'PINV-MATCH');
        [, $warehouse] = $this->stockTarget($store, 'PINV-MATCH-SKU');
        $receipt = $this->postedReceipt($actor, $context, $warehouse, 'PINV-MATCH-SUP', 'PINV-MATCH-SKU', 2);
        $receiptLine = $receipt->lines()->firstOrFail();

        $manager = app(PurchaseInvoiceHandoffManager::class);
        $invoice = $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
            'supplier_invoice_number' => 'SUP-INV-MATCH',
            'invoice_date' => '2026-10-16',
            'lines' => [[
                'goods_receipt_line_id' => $receiptLine->id,
                'quantity' => 2,
                'unit_price' => 1.500,
            ]],
        ]);

        $this->assertDatabaseHas('enterprise_procurement_match_reviews', [
            'purchase_invoice_id' => $invoice->id,
            'status' => 'pending',
            'quantity_variance' => 0,
            'value_variance' => 0.5,
        ]);

        try {
            $manager->handoff($actor, $context, $invoice->id);
            $this->fail('A value mismatch must block Finance handoff until explicitly reviewed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('three_way_match', $exception->errors());
        }

        app(ProcurementCompletionManager::class)->reviewMatch(
            $actor,
            $context,
            $invoice->id,
            ['decision' => 'approve', 'note' => 'Supplier invoice price accepted.'],
        );

        $handedOff = $manager->handoff($actor, $context, $invoice->id);
        $this->assertSame('handed_off', $handedOff->status);
        $this->assertDatabaseHas('enterprise_procurement_match_reviews', [
            'purchase_invoice_id' => $invoice->id,
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.procurement.three_way_match.reviewed',
        ]);
    }

    public function test_purchase_invoice_migration_is_reversible(): void
    {
        $migration = require database_path(
            'migrations/2026_10_16_080500_create_enterprise_purchase_invoice_handoff_foundation.php',
        );

        $migration->down();
        $this->assertFalse(Schema::hasTable('enterprise_purchase_invoice_lines'));
        $this->assertFalse(Schema::hasTable('enterprise_purchase_invoices'));

        $migration->up();
        $this->assertTrue(Schema::hasColumns('enterprise_purchase_invoices', [
            'goods_receipt_id',
            'supplier_invoice_number',
            'invoice_date',
            'due_date',
            'total_amount',
            'status',
            'outbox_event_id',
        ]));
        $this->assertTrue(Schema::hasColumns('enterprise_purchase_invoice_lines', [
            'goods_receipt_line_id',
            'invoice_quantity',
            'unit_price',
            'line_total',
        ]));
    }

    private function enableProcurement(): void
    {
        $lifecycle = app(ModuleLifecycleManager::class);
        $lifecycle->transition('procurement', ModuleState::ENABLING);
        $lifecycle->transition('procurement', ModuleState::ENABLED);
    }

    private function postedReceipt(
        User $actor,
        ResolvedTenantContext $context,
        Warehouse $warehouse,
        string $supplierCode,
        string $sku,
        float $quantity,
    ): EnterpriseGoodsReceipt {
        $supplier = app(SupplierDirectory::class)->create($actor, $context, [
            'scope_type' => 'store',
            'code' => $supplierCode,
            'name_en' => $supplierCode,
        ]);

        $requests = app(PurchaseRequestManager::class);
        $request = $requests->create($actor, $context, [
            'supplier_id' => $supplier->id,
            'currency_code' => 'KWD',
            'reason' => 'Purchase invoice source receipt',
            'lines' => [[
                'item_code' => $sku,
                'description' => 'Stock item '.$sku,
                'quantity' => $quantity,
                'unit' => 'each',
                'estimated_unit_cost' => 1.250,
            ]],
        ]);
        $requests->submit($actor, $context, $request->id);
        $requests->approve($actor, $context, $request->id);

        $orders = app(PurchaseOrderManager::class);
        $order = $orders->createFromApprovedRequest($actor, $context, $request->id);
        $order = $orders->issue($actor, $context, $order->id);

        $receipts = app(GoodsReceiptManager::class);
        $receipt = $receipts->createFromIssuedOrder($actor, $context, $order->id, [
            'lines' => [[
                'purchase_order_line_id' => $order->lines()->firstOrFail()->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => $quantity,
            ]],
        ]);

        return $receipts->post($actor, $context, $receipt->id);
    }

    /** @return array{Product,Warehouse} */
    private function stockTarget(Store $store, string $sku): array
    {
        $catalog = Catalog::query()->create([
            'store_id' => $store->id,
            'channel' => 'b2c',
            'code' => 'pinv-'.strtolower($sku),
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
            'name' => $sku.' Invoice',
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
            'procurement.purchase_returns.view',
            'procurement.purchase_returns.manage',
            'procurement.purchase_returns.post',
            'procurement.purchase_invoices.view',
            'procurement.purchase_invoices.manage',
            'procurement.purchase_invoices.handoff',
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
