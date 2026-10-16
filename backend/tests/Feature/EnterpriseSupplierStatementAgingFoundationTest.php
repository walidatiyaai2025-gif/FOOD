<?php

namespace Tests\Feature;

use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Enterprise\Procurement\GoodsReceiptManager;
use App\Enterprise\Procurement\PurchaseInvoiceHandoffManager;
use App\Enterprise\Procurement\PurchaseOrderManager;
use App\Enterprise\Procurement\PurchaseRequestManager;
use App\Enterprise\Procurement\SupplierDirectory;
use App\Enterprise\Procurement\SupplierStatementAgingManager;
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
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

final class EnterpriseSupplierStatementAgingFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_procurement_performs_no_supplier_statement_query_work(): void
    {
        [$actor, , $context] = $this->scopedUser($this->allPermissions(), 'SAGE-OFF');

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower((string) $query->sql);
        });

        try {
            app(SupplierStatementAgingManager::class)->statement($actor, $context, 1);
            $this->fail('Disabled Procurement must reject supplier statement reads.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertFalse(
            collect($queries)->contains(
                static fn (string $sql): bool => str_contains($sql, 'enterprise_purchase_invoice'),
            ),
        );
    }

    public function test_aging_is_deterministic_by_currency_and_due_date_bucket(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'SAGE-A');
        [, $warehouse] = $this->stockTarget($store, 'SAGE-A-SKU');
        $receipt = $this->postedReceipt($actor, $context, $warehouse, 'SAGE-A-SUP', 'SAGE-A-SKU', 8);
        $lineId = $receipt->lines()->firstOrFail()->id;
        $invoices = app(PurchaseInvoiceHandoffManager::class);

        foreach ([
            ['SAGE-A-NOT-DUE', '2026-10-20'],
            ['SAGE-A-0-30', '2026-10-16'],
            ['SAGE-A-31-60', '2026-09-15'],
            ['SAGE-A-61-90', '2026-08-15'],
            ['SAGE-A-OVER-90', '2026-07-01'],
            ['SAGE-A-NO-DUE', null],
        ] as [$number, $dueDate]) {
            $invoice = $invoices->createFromPostedReceipt($actor, $context, $receipt->id, [
                'supplier_invoice_number' => $number,
                'invoice_date' => '2026-01-01',
                'due_date' => $dueDate,
                'lines' => [[
                    'goods_receipt_line_id' => $lineId,
                    'quantity' => 1,
                ]],
            ]);
            $invoices->handoff($actor, $context, $invoice->id);
        }

        $draft = $invoices->createFromPostedReceipt($actor, $context, $receipt->id, [
            'supplier_invoice_number' => 'SAGE-A-DRAFT',
            'invoice_date' => '2026-01-01',
            'due_date' => '2026-10-01',
            'lines' => [[
                'goods_receipt_line_id' => $lineId,
                'quantity' => 1,
            ]],
        ]);

        $aging = app(SupplierStatementAgingManager::class)->aging(
            $actor,
            $context,
            (int) $receipt->supplier_id,
            [
                'as_of' => '2026-10-16',
                'currency_code' => 'kwd',
            ],
        );

        $this->assertSame(6, $aging['invoice_count']);
        $this->assertSame('7.500', $aging['gross_exposure']);
        $this->assertSame('KWD', $aging['currency_code']);
        $this->assertSame('2026-10-16', $aging['as_of']);
        $this->assertSame('1.250', $aging['buckets']['not_due']);
        $this->assertSame('1.250', $aging['buckets']['due_0_30']);
        $this->assertSame('1.250', $aging['buckets']['due_31_60']);
        $this->assertSame('1.250', $aging['buckets']['due_61_90']);
        $this->assertSame('1.250', $aging['buckets']['due_over_90']);
        $this->assertSame('1.250', $aging['buckets']['no_due_date']);
        $this->assertDatabaseHas('enterprise_purchase_invoices', [
            'id' => $draft->id,
            'status' => 'draft',
        ]);
    }

    public function test_statement_is_bounded_date_filtered_and_cross_store_isolated(): void
    {
        $this->enableProcurement();
        [$actorA, $storeA, $contextA] = $this->scopedUser($this->allPermissions(), 'SAGE-B-A');
        [$actorB, , $contextB] = $this->scopedUser($this->allPermissions(), 'SAGE-B-B');
        [, $warehouse] = $this->stockTarget($storeA, 'SAGE-B-A-SKU');
        $receipt = $this->postedReceipt($actorA, $contextA, $warehouse, 'SAGE-B-SUP', 'SAGE-B-A-SKU', 2);
        $lineId = $receipt->lines()->firstOrFail()->id;

        $invoices = app(PurchaseInvoiceHandoffManager::class);
        $invoice = $invoices->createFromPostedReceipt($actorA, $contextA, $receipt->id, [
            'supplier_invoice_number' => 'SAGE-B-INV',
            'invoice_date' => '2026-10-01',
            'due_date' => '2026-10-31',
            'lines' => [[
                'goods_receipt_line_id' => $lineId,
                'quantity' => 1,
            ]],
        ]);
        $invoices->handoff($actorA, $contextA, $invoice->id);

        $manager = app(SupplierStatementAgingManager::class);
        $page = $manager->statement(
            $actorA,
            $contextA,
            (int) $receipt->supplier_id,
            [
                'from' => '2026-01-01',
                'to' => '2026-10-16',
                'currency_code' => 'kwd',
            ],
            500,
        );

        $this->assertSame(100, $page->perPage());
        $this->assertSame(1, $page->total());
        $this->assertSame('SAGE-B-INV', $page->items()[0]->supplier_invoice_number);

        try {
            $manager->statement(
                $actorA,
                $contextA,
                (int) $receipt->supplier_id,
                [
                    'from' => '2025-01-01',
                    'to' => '2026-10-16',
                ],
            );
            $this->fail('Supplier statement window must be bounded to 366 days.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('to', $exception->errors());
        }

        try {
            $manager->statement(
                $actorB,
                $contextB,
                (int) $receipt->supplier_id,
            );
            $this->fail('Cross-store supplier statement must be invisible.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }
    }

    public function test_aging_reads_do_not_require_finance_or_treasury_modules(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'SAGE-C');
        [, $warehouse] = $this->stockTarget($store, 'SAGE-C-SKU');
        $receipt = $this->postedReceipt($actor, $context, $warehouse, 'SAGE-C-SUP', 'SAGE-C-SKU', 1);
        $lineId = $receipt->lines()->firstOrFail()->id;

        $invoices = app(PurchaseInvoiceHandoffManager::class);
        $invoice = $invoices->createFromPostedReceipt($actor, $context, $receipt->id, [
            'supplier_invoice_number' => 'SAGE-C-INV',
            'invoice_date' => '2026-10-01',
            'due_date' => '2026-10-10',
            'lines' => [[
                'goods_receipt_line_id' => $lineId,
                'quantity' => 1,
            ]],
        ]);
        $invoices->handoff($actor, $context, $invoice->id);

        $aging = app(SupplierStatementAgingManager::class)->aging(
            $actor,
            $context,
            (int) $receipt->supplier_id,
            ['as_of' => '2026-10-16', 'currency_code' => 'KWD'],
        );

        $this->assertSame(1, $aging['invoice_count']);
        $this->assertSame('1.250', $aging['gross_exposure']);
        $this->assertSame('1.250', $aging['buckets']['due_0_30']);
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
            'reason' => 'Supplier statement source receipt',
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
            'code' => 'sage-'.strtolower($sku),
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
            'name' => $sku.' Aging',
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
            'procurement.purchase_invoices.view',
            'procurement.purchase_invoices.manage',
            'procurement.purchase_invoices.handoff',
            'procurement.supplier_statements.view',
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
