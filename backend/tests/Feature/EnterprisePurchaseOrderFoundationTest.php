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
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class EnterprisePurchaseOrderFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_request_creates_one_scoped_audited_purchase_order_and_issue_is_idempotent(): void
    {
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'PO-A');

        $supplier = app(SupplierDirectory::class)->create($actor, $context, [
            'scope_type' => 'store',
            'code' => 'PO-SUP-1',
            'name_en' => 'Purchase Order Supplier',
        ]);

        $requestManager = app(PurchaseRequestManager::class);
        $request = $requestManager->create($actor, $context, [
            'supplier_id' => $supplier->id,
            'currency_code' => 'kwd',
            'needed_by' => now()->addDays(5)->toDateString(),
            'lines' => [
                [
                    'item_code' => 'ITEM-PO-1',
                    'description' => 'Operational material',
                    'quantity' => 4,
                    'unit' => 'case',
                    'estimated_unit_cost' => 12.500,
                ],
                [
                    'description' => 'Packaging material',
                    'quantity' => 10,
                    'unit' => 'pack',
                ],
            ],
        ]);
        $requestManager->submit($actor, $context, $request->id);
        $requestManager->approve($actor, $context, $request->id, 'Approved for PO');

        $manager = app(PurchaseOrderManager::class);
        $order = $manager->createFromApprovedRequest($actor, $context, $request->id, 'Supplier order');
        $retry = $manager->createFromApprovedRequest($actor, $context, $request->id, 'Ignored retry notes');

        $this->assertSame($order->id, $retry->id);
        $this->assertSame('draft', $order->status);
        $this->assertSame('KWD', $order->currency_code);
        $this->assertSame($store->id, $order->store_id);
        $this->assertSame($request->id, $order->purchase_request_id);
        $this->assertSame($supplier->id, $order->supplier_id);
        $this->assertCount(2, $order->lines);
        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('event', 'enterprise.purchase_order.created')
                ->where('auditable_id', $order->id)
                ->count(),
        );

        $issued = $manager->issue($actor, $context, $order->id);
        $issuedAgain = $manager->issue($actor, $context, $order->id);

        $this->assertSame('issued', $issued->status);
        $this->assertSame($issued->issued_at?->toISOString(), $issuedAgain->issued_at?->toISOString());
        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('event', 'enterprise.purchase_order.issued')
                ->where('auditable_id', $order->id)
                ->count(),
        );
    }

    public function test_non_approved_request_and_missing_supplier_are_rejected(): void
    {
        [$actor, , $context] = $this->scopedUser($this->allPermissions(), 'PO-B');
        $manager = app(PurchaseRequestManager::class);

        $draft = $manager->create($actor, $context, [
            'currency_code' => 'KWD',
            'lines' => [$this->linePayload()],
        ]);

        try {
            app(PurchaseOrderManager::class)->createFromApprovedRequest($actor, $context, $draft->id);
            $this->fail('Draft purchase request must not create a purchase order.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('purchase_request_id', $exception->errors());
        }

        $manager->submit($actor, $context, $draft->id);
        $manager->approve($actor, $context, $draft->id);

        $this->expectException(ValidationException::class);
        app(PurchaseOrderManager::class)->createFromApprovedRequest($actor, $context, $draft->id);
    }

    public function test_purchase_order_reads_are_bounded_and_cross_store_access_is_denied(): void
    {
        [$actorA, , $contextA] = $this->scopedUser($this->allPermissions(), 'PO-C-A');
        [$actorB, , $contextB] = $this->scopedUser($this->allPermissions(), 'PO-C-B');

        $supplier = app(SupplierDirectory::class)->create($actorA, $contextA, [
            'scope_type' => 'store',
            'code' => 'PO-SUP-C',
            'name_en' => 'Scoped PO Supplier',
        ]);

        $requestManager = app(PurchaseRequestManager::class);
        $orderManager = app(PurchaseOrderManager::class);

        $request = $requestManager->create($actorA, $contextA, [
            'supplier_id' => $supplier->id,
            'currency_code' => 'KWD',
            'lines' => [$this->linePayload()],
        ]);
        $requestManager->submit($actorA, $contextA, $request->id);
        $requestManager->approve($actorA, $contextA, $request->id);
        $order = $orderManager->createFromApprovedRequest($actorA, $contextA, $request->id);

        $page = $orderManager->paginate($actorA, $contextA, ['status' => 'draft'], 500);
        $this->assertSame(100, $page->perPage());
        $this->assertSame(1, $page->total());
        $this->assertSame(0, $orderManager->paginate($actorB, $contextB)->total());

        $this->expectException(ModelNotFoundException::class);
        $orderManager->detail($actorB, $contextB, $order->id);
    }

    public function test_create_requires_dedicated_purchase_order_manage_permission(): void
    {
        [$actor, , $context] = $this->scopedUser([
            'procurement.suppliers.manage',
            'procurement.purchase_requests.manage',
            'procurement.purchase_requests.approve',
            'procurement.purchase_orders.view',
        ], 'PO-D');

        $supplier = app(SupplierDirectory::class)->create($actor, $context, [
            'scope_type' => 'store',
            'code' => 'PO-SUP-D',
            'name_en' => 'Permission Supplier',
        ]);

        $requestManager = app(PurchaseRequestManager::class);
        $request = $requestManager->create($actor, $context, [
            'supplier_id' => $supplier->id,
            'currency_code' => 'KWD',
            'lines' => [$this->linePayload()],
        ]);
        $requestManager->submit($actor, $context, $request->id);
        $requestManager->approve($actor, $context, $request->id);

        try {
            app(PurchaseOrderManager::class)->createFromApprovedRequest($actor, $context, $request->id);
            $this->fail('Purchase order creation without manage permission must be denied.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
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
        ];
    }

    /** @return array<string, mixed> */
    private function linePayload(): array
    {
        return [
            'description' => 'Purchase order item',
            'quantity' => 2,
            'unit' => 'each',
            'estimated_unit_cost' => 3.250,
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
