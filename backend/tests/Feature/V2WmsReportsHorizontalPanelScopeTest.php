<?php

namespace Tests\Feature;

use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Models\Role;
use App\Models\User;
use App\Models\UserStoreRole;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Enterprise\Concerns\EnablesEnterpriseWms;
use Tests\Support\SyntheticErpWorkflowEvidence;
use Tests\TestCase;

final class V2WmsReportsHorizontalPanelScopeTest extends TestCase
{
    use EnablesEnterpriseWms;
    use RefreshDatabase;

    private const PANELS = [
        'low_stock', 'stock_aging', 'count_variance', 'transfer_performance', 'demand_plan',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
        SyntheticErpWorkflowEvidence::install();
        config()->set(
            'enterprise_module_readiness.certified_versions.inventory_wms',
            app(ModuleRegistry::class)->manifest('inventory_wms')->version,
        );
    }

    public function test_five_authorized_reports_show_only_one_server_rendered_panel_in_both_locales(): void
    {
        $own = $this->fixture('V2-3966-OWN');
        $actor = $this->operator($own['store_id']);

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            foreach (self::PANELS as $panel) {
                $response = $this->actingAs($actor->fresh())->get($this->url($own, $panel))->assertOk()
                    ->assertViewHas('tab', 'reports')
                    ->assertSee('dir="'.$direction.'"', false)
                    ->assertSee('data-wms-reports', false)
                    ->assertSee(__('enterprise_wms.reports.'.$panel));

                $html = html_entity_decode($response->getContent(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $this->assertSame(2, substr_count($html, 'class="wms-tabs foodex-tabs"'));
                $this->assertStringContainsString('.wms-tabs{display:flex;flex-wrap:nowrap;', $html);
                $this->assertStringContainsString('min-height:44px', $html);
                $this->assertSame(1, preg_match(
                    '/<nav class="wms-tabs foodex-tabs"[^>]*data-wms-report-tabs[^>]*>(.*?)<\\/nav>/s',
                    $html,
                    $navigation,
                ));
                $this->assertSame(5, substr_count($navigation[1], 'data-wms-report-tab="'));
                $this->assertSame(1, substr_count($navigation[1], 'aria-current="page"'));
                $this->assertSame(1, substr_count($html, 'data-wms-report-panel="'));
                $this->assertStringContainsString('data-wms-report-panel="'.$panel.'"', $html);

                foreach (self::PANELS as $other) {
                    if ($panel !== $other) {
                        $this->assertStringNotContainsString('data-wms-report-panel="'.$other.'"', $html);
                    }
                    $this->assertSame(1, preg_match(
                        '/<a class="wms-tab foodex-tab [^"]*"[^>]*data-wms-report-tab="'.preg_quote($other, '/').'"[^>]*href="([^"]+)"/s',
                        $navigation[1],
                        $matches,
                    ));
                    parse_str((string) parse_url($matches[1], PHP_URL_QUERY), $query);
                    $this->assertSame('reports', $query['tab'] ?? null);
                    $this->assertSame($other, $query['report_panel'] ?? null);
                    $this->assertSame((string) $own['store_id'], $query['store_id'] ?? null);
                    $this->assertSame((string) $own['warehouse_id'], $query['warehouse_id'] ?? null);
                }
            }
        }
    }

    public function test_invalid_or_array_report_panel_falls_back_to_one_safe_panel_without_rendering_other_grid_data(): void
    {
        $own = $this->fixture('V2-3966-INVALID');
        $actor = $this->operator($own['store_id']);

        foreach ([null, 'outside', 'stock_aging,low_stock', ['stock_aging'], ['x' => 'demand_plan']] as $candidate) {
            $params = [
                'tab' => 'reports',
                'store_id' => $own['store_id'],
                'warehouse_id' => $own['warehouse_id'],
            ];
            if ($candidate !== null) {
                $params['report_panel'] = $candidate;
            }

            $html = $this->actingAs($actor)->get(route('admin.erp.wms.index', $params))
                ->assertOk()->getContent();
            $this->assertSame(1, substr_count($html, 'data-wms-report-panel="'));
            $this->assertStringContainsString('data-wms-report-panel="low_stock"', $html);
            $this->assertStringNotContainsString('data-wms-report-panel="stock_aging"', $html);
        }
    }

    public function test_report_panel_does_not_bypass_store_warehouse_role_or_disabled_module(): void
    {
        $own = $this->fixture('V2-3966-A');
        $foreign = $this->fixture('V2-3966-B');
        $actor = $this->operator($own['store_id']);

        foreach (self::PANELS as $panel) {
            $this->actingAs($actor)->get($this->url($foreign, $panel))->assertNotFound();
            $this->actingAs($actor)->get(route('admin.erp.wms.index', [
                'tab' => 'reports',
                'report_panel' => $panel,
                'store_id' => $own['store_id'],
                'warehouse_id' => $foreign['warehouse_id'],
            ]))->assertNotFound();
        }

        $outsider = User::factory()->create(['is_active' => true]);
        $outsider->roles()->attach(Role::query()->where('code', 'CUSTOMER_SERVICE_AGENT')->firstOrFail());
        $this->actingAs($outsider)->get($this->url($own, 'stock_aging'))->assertForbidden();

        app(ModuleLifecycleManager::class)->transition('inventory_wms', ModuleState::DISABLED);
        $this->actingAs($actor)->get($this->url($own, 'stock_aging'))->assertForbidden();
    }

    public function test_forged_legal_owner_query_cannot_filter_or_reclassify_authoritative_physical_stock(): void
    {
        $own = $this->fixture('V2-3966-LOCAL');
        $foreign = $this->fixture('V2-3966-FOREIGN');
        $actor = $this->operator($own['store_id']);

        $before = DB::table('inventories')->where('id', $own['inventory_id'])->first();
        $beforeMovements = DB::table('stock_movements')->count();

        foreach (['FOODEX', 'SUPPLIER', 'UNKNOWN'] as $forgedTitle) {
            $response = $this->actingAs($actor)->get(route('admin.erp.wms.index', [
                'tab' => 'reports',
                'report_panel' => 'stock_aging',
                'store_id' => $own['store_id'],
                'warehouse_id' => $own['warehouse_id'],
                'legal_owner' => $forgedTitle,
                'supplier_id' => $foreign['store_id'],
            ]))->assertOk();

            $response->assertSee('data-wms-report-panel="stock_aging"', false)
                ->assertSee('SKU-V2-3966-LOCAL')
                ->assertDontSee('SKU-V2-3966-FOREIGN');
        }

        $after = DB::table('inventories')->where('id', $own['inventory_id'])->first();
        $this->assertSame((string) $before->quantity, (string) $after->quantity);
        $this->assertSame((string) $before->reserved_quantity, (string) $after->reserved_quantity);
        $this->assertSame($beforeMovements, DB::table('stock_movements')->count());
    }

    public function test_dual_store_viewer_can_switch_reports_without_leaking_the_other_authorized_store(): void
    {
        $a = $this->fixture('V2-3966-SCOPE-A');
        $b = $this->fixture('V2-3966-SCOPE-B');
        $actor = $this->operator($a['store_id']);
        UserStoreRole::query()->create([
            'user_id' => $actor->id,
            'store_id' => $b['store_id'],
            'role_id' => Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail()->id,
        ]);

        foreach ([$a, $b] as $selected) {
            foreach (self::PANELS as $panel) {
                $response = $this->actingAs($actor->fresh())
                    ->get($this->url($selected, $panel))
                    ->assertOk()
                    ->assertViewHas('storeId', $selected['store_id'])
                    ->assertViewHas('warehouseId', $selected['warehouse_id']);

                $this->assertSame(
                    1,
                    substr_count($response->getContent(), 'data-wms-report-panel="'),
                );
            }
        }

        $this->actingAs($actor)->get($this->url($a, 'stock_aging'))
            ->assertOk()
            ->assertSee('SKU-V2-3966-SCOPE-A')
            ->assertDontSee('SKU-V2-3966-SCOPE-B');

        $this->actingAs($actor)->get($this->url($b, 'stock_aging'))
            ->assertOk()
            ->assertSee('SKU-V2-3966-SCOPE-B')
            ->assertDontSee('SKU-V2-3966-SCOPE-A');
    }

    /** @return array{store_id:int,warehouse_id:int,inventory_id:int} */
    private function fixture(string $code): array
    {
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => 'WMS '.$code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => 'b2c',
            'code' => 'catalog-'.$code,
            'name' => 'WMS '.$code,
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => DB::table('units')->orderBy('id')->value('id'),
            'sku' => 'SKU-'.$code,
            'name' => 'WMS Product '.$code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'WH-'.$code,
            'name' => 'Warehouse '.$code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $inventoryId = (int) DB::table('inventories')->insertGetId([
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => 10,
            'reserved_quantity' => 2,
            'created_at' => now()->subDays(120),
            'updated_at' => now(),
        ]);
        $this->enableEnterpriseWmsForStore($storeId);

        return [
            'store_id' => $storeId,
            'warehouse_id' => $warehouseId,
            'inventory_id' => $inventoryId,
        ];
    }

    private function operator(int $storeId): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->grantPlatformErpAccess($actor);
        UserStoreRole::query()->create([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail()->id,
        ]);

        return $actor;
    }

    /** @param array{store_id:int,warehouse_id:int,inventory_id:int} $fixture */
    private function url(array $fixture, string $panel): string
    {
        return route('admin.erp.wms.index', [
            'tab' => 'reports',
            'report_panel' => $panel,
            'store_id' => $fixture['store_id'],
            'warehouse_id' => $fixture['warehouse_id'],
        ]);
    }
}
