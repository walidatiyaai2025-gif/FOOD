<?php

namespace Tests\Feature;

use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Enterprise\Procurement\GoodsReceiptManager;
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

final class EnterprisePurchaseReturnFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_procurement_performs_no_purchase_return_query_work(): void
    {
        [$actor, , $context] = $this->scopedUser($this->allPermissions(), 'PRTN-OFF');

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower((string) $query->sql);
        });

        try {
            app(PurchaseReturnManager::class)->paginate($actor, $context);
            $this->fail('Disabled Procurement must reject purchase return reads.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertFalse(
            collect($queries)->contains(
                static fn (string $sql): bool => str_contains($sql, 'enterprise_purchase_return'),
            ),
        );
    }

    public function test_posted_purchase_return_reverses_inventory_once_and_is_retry_safe(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'PRTN-A');
        [$product, $warehouse] = $this->stockTarget($store, 'PRTN-A-SKU');
        $receipt = $this->postedReceipt($actor, $context, $warehouse, 'PRTN-A-SUP', 'PRTN-A-SKU', 5);
        $receiptLine = $receipt->lines()->firstOrFail();

        $manager = app(PurchaseReturnManager::class);
        $return = $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
            'lines' => [[
                'goods_receipt_line_id' => $receiptLine->id,
                'quantity' => 2,
            ]],
        ]);

        $posted = $manager->post($actor, $context, $return->id);
        $retry = $manager->post($actor, $context, $return->id);

        $this->assertSame('posted', $posted->status);
        $this->assertSame($posted->posted_at?->toISOString(), $retry->posted_at?->toISOString());
        $this->assertSame($receipt->receipt_number, $posted->source_receipt_number);
        $this->assertSame('5.000', $posted->lines[0]->received_quantity);
        $this->assertSame('2.000', $posted->lines[0]->returned_quantity);

        $inventory = DB::table('inventories')
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->first();

        $this->assertNotNull($inventory);
        $this->assertSame(3.0, (float) $inventory->quantity);
        $this->assertSame(
            1,
            DB::table('stock_movements')
                ->where('store_id', $store->id)
                ->where('type', 'purchase_return')
                ->where('quantity', -2)
                ->where('reference_type', 'enterprise_purchase_return_line')
                ->where('reference_id', $posted->lines[0]->id)
                ->count(),
        );
        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('event', 'enterprise.purchase_return.posted')
                ->where('auditable_id', $return->id)
                ->count(),
        );
    }

    public function test_partial_returns_cannot_exceed_posted_receipt_remainder(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'PRTN-B');
        [$product, $warehouse] = $this->stockTarget($store, 'PRTN-B-SKU');
        $receipt = $this->postedReceipt($actor, $context, $warehouse, 'PRTN-B-SUP', 'PRTN-B-SKU', 5);
        $lineId = $receipt->lines()->firstOrFail()->id;
        $manager = app(PurchaseReturnManager::class);

        $first = $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
            'lines' => [[
                'goods_receipt_line_id' => $lineId,
                'quantity' => 2,
            ]],
        ]);
        $manager->post($actor, $context, $first->id);

        try {
            $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
                'lines' => [[
                    'goods_receipt_line_id' => $lineId,
                    'quantity' => 4,
                ]],
            ]);
            $this->fail('Draft creation must reject quantities beyond the current received remainder.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines', $exception->errors());
        }

        $staleA = $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
            'lines' => [[
                'goods_receipt_line_id' => $lineId,
                'quantity' => 3,
            ]],
        ]);
        $staleB = $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
            'lines' => [[
                'goods_receipt_line_id' => $lineId,
                'quantity' => 3,
            ]],
        ]);

        $manager->post($actor, $context, $staleA->id);

        try {
            $manager->post($actor, $context, $staleB->id);
            $this->fail('Posting a stale draft must re-check the return remainder under the GRN lock.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines', $exception->errors());
        }

        $this->assertDatabaseHas('enterprise_purchase_returns', [
            'id' => $staleB->id,
            'status' => 'draft',
        ]);
        $this->assertSame(
            0.0,
            (float) DB::table('inventories')
                ->where('warehouse_id', $warehouse->id)
                ->where('product_id', $product->id)
                ->value('quantity'),
        );
        $this->assertSame(
            2,
            DB::table('stock_movements')
                ->where('store_id', $store->id)
                ->where('type', 'purchase_return')
                ->count(),
        );
    }

    public function test_return_cannot_reduce_inventory_below_reserved_quantity(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'PRTN-C');
        [$product, $warehouse] = $this->stockTarget($store, 'PRTN-C-SKU');
        $receipt = $this->postedReceipt($actor, $context, $warehouse, 'PRTN-C-SUP', 'PRTN-C-SKU', 5);
        $lineId = $receipt->lines()->firstOrFail()->id;

        DB::table('inventories')
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->update(['reserved_quantity' => 4]);

        $manager = app(PurchaseReturnManager::class);
        $return = $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
            'lines' => [[
                'goods_receipt_line_id' => $lineId,
                'quantity' => 2,
            ]],
        ]);

        try {
            $manager->post($actor, $context, $return->id);
            $this->fail('Return posting must preserve reserved stock.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('returned_quantity', $exception->errors());
        }

        $this->assertDatabaseHas('enterprise_purchase_returns', [
            'id' => $return->id,
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
            0,
            DB::table('stock_movements')
                ->where('type', 'purchase_return')
                ->where('reference_type', 'enterprise_purchase_return_line')
                ->count(),
        );
    }

    public function test_purchase_return_reads_are_bounded_and_cross_store_isolated(): void
    {
        $this->enableProcurement();
        [$actorA, $storeA, $contextA] = $this->scopedUser($this->allPermissions(), 'PRTN-D-A');
        [$actorB, , $contextB] = $this->scopedUser($this->allPermissions(), 'PRTN-D-B');
        [, $warehouseA] = $this->stockTarget($storeA, 'PRTN-D-A-SKU');
        $receipt = $this->postedReceipt($actorA, $contextA, $warehouseA, 'PRTN-D-SUP', 'PRTN-D-A-SKU', 1);
        $return = app(PurchaseReturnManager::class)->createFromPostedReceipt($actorA, $contextA, $receipt->id, [
            'lines' => [[
                'goods_receipt_line_id' => $receipt->lines()->firstOrFail()->id,
                'quantity' => 1,
            ]],
        ]);

        $manager = app(PurchaseReturnManager::class);
        $page = $manager->paginate($actorA, $contextA, ['status' => 'draft'], 500);

        $this->assertSame(100, $page->perPage());
        $this->assertSame(1, $page->total());
        $this->assertSame(0, $manager->paginate($actorB, $contextB)->total());

        try {
            $manager->detail($actorB, $contextB, $return->id);
            $this->fail('Cross-store purchase return detail must be invisible.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }
    }

    public function test_draft_cancel_is_retry_safe_and_posted_return_is_immutable(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'PRTN-E');
        [, $warehouse] = $this->stockTarget($store, 'PRTN-E-SKU');
        $receipt = $this->postedReceipt($actor, $context, $warehouse, 'PRTN-E-SUP', 'PRTN-E-SKU', 2);
        $lineId = $receipt->lines()->firstOrFail()->id;
        $manager = app(PurchaseReturnManager::class);

        $draft = $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
            'lines' => [[
                'goods_receipt_line_id' => $lineId,
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
                ->where('event', 'enterprise.purchase_return.cancelled')
                ->where('auditable_id', $draft->id)
                ->count(),
        );

        $postedReturn = $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
            'lines' => [[
                'goods_receipt_line_id' => $lineId,
                'quantity' => 1,
            ]],
        ]);
        $manager->post($actor, $context, $postedReturn->id);

        $this->expectException(ValidationException::class);
        $manager->cancel($actor, $context, $postedReturn->id);
    }

    public function test_purchase_return_migration_is_reversible(): void
    {
        $migration = require database_path(
            'migrations/2026_10_16_074500_create_enterprise_purchase_return_foundation.php',
        );

        $migration->down();
        $this->assertFalse(Schema::hasTable('enterprise_purchase_return_lines'));
        $this->assertFalse(Schema::hasTable('enterprise_purchase_returns'));

        $migration->up();
        $this->assertTrue(Schema::hasColumns('enterprise_purchase_returns', [
            'goods_receipt_id',
            'organization_id',
            'company_id',
            'store_id',
            'status',
            'posted_at',
        ]));
        $this->assertTrue(Schema::hasColumns('enterprise_purchase_return_lines', [
            'goods_receipt_line_id',
            'returned_quantity',
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
            'reason' => 'Purchase return source receipt',
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
            'code' => 'prtn-'.strtolower($sku),
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
            'name' => $sku.' Return',
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
