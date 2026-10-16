<?php

namespace Tests\Feature;

use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Enterprise\Procurement\PurchaseOrderManager;
use App\Enterprise\Procurement\PurchaseRequestManager;
use App\Enterprise\Procurement\SupplierDirectory;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\ResolvedTenantContext;
use App\Support\TenantContextResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class EnterprisePurchaseOrderFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_request_creates_one_scoped_snapshot_purchase_order(): void
    {
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'PO-A');
        $supplier = app(SupplierDirectory::class)->create($actor, $context, [
            'scope_type' => 'store',
            'code' => 'PO-SUP-1',
            'name_en' => 'Purchase Order Supplier',
            'name_ar' => 'مورد أمر الشراء',
        ]);

        $request = app(PurchaseRequestManager::class)->create($actor, $context, [
            'supplier_id' => $supplier->id,
            'currency_code' => 'KWD',
            'needed_by' => now()->addDays(5)->toDateString(),
            'reason' => 'Approved PO source',
            'lines' => [
                [
                    'item_code' => 'PO-ITEM-1',
                    'description' => 'Packaging material',
                    'quantity' => 4,
                    'unit' => 'case',
                    'estimated_unit_cost' => 12.500,
                ],
                [
                    'description' => 'Cleaning material',
                    'quantity' => 2,
                    'unit' => 'pack',
                ],
            ],
        ]);
        $requests = app(PurchaseRequestManager::class);
        $requests->submit($actor, $context, $request->id);
        $requests->approve($actor, $context, $request->id, 'Approved for PO');

        $orders = app(PurchaseOrderManager::class);
        $order = $orders->createFromApprovedRequest($actor, $context, $request->id);
        $retry = $orders->createFromApprovedRequest($actor, $context, $request->id);

        $this->assertSame($order->id, $retry->id);
        $this->assertSame('draft', $order->status);
        $this->assertSame($store->id, $order->store_id);
        $this->assertSame($request->request_number, $order->source_request_number);
        $this->assertSame('PO-SUP-1', $order->supplier_code);
        $this->assertSame('Purchase Order Supplier', $order->supplier_name_en);
        $this->assertSame('مورد أمر الشراء', $order->supplier_name_ar);
        $this->assertCount(2, $order->lines);
        $this->assertSame('12.500', $order->lines[0]->unit_price);
        $this->assertNull($order->lines[1]->unit_price);
        $this->assertDatabaseCount('enterprise_purchase_orders', 1);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.purchase_order.created',
            'auditable_id' => $order->id,
        ]);
        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('event', 'enterprise.purchase_order.created')
                ->where('auditable_id', $order->id)
                ->count(),
        );
    }

    public function test_purchase_order_requires_an_approved_request_with_supplier(): void
    {
        [$actor, , $context] = $this->scopedUser($this->allPermissions(), 'PO-B');
        $requests = app(PurchaseRequestManager::class);
        $request = $requests->create($actor, $context, [
            'currency_code' => 'KWD',
            'reason' => 'Missing supplier',
            'lines' => [[
                'description' => 'Operational item',
                'quantity' => 1,
                'unit' => 'each',
            ]],
        ]);

        try {
            app(PurchaseOrderManager::class)->createFromApprovedRequest($actor, $context, $request->id);
            $this->fail('A draft purchase request must not create a purchase order.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('purchase_request_id', $exception->errors());
        }

        $requests->submit($actor, $context, $request->id);
        $requests->approve($actor, $context, $request->id);

        $this->expectException(ValidationException::class);
        app(PurchaseOrderManager::class)->createFromApprovedRequest($actor, $context, $request->id);
    }

    public function test_issue_and_cancel_are_retry_safe_and_audited(): void
    {
        [$actor, , $context] = $this->scopedUser($this->allPermissions(), 'PO-C');
        $order = $this->approvedOrder($actor, $context, 'PO-C-SUP');

        $orders = app(PurchaseOrderManager::class);
        $issued = $orders->issue($actor, $context, $order->id);
        $issuedAgain = $orders->issue($actor, $context, $order->id);

        $this->assertSame('issued', $issued->status);
        $this->assertSame($issued->issued_at?->toISOString(), $issuedAgain->issued_at?->toISOString());
        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('event', 'enterprise.purchase_order.issued')
                ->where('auditable_id', $order->id)
                ->count(),
        );

        $cancelled = $orders->cancel($actor, $context, $order->id);
        $cancelledAgain = $orders->cancel($actor, $context, $order->id);

        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame(
            $cancelled->cancelled_at?->toISOString(),
            $cancelledAgain->cancelled_at?->toISOString(),
        );
        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('event', 'enterprise.purchase_order.cancelled')
                ->where('auditable_id', $order->id)
                ->count(),
        );
    }

    public function test_purchase_order_reads_are_bounded_and_cross_store_isolated(): void
    {
        [$actorA, , $contextA] = $this->scopedUser($this->allPermissions(), 'PO-D-A');
        [$actorB, , $contextB] = $this->scopedUser($this->allPermissions(), 'PO-D-B');
        $order = $this->approvedOrder($actorA, $contextA, 'PO-D-SUP');

        $orders = app(PurchaseOrderManager::class);
        $page = $orders->paginate($actorA, $contextA, ['status' => 'draft'], 500);

        $this->assertSame(100, $page->perPage());
        $this->assertSame(1, $page->total());
        $this->assertSame(0, $orders->paginate($actorB, $contextB)->total());

        $this->expectException(ModelNotFoundException::class);
        $orders->detail($actorB, $contextB, $order->id);
    }

    private function approvedOrder(
        User $actor,
        ResolvedTenantContext $context,
        string $supplierCode,
    ) {
        $supplier = app(SupplierDirectory::class)->create($actor, $context, [
            'scope_type' => 'store',
            'code' => $supplierCode,
            'name_en' => $supplierCode,
        ]);
        $requests = app(PurchaseRequestManager::class);
        $request = $requests->create($actor, $context, [
            'supplier_id' => $supplier->id,
            'currency_code' => 'KWD',
            'reason' => 'PO lifecycle',
            'lines' => [[
                'description' => 'Operational item',
                'quantity' => 1,
                'unit' => 'each',
                'estimated_unit_cost' => 1.250,
            ]],
        ]);
        $requests->submit($actor, $context, $request->id);
        $requests->approve($actor, $context, $request->id);

        return app(PurchaseOrderManager::class)->createFromApprovedRequest($actor, $context, $request->id);
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
