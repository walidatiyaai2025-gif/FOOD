<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Enterprise\Treasury\TreasuryPaymentService;
use App\Models\EnterpriseCashbox;
use App\Models\EnterpriseTreasuryPayment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ProcurementSecondApprover;
use Tests\TestCase;

final class V2ProcurementTreasurySodBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
        config()->set('enterprise_module_readiness.certified_versions.procurement', app(ModuleRegistry::class)->manifest('procurement')->version);
    }

    public function test_purchase_maker_with_approval_permission_cannot_self_approve_even_after_checker_approval(): void
    {
        $this->enableModule('procurement');
        $store = $this->store('P033-PROC-A');
        $maker = $this->procurementOperator($store);

        $this->actingAs($maker)->post(route('admin.erp.procurement.suppliers.store'), [
            'store_id' => $store->id,
            'scope_type' => 'store',
            'code' => 'P033-SUP-A',
            'name_en' => 'P033 Supplier',
            'name_ar' => 'مورد اختبار الفصل',
            'payment_terms_days' => 30,
        ])->assertRedirect();

        $supplierId = (int) DB::table('enterprise_suppliers')->where('code', 'P033-SUP-A')->value('id');
        $this->assertGreaterThan(0, $supplierId);

        $this->actingAs($maker)->post(route('admin.erp.procurement.requests.store'), [
            'store_id' => $store->id,
            'supplier_id' => $supplierId,
            'currency_code' => 'KWD',
            'needed_by' => now()->addDays(5)->toDateString(),
            'reason' => 'P033 maker checker negative',
            'item_code' => 'P033-SKU',
            'description' => 'P033 controlled purchase',
            'quantity' => 2,
            'unit' => 'case',
            'estimated_unit_cost' => 15.000,
        ])->assertRedirect();

        $id = (int) DB::table('enterprise_purchase_requests')->where('reason', 'P033 maker checker negative')->value('id');
        $this->assertGreaterThan(0, $id);

        $this->actingAs($maker)->post(route('admin.erp.procurement.requests.submit', $id), [
            'store_id' => $store->id,
        ])->assertRedirect();

        $this->actingAs($maker)->post(route('admin.erp.procurement.requests.decision', $id), [
            'store_id' => $store->id,
            'decision' => 'approve',
            'note' => 'Self approval attempt',
        ])->assertSessionHasErrors('decision');

        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $id,
            'status' => 'submitted',
            'decided_by' => null,
        ]);

        $otherStore = $this->store('P033-PROC-B');
        $outside = $this->procurementOperator($otherStore);
        $this->actingAs($outside)->post(route('admin.erp.procurement.requests.decision', $id), [
            'store_id' => $store->id,
            'decision' => 'approve',
        ])->assertForbidden();

        [$checker] = ProcurementSecondApprover::forSameStore(
            $maker,
            app(TenantContextResolver::class)->retail($maker, $store->id),
        );
        $this->grantPlatformErpAccess($checker);

        $this->actingAs($checker)->post(route('admin.erp.procurement.requests.decision', $id), [
            'store_id' => $store->id,
            'decision' => 'approve',
            'note' => 'Independent checker',
        ])->assertRedirect();

        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $id,
            'status' => 'approved',
            'decided_by' => $checker->id,
        ]);

        // An idempotent retry must not retroactively let the maker approve.
        $this->actingAs($maker)->post(route('admin.erp.procurement.requests.decision', $id), [
            'store_id' => $store->id,
            'decision' => 'approve',
        ])->assertSessionHasErrors('decision');
        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $id,
            'status' => 'approved',
            'decided_by' => $checker->id,
        ]);
    }

    public function test_treasury_maker_with_overlapping_role_grants_cannot_post_or_cross_stores(): void
    {
        $store = $this->store('P033-CASH-A');
        $maker = $this->financeOperator($store);
        // A second operational role must not weaken transaction-level maker/checker.
        $this->assignStoreRole($maker, $store, 'B2C_STORE_ADMIN');
        $makerContext = app(TenantContextResolver::class)->retail($maker, $store->id);

        $this->enableModule('treasury', $maker);
        $this->entitleTreasury($maker, $store);

        $scope = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertNotNull($scope);
        $cashbox = EnterpriseCashbox::query()->create([
            'company_id' => $scope['company_id'],
            'store_id' => $store->id,
            'code' => 'P033-CASHBOX-A',
            'name' => 'P033 Cashbox',
            'currency_code' => 'KWD',
            'operator_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'approval_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'is_active' => true,
        ]);

        $service = app(TreasuryPaymentService::class);
        $draft = $service->createDraft($maker, $makerContext, [
            'idempotency_key' => 'p033-treasury-draft',
            'target_type' => EnterpriseTreasuryPayment::TARGET_CASHBOX,
            'target_id' => $cashbox->id,
            'amount' => '45.250',
            'value_date' => '2026-11-10',
            'reference' => 'P033-TREASURY',
        ]);

        try {
            $service->post($maker, $makerContext, $draft->id);
            $this->fail('Even a multi-role treasury maker must not approve their own payment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('approver', $exception->errors());
        }

        $this->assertDatabaseHas('enterprise_treasury_payments', [
            'id' => $draft->id,
            'status' => EnterpriseTreasuryPayment::STATUS_DRAFT,
            'posted_by' => null,
        ]);

        $otherStore = $this->store('P033-CASH-B');
        $outsider = $this->financeOperator($otherStore);
        $this->entitleTreasury($outsider, $otherStore);
        $otherContext = app(TenantContextResolver::class)->retail($outsider, $otherStore->id);

        try {
            $service->post($outsider, $otherContext, $draft->id);
            $this->fail('Another store must not see the first store payment.');
        } catch (ModelNotFoundException $exception) {
            $this->assertNotNull($exception);
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $checker = $this->financeOperator($store);
        $this->entitleTreasury($checker, $store);
        $checkerContext = app(TenantContextResolver::class)->retail($checker, $store->id);
        $posted = $service->post($checker, $checkerContext, $draft->id);

        $this->assertSame(EnterpriseTreasuryPayment::STATUS_POSTED, $posted->status);
        $this->assertSame($checker->id, (int) $posted->posted_by);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.treasury.payment_posted',
            'auditable_id' => $draft->id,
        ]);
        $this->assertSame(
            1,
            DB::table('enterprise_workflow_requests')
                ->where('workflow_key', 'treasury_payment_posting')
                ->where('entity_id', (string) $draft->id)
                ->count(),
        );
    }

    private function enableModule(string $key, ?User $actor = null): void
    {
        $registry = app(ModuleRegistry::class);
        if ($registry->isEnabled($key)) {
            return;
        }

        $lifecycle = app(ModuleLifecycleManager::class);
        $lifecycle->transition($key, ModuleState::ENABLING, $actor);
        $lifecycle->transition($key, ModuleState::ENABLED, $actor);
    }

    private function store(string $code): Store
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        $store = Store::query()->create([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);

        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    private function procurementOperator(Store $store): User
    {
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();

        foreach ([
            'procurement.suppliers.view',
            'procurement.suppliers.manage',
            'procurement.purchase_requests.view',
            'procurement.purchase_requests.manage',
            'procurement.purchase_requests.approve',
        ] as $code) {
            $permission = Permission::query()->firstOrCreate(['code' => $code], ['name' => $code]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $actor = User::factory()->create(['locale' => 'ar', 'is_active' => true]);
        $this->grantPlatformErpAccess($actor);
        $this->assignStoreRole($actor, $store, $role->code);

        return $actor;
    }

    private function financeOperator(Store $store): User
    {
        $actor = User::factory()->create(['is_active' => true]);
        $this->assignStoreRole($actor, $store, 'RETAIL_FINANCE');

        return $actor;
    }

    private function assignStoreRole(User $actor, Store $store, string $roleCode): void
    {
        $role = Role::query()->where('code', $roleCode)->firstOrFail();
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function entitleTreasury(User $actor, Store $store): void
    {
        $scope = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertNotNull($scope);

        app(ModuleEntitlementManager::class)->set(
            TreasuryPaymentService::MODULE,
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            true,
            ['treasury.payments.read', 'treasury.payments.manage', 'treasury.payments.approve'],
            $actor,
        );
    }
}
