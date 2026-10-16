<?php

namespace Tests\Feature;

use App\Enterprise\Organization\OrganizationHierarchyService;
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

final class EnterprisePurchaseRequestFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_lifecycle_is_scoped_audited_and_decisions_are_idempotent(): void
    {
        [$actor, $store, $context] = $this->scopedUser([
            'procurement.suppliers.view',
            'procurement.suppliers.manage',
            'procurement.purchase_requests.view',
            'procurement.purchase_requests.manage',
            'procurement.purchase_requests.approve',
        ], 'PR-A');

        $supplier = app(SupplierDirectory::class)->create($actor, $context, [
            'scope_type' => 'store',
            'code' => 'PR-SUP-1',
            'name_en' => 'Purchase Request Supplier',
        ]);

        $manager = app(PurchaseRequestManager::class);
        $request = $manager->create($actor, $context, [
            'supplier_id' => $supplier->id,
            'currency_code' => 'kwd',
            'needed_by' => now()->addDays(7)->toDateString(),
            'reason' => 'Replenish service stock',
            'organization_id' => 999999,
            'company_id' => 999999,
            'store_id' => 999999,
            'status' => 'approved',
            'lines' => [
                [
                    'item_code' => 'ITEM-1',
                    'description' => 'Cleaning material',
                    'quantity' => 4,
                    'unit' => 'case',
                    'estimated_unit_cost' => 12.500,
                ],
                [
                    'description' => 'Packaging',
                    'quantity' => 10,
                    'unit' => 'pack',
                ],
            ],
        ]);

        $this->assertSame('draft', $request->status);
        $this->assertSame('KWD', $request->currency_code);
        $this->assertSame($store->id, $request->store_id);
        $this->assertSame($context->enterpriseScope?->companyId, $request->company_id);
        $this->assertCount(2, $request->lines);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.purchase_request.created',
            'auditable_id' => $request->id,
        ]);

        $submitted = $manager->submit($actor, $context, $request->id);
        $this->assertSame('submitted', $submitted->status);
        $this->assertSame($actor->id, $submitted->submitted_by);

        $approved = $manager->approve($actor, $context, $request->id, 'Approved for purchase');
        $approvedAgain = $manager->approve($actor, $context, $request->id, 'Ignored retry note');
        $this->assertSame('approved', $approved->status);
        $this->assertSame($approved->decided_at?->toISOString(), $approvedAgain->decided_at?->toISOString());
        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('event', 'enterprise.purchase_request.approved')
                ->where('auditable_id', $request->id)
                ->count(),
        );

        $this->expectException(ValidationException::class);
        $manager->reject($actor, $context, $request->id, 'Conflicting retry');
    }

    public function test_request_queries_are_bounded_and_cross_store_access_is_denied(): void
    {
        [$actorA, , $contextA] = $this->scopedUser([
            'procurement.purchase_requests.view',
            'procurement.purchase_requests.manage',
            'procurement.purchase_requests.approve',
        ], 'PR-B-A');
        [$actorB, , $contextB] = $this->scopedUser([
            'procurement.purchase_requests.view',
            'procurement.purchase_requests.manage',
            'procurement.purchase_requests.approve',
        ], 'PR-B-B');

        $manager = app(PurchaseRequestManager::class);
        $request = $manager->create($actorA, $contextA, $this->requestPayload('Cross-store test'));

        for ($i = 1; $i <= 12; $i++) {
            $manager->create($actorA, $contextA, $this->requestPayload('Bounded '.$i));
        }

        $page = $manager->paginate($actorA, $contextA, ['status' => 'draft'], 500);
        $this->assertSame(100, $page->perPage());
        $this->assertSame(13, $page->total());
        $this->assertSame(0, $manager->paginate($actorB, $contextB)->total());

        $this->expectException(ModelNotFoundException::class);
        $manager->detail($actorB, $contextB, $request->id);
    }

    public function test_supplier_reference_must_be_visible_and_active_in_request_scope(): void
    {
        [$actorA, , $contextA] = $this->scopedUser([
            'procurement.suppliers.view',
            'procurement.suppliers.manage',
            'procurement.purchase_requests.view',
            'procurement.purchase_requests.manage',
        ], 'PR-C-A');
        [$actorB, , $contextB] = $this->scopedUser([
            'procurement.suppliers.view',
            'procurement.suppliers.manage',
        ], 'PR-C-B');

        $otherSupplier = app(SupplierDirectory::class)->create($actorB, $contextB, [
            'scope_type' => 'store',
            'code' => 'OTHER-STORE',
            'name_en' => 'Other Store Supplier',
        ]);

        $payload = $this->requestPayload('Supplier scope validation');
        $payload['supplier_id'] = $otherSupplier->id;

        $this->expectException(ModelNotFoundException::class);
        app(PurchaseRequestManager::class)->create($actorA, $contextA, $payload);
    }

    public function test_approve_requires_dedicated_permission(): void
    {
        [$actor, , $context] = $this->scopedUser([
            'procurement.purchase_requests.view',
            'procurement.purchase_requests.manage',
        ], 'PR-D');

        $manager = app(PurchaseRequestManager::class);
        $request = $manager->create($actor, $context, $this->requestPayload('Approval permission'));
        $manager->submit($actor, $context, $request->id);

        try {
            $manager->approve($actor, $context, $request->id);
            $this->fail('Approval without procurement.purchase_requests.approve must be denied.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    /** @return array<string, mixed> */
    private function requestPayload(string $reason): array
    {
        return [
            'currency_code' => 'KWD',
            'reason' => $reason,
            'lines' => [[
                'description' => 'Operational item',
                'quantity' => 1,
                'unit' => 'each',
                'estimated_unit_cost' => 1.250,
            ]],
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
