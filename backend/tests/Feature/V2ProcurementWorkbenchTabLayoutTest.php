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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyntheticErpWorkflowEvidence;
use Tests\TestCase;

final class V2ProcurementWorkbenchTabLayoutTest extends TestCase
{
    use RefreshDatabase;

    private const TABS = [
        'overview', 'suppliers', 'requests', 'rfqs', 'orders', 'contracts',
        'receipts', 'invoices', 'matches', 'returns', 'statements', 'reports',
    ];

    private const EMBEDDED_PAGINATION_TABS = ['rfqs', 'contracts', 'matches'];

    private const FOOTER_PAGINATION_TABS = [
        'suppliers', 'requests', 'orders', 'receipts', 'invoices', 'returns',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        SyntheticErpWorkflowEvidence::install();
        config()->set('security.mfa.enforce_privileged', false);
        config()->set(
            'enterprise_module_readiness.certified_versions.procurement',
            app(ModuleRegistry::class)->manifest('procurement')->version,
        );
    }

    public function test_all_twelve_authorized_tabs_reuse_one_horizontal_rail_in_arabic_and_english(): void
    {
        $this->enableProcurement();
        [$actor, $store] = $this->operator('V2-PROC-3964');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            foreach (self::TABS as $tab) {
                $response = $this->actingAs($actor->fresh())->get(route('admin.erp.procurement.index', [
                    'store_id' => $store->id,
                    'tab' => $tab,
                ]))->assertOk()->assertViewHas('activeTab', $tab);

                $html = html_entity_decode($response->getContent(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $this->assertStringContainsString('dir="'.$direction.'"', $html);
                $this->assertSame(1, preg_match(
                    '/<nav\\s+class="foodex-tabs erp-procurement-tabs"[^>]*data-procurement-tabs[^>]*>(.*?)<\\/nav>/s',
                    $html,
                    $navigation,
                ));
                $this->assertSame(count(self::TABS), substr_count($navigation[1], 'data-procurement-tab="'));
                $this->assertSame(1, substr_count($navigation[1], 'aria-current="page"'));
                $this->assertStringContainsString(
                    'data-procurement-tab="'.$tab.'"',
                    $navigation[1],
                );
                $this->assertSame(1, preg_match(
                    '/<a data-procurement-tab="'.preg_quote($tab, '/').'" href="([^"]+)"/',
                    $navigation[1],
                    $tabLink,
                ));
                parse_str((string) parse_url($tabLink[1], PHP_URL_QUERY), $tabQuery);
                $this->assertSame($tab, $tabQuery['tab'] ?? null);
                $this->assertSame((string) $store->id, (string) ($tabQuery['store_id'] ?? ''));
                $this->assertStringContainsString(__('enterprise_procurement.tabs.'.$tab), $navigation[1]);
            }
        }
    }

    public function test_tab_filter_store_and_inline_pagination_stay_in_one_authorized_context(): void
    {
        $this->enableProcurement();
        [$actor, $store] = $this->operator('V2-PROC-3964-FILTER');

        foreach (self::EMBEDDED_PAGINATION_TABS as $tab) {
            $response = $this->actingAs($actor)->get(route('admin.erp.procurement.index', [
                'tab' => $tab,
                'store_id' => $store->id,
                'q' => 'SAFE-PREFIX',
                'status' => $tab === 'matches' ? 'pending' : 'draft',
            ]))->assertOk();

            $html = $response->getContent();
            $this->assertSame(1, substr_count($html, 'class="erp-pagination"'));
            $this->assertStringNotContainsString('data-procurement-footer-pagination', $html);
            $this->assertStringContainsString('name="tab" value="'.$tab.'"', $html);
            $this->assertStringContainsString('name="store_id" value="'.$store->id.'"', $html);
            $this->assertStringContainsString('name="q" value="SAFE-PREFIX"', $html);
            $selectedStatus = $tab === 'matches' ? 'pending' : 'draft';
            $this->assertMatchesRegularExpression(
                '/<option value="'.preg_quote($selectedStatus, '/').'"\\s+selected\\b/',
                $html,
            );
            parse_str((string) parse_url((string) $response->viewData('rows')->url(2), PHP_URL_QUERY), $pageQuery);
            $this->assertSame($tab, $pageQuery['tab'] ?? null);
            $this->assertSame((string) $store->id, (string) ($pageQuery['store_id'] ?? ''));
            $this->assertSame('SAFE-PREFIX', $pageQuery['q'] ?? null);
            $this->assertSame($selectedStatus, $pageQuery['status'] ?? null);
        }

        foreach (self::FOOTER_PAGINATION_TABS as $tab) {
            $response = $this->actingAs($actor)->get(route('admin.erp.procurement.index', [
                'tab' => $tab,
                'store_id' => $store->id,
                'q' => 'SAFE-PREFIX',
            ]))->assertOk();

            $html = $response->getContent();
            $this->assertSame(1, substr_count($html, 'data-procurement-footer-pagination'));
            $this->assertStringNotContainsString('class="erp-pagination"', $html);
            $this->assertStringContainsString('name="tab" value="'.$tab.'"', $html);
            $this->assertStringContainsString('name="store_id" value="'.$store->id.'"', $html);
            $this->assertStringContainsString('name="q" value="SAFE-PREFIX"', $html);
            parse_str((string) parse_url((string) $response->viewData('rows')->url(2), PHP_URL_QUERY), $pageQuery);
            $this->assertSame($tab, $pageQuery['tab'] ?? null);
            $this->assertSame((string) $store->id, (string) ($pageQuery['store_id'] ?? ''));
            $this->assertSame('SAFE-PREFIX', $pageQuery['q'] ?? null);
        }

        foreach (['overview', 'statements', 'reports'] as $tab) {
            $this->actingAs($actor)->get(route('admin.erp.procurement.index', [
                'tab' => $tab, 'store_id' => $store->id,
            ]))->assertOk()
                ->assertDontSee('data-procurement-footer-pagination', false)
                ->assertDontSee('class="erp-pagination"', false);
        }
    }

    public function test_tab_selection_and_filters_cannot_expose_a_foreign_store_or_grant_a_role(): void
    {
        $this->enableProcurement();
        [$actor, $own] = $this->operator('V2-PROC-3964-OWN');
        [, $foreign] = $this->operator('V2-PROC-3964-FOREIGN');

        foreach (self::TABS as $tab) {
            $this->actingAs($actor)->get(route('admin.erp.procurement.index', [
                'tab' => $tab,
                'store_id' => $foreign->id,
                'q' => 'foreign',
                'status' => 'draft',
            ]))->assertForbidden();
        }

        $outsider = User::factory()->create(['is_active' => true]);
        $this->actingAs($outsider)->get(route('admin.erp.procurement.index', [
            'tab' => 'matches',
            'store_id' => $own->id,
            'q' => 'forged',
        ]))->assertForbidden();
    }

    public function test_denied_tab_permission_never_appears_as_a_link_or_opens_by_direct_url(): void
    {
        $this->enableProcurement();
        [$actor, $store] = $this->operator('V2-PROC-3964-DENY');

        foreach ([
            'procurement.purchase_requests.view' => ['requests', 'rfqs'],
            'procurement.purchase_invoices.view' => ['invoices', 'matches'],
        ] as $permissionCode => $deniedTabs) {
            DB::table('user_permission_overrides')->insert([
                'user_id' => $actor->id,
                'permission_id' => Permission::query()->where('code', $permissionCode)->firstOrFail()->id,
                'scope_key' => 'global',
                'store_id' => null,
                'effect' => 'deny',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $actor->forceFill(['has_permission_overrides' => true])->save();
            $response = $this->actingAs($actor->fresh())->get(route('admin.erp.procurement.index', [
                'tab' => 'suppliers',
                'store_id' => $store->id,
            ]))->assertOk();

            foreach ($deniedTabs as $tab) {
                $response->assertDontSee('data-procurement-tab="'.$tab.'"', false);
                $this->actingAs($actor->fresh())->get(route('admin.erp.procurement.index', [
                    'tab' => $tab,
                    'store_id' => $store->id,
                ]))->assertForbidden();
            }
        }
    }

    private function enableProcurement(): void
    {
        $lifecycle = app(ModuleLifecycleManager::class);
        $lifecycle->transition('procurement', ModuleState::ENABLING);
        $lifecycle->transition('procurement', ModuleState::ENABLED);
    }

    /** @return array{User, Store} */
    private function operator(string $code): array
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        if ($typeId < 1) {
            $typeId = (int) DB::table('store_types')->insertGetId([
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

        $role = Role::query()->firstOrCreate(
            ['code' => 'B2C_STORE_ADMIN'],
            ['name' => 'B2C Store Admin', 'scope' => 'store', 'is_active' => true, 'is_system' => true],
        );

        foreach ([
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
            'procurement.purchase_invoices.view',
            'procurement.purchase_invoices.manage',
            'procurement.purchase_invoices.handoff',
            'procurement.purchase_returns.view',
            'procurement.purchase_returns.manage',
            'procurement.purchase_returns.post',
            'procurement.supplier_statements.view',
            'reports.view',
            'reports.export',
        ] as $permissionCode) {
            $permission = Permission::query()->firstOrCreate(
                ['code' => $permissionCode],
                ['name' => $permissionCode],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $actor = User::factory()->create(['locale' => 'ar', 'is_active' => true]);
        $this->grantPlatformErpAccess($actor);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$actor, $store];
    }
}
