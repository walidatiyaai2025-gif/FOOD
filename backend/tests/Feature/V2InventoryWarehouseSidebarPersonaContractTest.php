<?php

namespace Tests\Feature;

use App\Enterprise\Modules\ModuleRegistry;
use App\Enums\StoreBusinessProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Enterprise\Concerns\EnablesEnterpriseWms;
use Tests\Support\SyntheticErpWorkflowEvidence;
use Tests\TestCase;

/**
 * T353R4: inventory/warehouse persona links are projected from real
 * permissions, channel scope and ERP readiness, not another role's UI profile.
 */
final class V2InventoryWarehouseSidebarPersonaContractTest extends TestCase
{
    use EnablesEnterpriseWms;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Test-only ERP workflow evidence; never a live certificate.
        SyntheticErpWorkflowEvidence::install();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_wholesale_inventory_role_reaches_inventory_from_sidebar_in_both_locales(): void
    {
        app(WholesalePrincipal::class)->warehouseId();
        $actor = $this->globalRoleUser('INVENTORY');

        $this->assertTrue($actor->hasPermission('inventory.view'));
        $this->assertFalse($actor->hasPermission('orders.view'));
        $this->assertFalse($actor->hasPermission('finance.view'));
        $this->assertFalse($actor->hasPermission('reports.view'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $items = $this->visibleItems($actor->fresh());
            $this->assertArrayHasKey('b2b_inventory', $items, "Inventory link missing in {$locale}.");
            $this->assertSame('admin.b2b.module', $items['b2b_inventory']['route']);
            $this->assertSame(['module' => 'inventory'], $items['b2b_inventory']['params']);
            $this->assertArrayHasKey('b2b_dashboard', $items);

            foreach (['order_operations', 'treasury_master', 'reports_center', 'sidebar_role_access'] as $forbidden) {
                $this->assertArrayNotHasKey($forbidden, $items, "Inventory role leaked {$forbidden}.");
            }

            $this->actingAs($actor->fresh())
                ->get(route('admin.b2b.module', ['module' => 'inventory'], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);
        }
    }

    public function test_repaired_inventory_link_preserves_existing_compact_admin_menu(): void
    {
        app(WholesalePrincipal::class)->warehouseId();

        foreach (['B2B_ADMIN', 'SUPER_ADMIN'] as $roleCode) {
            $actor = $this->globalRoleUser($roleCode);

            $this->assertTrue($actor->hasPermission('inventory.view'));
            $items = $this->visibleItems($actor);
            $this->assertArrayNotHasKey(
                'b2b_inventory',
                $items,
                "The established compact {$roleCode} menu must not be changed by the specialist repair.",
            );

            // The hidden link does not revoke independently authorized HTTP access.
            $this->actingAs($actor)
                ->get(route('admin.b2b.module', ['module' => 'inventory'], false))
                ->assertOk();
        }
    }

    public function test_uncertified_wms_never_appears_or_accepts_direct_operational_requests_in_both_locales(): void
    {
        app(WholesalePrincipal::class)->warehouseId();
        config()->set('enterprise_module_readiness.certified_versions', []);
        $actor = $this->globalRoleUser('INVENTORY');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $items = $this->visibleItems($actor->fresh());
            $this->assertArrayHasKey('b2b_inventory', $items);
            $this->assertArrayNotHasKey('erp_wms', $items, "Uncertified WMS leaked to {$locale} Sidebar.");
            $this->assertTrue($actor->hasPermission('inventory.view'));

            // Navigation hiding is not an access control. The real ERP middleware
            // must reject deep links for every WMS persona tab, in either locale.
            foreach (['overview', 'transfers', 'counts', 'reports'] as $tab) {
                $this->actingAs($actor->fresh())
                    ->get(route('admin.erp.wms.index', ['tab' => $tab], false))
                    ->assertForbidden();
            }

            // A crafted POST must not reach an uncertified count or transfer
            // handler, regardless of its validation payload.
            $this->actingAs($actor->fresh())
                ->post(route('admin.erp.wms.counts.store', [], false), [])
                ->assertForbidden();
            $this->actingAs($actor->fresh())
                ->post(route('admin.erp.wms.transfers.store', [], false), [])
                ->assertForbidden();

            // The separately authorized legacy B2B inventory remains usable.
            $this->actingAs($actor->fresh())
                ->get(route('admin.b2b.module', ['module' => 'inventory'], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);
        }
    }

    public function test_retail_inventory_store_scope_does_not_grant_wholesale_or_another_store(): void
    {
        $ownStore = $this->retailStore('P2713-RETAIL-OWN');
        $foreignStore = $this->retailStore('P2713-RETAIL-FOREIGN');
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $role = Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail();

        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $ownStore,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $actor = $actor->fresh();
        $this->assertTrue($actor->hasPermission('inventory.view', $ownStore));
        $this->assertFalse($actor->hasPermission('inventory.view', $foreignStore));

        $items = $this->visibleItems($actor);
        $this->assertArrayHasKey('b2c_inventory', $items);
        $this->assertArrayNotHasKey('b2b_inventory', $items);

        $this->actingAs($actor)
            ->get(route('admin.b2c.module', ['module' => 'inventory', 'store_id' => $ownStore], false))
            ->assertOk();

        $foreign = $this->actingAs($actor)->get(route('admin.b2c.module', [
            'module' => 'inventory',
            'store_id' => $foreignStore,
        ], false));
        $this->assertContains($foreign->getStatusCode(), [403, 404]);

        $this->actingAs($actor)
            ->get(route('admin.b2b.module', ['module' => 'inventory'], false))
            ->assertForbidden();

        $roleless = User::factory()->create(['is_active' => true, 'locale' => 'ar']);
        $this->assertArrayNotHasKey('b2b_inventory', $this->visibleItems($roleless));
        $this->actingAs($roleless)
            ->get(route('admin.b2b.module', ['module' => 'inventory'], false))
            ->assertForbidden();
    }

    public function test_certified_warehouse_personas_reach_real_wms_tabs_with_scoped_read_and_manage_authority(): void
    {
        $principal = app(WholesalePrincipal::class);
        $storeId = (int) $principal->storeId();
        $warehouseId = (int) $principal->warehouseId();
        $targetWarehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'P2713-TARGET',
            'name' => 'P2713 Transfer Target',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // A local fixture may attest this exact manifest version. This must
        // never change the default runtime certification in production.
        $this->enableEnterpriseWmsForStore($storeId);
        config()->set(
            'enterprise_module_readiness.certified_versions.inventory_wms',
            app(ModuleRegistry::class)->manifest('inventory_wms')->version,
        );

        foreach (['WAREHOUSE_MANAGER' => true, 'INVENTORY_CONTROLLER' => false] as $code => $mayManage) {
            $actor = $this->globalRoleUser($code);
            $this->assertTrue($actor->hasPermission('inventory.view'), $code);
            $this->assertSame($mayManage, $actor->hasPermission('inventory.manage'), $code);
            $this->assertFalse($actor->hasPermission('finance.manage'), $code);
            $this->assertFalse($actor->hasPermission('orders.dispatch'), $code);

            $items = $this->visibleItems($actor);
            $this->assertArrayHasKey('b2b_inventory', $items, $code);
            $this->assertArrayHasKey('erp_wms', $items, $code);
            $this->assertSame('admin.erp.wms.index', $items['erp_wms']['route']);
            $this->assertSame('warehouses', $items['erp_wms']['params']['tab']);
            $this->assertSame((string) $storeId, $items['erp_wms']['params']['store_id']);

            foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
                app()->setLocale($locale);
                $actor->forceFill(['locale' => $locale])->save();

                foreach (['warehouses', 'counts', 'transfers', 'reports'] as $tab) {
                    $this->actingAs($actor->fresh())
                        ->get(route('admin.erp.wms.index', [
                            'store_id' => $storeId,
                            'warehouse_id' => $warehouseId,
                            'tab' => $tab,
                        ], false))
                        ->assertOk()
                        ->assertSee('dir="'.$direction.'"', false)
                        ->assertSee(__('enterprise_wms.tabs.'.$tab));
                }
            }

            $countResponse = $this->actingAs($actor->fresh())->post(
                route('admin.erp.wms.counts.store', [], false),
                [
                    'store_id' => $storeId,
                    'warehouse_id' => $warehouseId,
                    'inventory_ids' => [999999999],
                    'request_key' => 'P2713-COUNT-'.$code,
                ],
            );
            if ($mayManage) {
                $countResponse->assertRedirect()->assertSessionHasErrors(['inventory_ids']);
            } else {
                // A scoped warehouse may be intentionally masked as 404.
                $this->assertContains($countResponse->getStatusCode(), [403, 404]);
            }

            $transferResponse = $this->actingAs($actor->fresh())->post(
                route('admin.erp.wms.transfers.store', [], false),
                [
                    'store_id' => $storeId,
                    'source_warehouse_id' => $warehouseId,
                    'target_warehouse_id' => $targetWarehouseId,
                    'product_id' => 999999999,
                    'quantity' => 2,
                    'request_key' => 'P2713-TRANSFER-'.$code,
                ],
            );
            if ($mayManage) {
                $transferResponse->assertRedirect()->assertSessionHasErrors(['lines']);
            } else {
                $this->assertContains($transferResponse->getStatusCode(), [403, 404]);
            }
        }

        $this->assertDatabaseCount('enterprise_inventory_counts', 0);
        $this->assertDatabaseCount('enterprise_warehouse_transfers', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_wms_navigation_selects_an_entitled_retail_store_not_a_dead_default_store(): void
    {
        $storeA = $this->retailStore('P2713-WMS-UNENTITLED');
        $storeB = $this->retailStore('P2713-WMS-ENTITLED');
        $this->enableEnterpriseWmsForStore($storeB);
        config()->set(
            'enterprise_module_readiness.certified_versions.inventory_wms',
            app(ModuleRegistry::class)->manifest('inventory_wms')->version,
        );

        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->grantPlatformErpAccess($actor);
        $roleId = Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail()->id;
        foreach ([$storeA, $storeB] as $storeId) {
            DB::table('user_store_roles')->insert([
                'user_id' => $actor->id,
                'store_id' => $storeId,
                'role_id' => $roleId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $items = $this->visibleItems($actor->fresh());
        $this->assertArrayHasKey('erp_wms', $items);
        $this->assertSame((string) $storeB, $items['erp_wms']['params']['store_id']);
        $this->assertSame('warehouses', $items['erp_wms']['params']['tab']);

        $this->actingAs($actor->fresh())
            ->get(route('admin.erp.wms.index', [
                'tab' => 'warehouses',
                'store_id' => $storeB,
            ], false))
            ->assertOk();
        $this->actingAs($actor->fresh())
            ->get(route('admin.erp.wms.index', [
                'tab' => 'warehouses',
                'store_id' => $storeA,
            ], false))
            ->assertNotFound();

        $this->assertDatabaseCount('stock_movements', 0);
    }

    private function globalRoleUser(string $code): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', $code)->firstOrFail());

        return $actor->fresh();
    }

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, array<string, mixed>> */
    private function visibleItems(User $actor): array
    {
        $items = [];

        foreach (app(AdminNavigation::class)->groupsFor($actor) as $group) {
            foreach ($group['children'] as $item) {
                $items[$item['key']] = $item;
                foreach ((array) ($item['children'] ?? []) as $child) {
                    $items[$child['key']] = $child;
                }
            }
        }

        return $items;
    }
}
