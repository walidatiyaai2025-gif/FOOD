<?php

namespace Tests\Feature;

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
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * V2 T122R9: a Procurement Buyer is a wholesale maker, never an approver,
 * purchase-order issuer, inventory poster or cross-channel administrator.
 */
final class V2ProcurementBuyerAuthorityTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const GRANTS = [
        'procurement.suppliers.view',
        'procurement.suppliers.manage',
        'procurement.purchase_requests.view',
        'procurement.purchase_requests.manage',
        'procurement.purchase_orders.view',
        'procurement.purchase_orders.manage',
        'procurement.goods_receipts.view',
        'procurement.purchase_invoices.view',
        'procurement.supplier_statements.view',
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

    public function test_builtin_buyer_is_bilingual_and_has_exact_maker_permissions(): void
    {
        $catalog = app(BuiltInJobRoleCatalog::class);
        $definition = $catalog->definition('PROCUREMENT_BUYER');

        $this->assertIsArray($definition);
        $this->assertSame('procurement', $definition['domain']);
        $this->assertSame('global', $definition['scope']);
        $this->assertSame('tenant', $definition['business_scope']);
        $this->assertSame(['procurement'], $definition['required_modules']);
        $this->assertEqualsCanonicalizing(self::GRANTS, $definition['permissions']);

        $role = Role::query()->where('code', 'PROCUREMENT_BUYER')->firstOrFail();
        $this->assertTrue((bool) $role->is_system);
        $this->assertEqualsCanonicalizing(self::GRANTS, $role->permissions()->pluck('code')->all());

        foreach (['ar' => 'مسؤول مشتريات / مشتري', 'en' => 'Procurement Buyer'] as $locale => $name) {
            app()->setLocale($locale);
            $presentation = $catalog->presentation('PROCUREMENT_BUYER');

            $this->assertSame($name, $presentation['name']);
            $this->assertNotEmpty($presentation['description']);
            $this->assertNotEmpty($presentation['access_boundaries']);
        }
    }

    public function test_buyer_requires_enabled_procurement_and_discovers_authorized_bilingual_workbench(): void
    {
        $store = $this->wholesaleStore();
        $buyer = $this->buyer();
        $catalog = app(BuiltInJobRoleCatalog::class);

        $this->assertFalse($catalog->isAvailable('PROCUREMENT_BUYER'));
        $this->assertFalse($buyer->hasPermission('procurement.suppliers.view'));

        $disabled = $this->actingAs($buyer)
            ->get(route('admin.erp.procurement.index', ['store_id' => $store->id], false));
        $this->assertContains($disabled->status(), [403, 404]);

        $this->enableProcurement();
        $this->assertTrue($catalog->isAvailable('PROCUREMENT_BUYER'));
        $this->assertTrue($buyer->fresh()->hasPermission('procurement.suppliers.view', $store->id));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $buyer->forceFill(['locale' => $locale])->save();
            app()->forgetInstance(AdminNavigation::class);

            $items = collect(app(AdminNavigation::class)->groupsFor($buyer->fresh()))
                ->flatMap(static fn (array $group): array => $group['children'])
                ->keyBy('key');

            $this->assertTrue($items->has('erp_procurement'), $locale.' missing procurement entry');
            $this->assertSame('admin.erp.procurement.index', $items['erp_procurement']['route']);
            $this->assertTrue(Route::has($items['erp_procurement']['route']));
            $this->assertFalse($items->has('erp_finance'));
            $this->assertFalse($items->has('treasury_master'));
            $this->assertFalse($items->has('sidebar_role_access'));

            $this->actingAs($buyer->fresh())
                ->get(route('admin.erp.procurement.index', ['store_id' => $store->id, 'tab' => 'requests'], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            $this->actingAs($buyer->fresh())
                ->get(route('admin.erp.procurement.index', ['store_id' => $store->id, 'tab' => 'returns'], false))
                ->assertForbidden();
        }
    }

    public function test_buyer_creates_and_submits_real_wholesale_request_but_cannot_approve_it(): void
    {
        $store = $this->wholesaleStore();
        $this->enableProcurement();
        $buyer = $this->buyer();
        $auditBefore = DB::table('audit_logs')->count();

        $this->actingAs($buyer)->post(route('admin.erp.procurement.suppliers.store'), [
            'store_id' => $store->id,
            'scope_type' => 'store',
            'code' => 'V2-P2689-SUP',
            'name_en' => 'Buyer scope supplier',
            'name_ar' => 'مورد نطاق المشتري',
            'payment_terms_days' => 30,
        ])->assertRedirect();

        $supplierId = (int) DB::table('enterprise_suppliers')->where('code', 'V2-P2689-SUP')->value('id');
        $this->assertGreaterThan(0, $supplierId);

        $this->actingAs($buyer)->post(route('admin.erp.procurement.requests.store'), [
            'store_id' => $store->id,
            'supplier_id' => $supplierId,
            'currency_code' => 'KWD',
            'needed_by' => now()->addDays(5)->toDateString(),
            'reason' => 'V2 2689 maker/checker HTTP proof',
            'item_code' => 'V2-P2689-LINE',
            'description' => 'Wholesale procurement line',
            'quantity' => 2,
            'unit' => 'case',
            'estimated_unit_cost' => 4.250,
        ])->assertRedirect();

        $requestId = (int) DB::table('enterprise_purchase_requests')
            ->where('reason', 'V2 2689 maker/checker HTTP proof')->value('id');
        $this->assertGreaterThan(0, $requestId);
        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $requestId,
            'store_id' => $store->id,
            'created_by' => $buyer->id,
            'status' => 'draft',
        ]);

        $this->actingAs($buyer)->post(
            route('admin.erp.procurement.requests.submit', $requestId),
            ['store_id' => $store->id],
        )->assertRedirect();

        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $requestId,
            'status' => 'submitted',
            'submitted_by' => $buyer->id,
        ]);

        $this->actingAs($buyer)->post(
            route('admin.erp.procurement.requests.decision', $requestId),
            ['store_id' => $store->id, 'decision' => 'approve', 'note' => 'unauthorized buyer approval'],
        )->assertForbidden();

        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $requestId,
            'status' => 'submitted',
            'decided_by' => null,
        ]);
        $this->assertGreaterThan($auditBefore, DB::table('audit_logs')->count());
        foreach ([
            'procurement.purchase_requests.approve',
            'procurement.purchase_orders.issue',
            'procurement.goods_receipts.post',
            'procurement.purchase_invoices.handoff',
            'procurement.purchase_returns.post',
            'finance.post',
            'finance.approve',
            'orders.dispatch',
            'roles.manage',
        ] as $forbidden) {
            $this->assertFalse($buyer->fresh()->hasPermission($forbidden), $forbidden);
        }
    }

    public function test_buyer_denies_retail_context_unrelated_pages_and_explicit_global_deny(): void
    {
        $wholesale = $this->wholesaleStore();
        $this->enableProcurement();
        $buyer = $this->buyer();
        $retailType = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $retail = Store::query()->create([
            'store_type_id' => $retailType,
            'code' => 'V2-P2689-RETAIL',
            'name' => 'Isolated Retail',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($retail);

        $this->assertFalse($buyer->hasPermission('procurement.suppliers.view', $retail->id));
        $this->actingAs($buyer)
            ->get(route('admin.erp.procurement.index', ['store_id' => $retail->id], false))
            ->assertForbidden();
        $this->actingAs($buyer)
            ->get(route('admin.security.index', [], false))
            ->assertForbidden();

        $permission = Permission::query()->where('code', 'procurement.suppliers.view')->firstOrFail();
        DB::table('user_permission_overrides')->insert([
            'user_id' => $buyer->id,
            'permission_id' => $permission->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $buyer->forceFill(['has_permission_overrides' => true])->save();

        $this->assertFalse($buyer->fresh()->hasPermission('procurement.suppliers.view', $wholesale->id));
        $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
            'store_id' => $wholesale->id,
            'tab' => 'suppliers',
        ], false))->assertForbidden();

        $inactive = $this->buyer();
        $inactive->forceFill(['is_active' => false])->save();
        $this->assertFalse($inactive->fresh()->hasPermission('procurement.suppliers.view'));
        $this->actingAs($inactive->fresh())
            ->get(route('admin.erp.procurement.index', ['store_id' => $wholesale->id], false))
            ->assertForbidden();
    }

    private function buyer(): User
    {
        $buyer = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $buyer->roles()->attach(Role::query()->where('code', 'PROCUREMENT_BUYER')->firstOrFail());

        return $buyer->fresh();
    }

    private function wholesaleStore(): Store
    {
        $store = Store::query()->findOrFail(app(WholesalePrincipal::class)->storeId());
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    private function enableProcurement(): void
    {
        $lifecycle = app(ModuleLifecycleManager::class);
        $lifecycle->transition('procurement', ModuleState::ENABLING);
        $lifecycle->transition('procurement', ModuleState::ENABLED);
    }
}
