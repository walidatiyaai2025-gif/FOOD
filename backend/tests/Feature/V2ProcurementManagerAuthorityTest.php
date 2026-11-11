<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\BuiltInJobRoleCatalog;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 #2688 / T122R8: The Procurement Manager has the procurement
 * approval AND preparation bundle, but never a financial, security or
 * cross-tenant superuser bypass. Transaction-level maker/checker remains.
 */
final class V2ProcurementManagerAuthorityTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const GRANTS = [
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
        'procurement.supplier_statements.view',
        'reports.view',
        'reports.export',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
        config()->set(
            'enterprise_module_readiness.certified_versions.procurement',
            app(ModuleRegistry::class)->manifest('procurement')->version,
        );
    }

    public function test_builtin_manager_is_seeded_with_exact_procurement_grants_and_bilingual_responsibilities(): void
    {
        $catalog = app(BuiltInJobRoleCatalog::class);
        $role = $catalog->definition('PROCUREMENT_MANAGER');

        $this->assertIsArray($role);
        $this->assertSame('procurement', $role['domain']);
        $this->assertSame('global', $role['scope']);
        $this->assertSame('tenant', $role['business_scope']);
        $this->assertSame('manager', $role['level']);
        $this->assertSame(['procurement'], $role['required_modules']);
        $this->assertEqualsCanonicalizing(['inventory_wms', 'finance'], $role['optional_modules']);
        $this->assertEqualsCanonicalizing(self::GRANTS, $role['permissions']);

        $persisted = Role::query()->where('code', 'PROCUREMENT_MANAGER')->firstOrFail();
        $this->assertTrue((bool) $persisted->is_system);
        $this->assertEqualsCanonicalizing(self::GRANTS, $persisted->permissions()->pluck('code')->all());

        foreach (['ar' => 'مدير المشتريات', 'en' => 'Procurement Manager'] as $locale => $name) {
            app()->setLocale($locale);
            $presentation = $catalog->presentation('PROCUREMENT_MANAGER');
            $this->assertSame($name, $presentation['name']);
            $this->assertNotEmpty($presentation['description']);
            $this->assertNotEmpty($presentation['responsibilities']);
            $this->assertNotEmpty($presentation['access_boundaries']);
        }
    }

    public function test_procurement_module_and_tenant_boundaries_control_real_bilingual_http_navigation(): void
    {
        $wholesale = $this->wholesaleStore();
        $actor = $this->manager();

        $this->assertFalse(app(BuiltInJobRoleCatalog::class)->isAvailable('PROCUREMENT_MANAGER'));
        $this->assertFalse($actor->hasPermission('procurement.suppliers.view'));
        $this->actingAs($actor)
            ->get(route('admin.erp.procurement.index', ['store_id' => $wholesale->id], false))
            ->assertForbidden();

        $this->enableProcurement();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            app()->forgetInstance(AdminNavigation::class);

            $items = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
                ->flatMap(static fn (array $group): array => $group['children'])
                ->keyBy('key');

            $this->assertTrue($items->has('erp_procurement'));
            $this->assertSame('admin.erp.procurement.index', $items['erp_procurement']['route']);
            foreach (['erp_finance', 'erp_treasury', 'treasury_master', 'sidebar_role_access'] as $denied) {
                $this->assertFalse($items->has($denied), $denied);
            }

            $this->actingAs($actor->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $wholesale->id,
                'tab' => 'requests',
            ], false))->assertOk()->assertSee('dir="'.$direction.'"', false);
        }

        $retail = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'V2-P2688-RETAIL',
            'name' => 'Manager role cross-channel guard',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($retail);

        $this->assertFalse($actor->fresh()->hasPermission('procurement.suppliers.view', $retail->id));
        $this->actingAs($actor->fresh())->get(route('admin.erp.procurement.index', [
            'store_id' => $retail->id,
            'tab' => 'suppliers',
        ], false))->assertForbidden();

        foreach (['finance.manage', 'finance.post', 'finance.approve', 'orders.dispatch', 'roles.manage', 'security.approve', 'users.delete'] as $forbidden) {
            $this->assertFalse($actor->fresh()->hasPermission($forbidden), $forbidden);
        }
        $this->actingAs($actor->fresh())->get(route('admin.security.index', [], false))->assertForbidden();
    }

    public function test_manager_may_prepare_but_cannot_self_approve_the_same_request(): void
    {
        $wholesale = $this->wholesaleStore();
        $this->enableProcurement();
        $maker = $this->manager();
        $checker = $this->manager();
        $this->assertNotSame($maker->id, $checker->id);

        $this->actingAs($maker)->post(route('admin.erp.procurement.suppliers.store'), [
            'store_id' => $wholesale->id,
            'scope_type' => 'store',
            'code' => 'V2-P2688-SUPPLIER',
            'name_en' => 'Manager maker-checker supplier',
            'name_ar' => 'مورد فصل المدير',
            'payment_terms_days' => 30,
        ])->assertRedirect();

        $supplierId = (int) DB::table('enterprise_suppliers')->where('code', 'V2-P2688-SUPPLIER')->value('id');
        $this->assertGreaterThan(0, $supplierId);
        $this->actingAs($maker)->post(route('admin.erp.procurement.requests.store'), [
            'store_id' => $wholesale->id,
            'supplier_id' => $supplierId,
            'currency_code' => 'KWD',
            'needed_by' => now()->addDays(5)->toDateString(),
            'reason' => 'V2 2688 procurement manager independent review',
            'item_code' => 'V2-P2688-LINE',
            'description' => 'Manager purchase request',
            'quantity' => 2,
            'unit' => 'case',
            'estimated_unit_cost' => 6.250,
        ])->assertRedirect();

        $requestId = (int) DB::table('enterprise_purchase_requests')
            ->where('reason', 'V2 2688 procurement manager independent review')
            ->value('id');
        $this->assertGreaterThan(0, $requestId);
        $this->actingAs($maker)->post(
            route('admin.erp.procurement.requests.submit', $requestId),
            ['store_id' => $wholesale->id],
        )->assertRedirect();

        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $requestId,
            'created_by' => $maker->id,
            'status' => 'submitted',
        ]);

        $selfDecision = $this->actingAs($maker)->post(
            route('admin.erp.procurement.requests.decision', $requestId),
            ['store_id' => $wholesale->id, 'decision' => 'approve', 'note' => 'self-check attempt'],
        );
        $this->assertContains($selfDecision->status(), [403, 422], 'Manager may not approve own purchase request');
        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $requestId,
            'status' => 'submitted',
            'decided_by' => null,
        ]);

        $this->actingAs($checker)->post(
            route('admin.erp.procurement.requests.decision', $requestId),
            ['store_id' => $wholesale->id, 'decision' => 'approve', 'note' => 'independent manager checker'],
        )->assertRedirect();

        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $requestId,
            'status' => 'approved',
            'decided_by' => $checker->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.purchase_request.approved',
            'auditable_id' => $requestId,
        ]);
    }

    public function test_explicit_permission_deny_and_inactive_account_fail_closed_without_erasing_role_history(): void
    {
        $wholesale = $this->wholesaleStore();
        $this->enableProcurement();
        $actor = $this->manager();

        $permission = Permission::query()->where('code', 'procurement.suppliers.view')->firstOrFail();
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => $permission->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        $this->assertFalse($actor->fresh()->hasPermission('procurement.suppliers.view', $wholesale->id));
        $this->actingAs($actor->fresh())->get(route('admin.erp.procurement.index', [
            'store_id' => $wholesale->id,
            'tab' => 'suppliers',
        ], false))->assertForbidden();

        $actor->forceFill(['is_active' => false])->save();
        $this->actingAs($actor->fresh())->get(route('admin.erp.procurement.index', [
            'store_id' => $wholesale->id,
            'tab' => 'requests',
        ], false))->assertForbidden();

        $this->assertDatabaseHas('role_user', [
            'user_id' => $actor->id,
            'role_id' => Role::query()->where('code', 'PROCUREMENT_MANAGER')->firstOrFail()->id,
        ]);
    }

    private function manager(): User
    {
        $user = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', 'PROCUREMENT_MANAGER')->firstOrFail());

        return $user->fresh();
    }

    private function wholesaleStore(): Store
    {
        $store = Store::query()->findOrFail(app(WholesalePrincipal::class)->storeId());
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    private function enableProcurement(): void
    {
        app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::ENABLING);
        app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::ENABLED);

        app(ModuleEntitlementManager::class)->set(
            'procurement',
            EntitlementScope::platform(),
            true,
        );
    }
}
