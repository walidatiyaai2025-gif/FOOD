<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\EnterpriseModuleAdministration;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 P111: a global procurement role does not override an explicit store
 * entitlement denial, and restoring the entitlement does not bypass SoD.
 */
final class V2ErpEntitlementCheckerBoundaryTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_store_entitlement_revocation_blocks_existing_maker_and_checker_http_workflows_in_both_locales(): void
    {
        $store = $this->wholesaleStore();
        $admin = $this->actor('SUPER_ADMIN');
        $buyer = $this->actor('PROCUREMENT_BUYER');
        $checker = $this->actor('PROCUREMENT_APPROVER');

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $admin);
        $entitlements = app(ModuleEntitlementManager::class);
        $entitlements->set('procurement', EntitlementScope::platform(), true, null, $admin);
        $storeScope = new EntitlementScope(EntitlementScope::STORE, $store->id);

        $this->actingAs($buyer)->post(route('admin.erp.procurement.requests.store'), [
            'store_id' => $store->id,
            'currency_code' => 'KWD',
            'reason' => 'P111 entitlement and independent checker',
            'item_code' => 'P111-PROC-SKU',
            'description' => 'Store entitlement revocation acceptance',
            'quantity' => 2,
            'unit' => 'case',
            'estimated_unit_cost' => '5.000',
        ])->assertRedirect();

        $requestId = (int) DB::table('enterprise_purchase_requests')
            ->where('reason', 'P111 entitlement and independent checker')
            ->value('id');
        $this->assertGreaterThan(0, $requestId);

        $this->actingAs($buyer)->post(route('admin.erp.procurement.requests.submit', $requestId), [
            'store_id' => $store->id,
        ])->assertRedirect();
        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $requestId,
            'status' => 'submitted',
            'submitted_by' => $buyer->id,
            'decided_by' => null,
        ]);

        // A store-specific DENY takes precedence over the platform grant,
        // including for a global checker and a platform Super Admin.
        $entitlements->set('procurement', $storeScope, false, null, $admin);
        $this->assertDatabaseHas('enterprise_module_entitlements', [
            'module_code' => 'procurement',
            'scope_key' => $storeScope->key(),
            'is_enabled' => false,
        ]);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $checker->forceFill(['locale' => $locale])->save();
            $admin->forceFill(['locale' => $locale])->save();

            $this->actingAs($checker->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $store->id,
                'tab' => 'requests',
            ], false))->assertForbidden();

            $this->actingAs($checker->fresh())->post(route('admin.erp.procurement.requests.decision', $requestId), [
                'store_id' => $store->id,
                'decision' => 'approve',
            ])->assertForbidden();

            $this->actingAs($admin->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $store->id,
                'tab' => 'requests',
            ], false))->assertForbidden();

            $this->actingAs($buyer->fresh())->post(route('admin.erp.procurement.requests.submit', $requestId), [
                'store_id' => $store->id,
            ])->assertForbidden();
        }

        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $requestId,
            'status' => 'submitted',
            'decided_by' => null,
        ]);
        $this->assertSame(0, DB::table('audit_logs')
            ->where('event', 'enterprise.purchase_request.approved')
            ->where('auditable_id', $requestId)
            ->count());

        // Restoring entitlement only restores route eligibility. Even a
        // maker who later receives the checker role cannot approve their own
        // previously submitted request.
        $entitlements->set('procurement', $storeScope, true, null, $admin);
        $buyer->roles()->attach(Role::query()->where('code', 'PROCUREMENT_APPROVER')->firstOrFail());
        $this->assertTrue($buyer->fresh()->hasPermission('procurement.purchase_requests.approve', $store->id));

        $this->actingAs($buyer->fresh())->post(route('admin.erp.procurement.requests.decision', $requestId), [
            'store_id' => $store->id,
            'decision' => 'approve',
        ])->assertSessionHasErrors('decision');

        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $requestId,
            'status' => 'submitted',
            'decided_by' => null,
        ]);

        $this->actingAs($checker->fresh())->post(route('admin.erp.procurement.requests.decision', $requestId), [
            'store_id' => $store->id,
            'decision' => 'approve',
            'note' => 'Independent P111 checker',
        ])->assertRedirect();

        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $requestId,
            'status' => 'approved',
            'decided_by' => $checker->id,
        ]);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('event', 'enterprise.purchase_request.approved')
            ->where('auditable_id', $requestId)
            ->count());
    }

    public function test_explicit_store_deny_preserves_independent_sibling_b2b_entitlement(): void
    {
        $primary = $this->wholesaleStore();
        $sibling = Store::query()->create([
            'store_type_id' => $primary->store_type_id,
            'code' => 'P111-B2B-SIBLING',
            'name' => 'P111 B2B sibling',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($sibling);

        $admin = $this->actor('SUPER_ADMIN');
        $buyer = $this->actor('PROCUREMENT_BUYER');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $admin);

        $entitlements = app(ModuleEntitlementManager::class);
        $entitlements->set('procurement', EntitlementScope::platform(), true, null, $admin);
        $entitlements->set(
            'procurement',
            new EntitlementScope(EntitlementScope::STORE, $primary->id),
            false,
            null,
            $admin,
        );

        foreach (['ar', 'en'] as $locale) {
            $buyer->forceFill(['locale' => $locale])->save();

            $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $primary->id,
                'tab' => 'requests',
            ]))->assertForbidden();

            $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $sibling->id,
                'tab' => 'requests',
            ]))->assertOk();
        }

        $request = [
            'currency_code' => 'KWD',
            'reason' => 'P111 independent B2B store scope',
            'item_code' => 'P111-SCOPE-SKU',
            'description' => 'Cross-store entitlement enforcement',
            'quantity' => 1,
            'unit' => 'case',
            'estimated_unit_cost' => '1.000',
        ];

        $this->actingAs($buyer->fresh())->post(route('admin.erp.procurement.requests.store'), [
            ...$request,
            'store_id' => $primary->id,
        ])->assertForbidden();

        $this->assertDatabaseMissing('enterprise_purchase_requests', [
            'reason' => $request['reason'],
        ]);

        $this->actingAs($buyer->fresh())->post(route('admin.erp.procurement.requests.store'), [
            ...$request,
            'store_id' => $sibling->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'store_id' => $sibling->id,
            'reason' => $request['reason'],
        ]);
    }

    private function wholesaleStore(): Store
    {
        $store = Store::query()->findOrFail(app(WholesalePrincipal::class)->storeId());
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

        return $user->fresh();
    }
}
