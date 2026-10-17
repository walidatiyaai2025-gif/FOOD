<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class EnterpriseBiWorkbenchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_entitled_business_user_can_discover_and_run_bounded_w7_orders_report(): void
    {
        [$actor, $store] = $this->authorizedStoreActor('ERP-BI-UI-A');
        $customerId = (int) DB::table('customers')->insertGetId([
            'type' => 'b2c',
            'name' => 'BI UI Customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $day = now()->subDay()->toDateString();

        DB::table('orders')->insert([
            'store_id' => $store->id,
            'customer_id' => $customerId,
            'order_number' => 'ERP-BI-UI-ORDER',
            'channel' => 'b2c',
            'status' => 'completed',
            'currency' => 'KWD',
            'subtotal' => '12.000',
            'discount_total' => '0.000',
            'delivery_total' => '0.000',
            'grand_total' => '12.000',
            'created_at' => $day.' 10:00:00',
            'updated_at' => $day.' 10:00:00',
        ]);

        $this->actingAs($actor)
            ->get(route('admin.erp.bi', [
                'report' => 'orders.summary',
                'scope' => 'store:'.$store->id,
                'breakdown' => 'channel',
                'from' => $day,
                'to' => $day,
                'run' => 1,
            ]))
            ->assertOk()
            ->assertViewIs('admin.enterprise-bi')
            ->assertSee(__('enterprise_bi_ui.title'))
            ->assertSee(__('enterprise_bi_ui.reports.orders.title'))
            ->assertSee(__('enterprise_bi_ui.reports.profitability.title'))
            ->assertSee(__('enterprise_bi_ui.reports.stock.title'))
            ->assertSee($store->name)
            ->assertSee('b2c')
            ->assertSee('12.000')
            ->assertSee(__('enterprise_bi_ui.open_source'))
            ->assertSee('data-bi-run-form', false);
    }

    public function test_export_schedule_and_exception_panels_reflect_persisted_w7_state(): void
    {
        [$actor, $store] = $this->authorizedStoreActor('ERP-BI-UI-B');

        DB::table('enterprise_report_exports')->insert([
            'report_code' => 'orders.summary',
            'scope_type' => 'store',
            'scope_id' => $store->id,
            'requested_by' => $actor->id,
            'scheduled_report_id' => null,
            'query_payload' => '{}',
            'row_count' => 5001,
            'execution_mode' => 'queued_chunked',
            'chunk_size' => 500,
            'next_offset' => 500,
            'processed_rows' => 500,
            'chunks_completed' => 1,
            'status' => 'failed',
            'failure_code' => 'source_unavailable',
            'artifact_metadata' => null,
            'idempotency_key' => hash('sha256', 'erp-bi-ui-export'),
            'completed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('enterprise_scheduled_reports')->insert([
            'report_code' => 'orders.summary',
            'scope_type' => 'store',
            'scope_id' => $store->id,
            'requested_by' => $actor->id,
            'query_payload' => '{}',
            'row_count_estimate' => 100,
            'interval_minutes' => 1440,
            'next_run_at' => now()->addDay(),
            'last_run_at' => null,
            'enabled' => true,
            'last_error' => 'source_unavailable',
            'schedule_key' => hash('sha256', 'erp-bi-ui-schedule'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($actor)
            ->get(route('admin.erp.bi'))
            ->assertOk()
            ->assertSee(__('enterprise_bi_ui.exports_title'))
            ->assertSee(__('enterprise_bi_ui.schedules_title'))
            ->assertSee(__('enterprise_bi_ui.failed_exports'))
            ->assertSee(__('enterprise_bi_ui.failed_schedules'))
            ->assertSee('5001')
            ->assertSee('1440');
    }

    public function test_executive_dashboard_uses_shared_primitives_and_only_forecasts_with_real_history(): void
    {
        [$actor, $store] = $this->authorizedStoreActor('ERP-BI-UI-C');
        $customerId = (int) DB::table('customers')->insertGetId([
            'type' => 'b2c',
            'name' => 'BI Forecast Customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (range(0, 15) as $offset) {
            $day = now()->subDays($offset);
            DB::table('orders')->insert([
                'store_id' => $store->id,
                'customer_id' => $customerId,
                'order_number' => 'ERP-BI-FC-'.$offset,
                'channel' => 'b2c',
                'status' => 'completed',
                'currency' => 'KWD',
                'subtotal' => '10.000',
                'discount_total' => '0.000',
                'delivery_total' => '0.000',
                'grand_total' => '10.000',
                'created_at' => $day,
                'updated_at' => $day,
            ]);
        }

        $response = $this->actingAs($actor)
            ->get(route('admin.erp.bi', [
                'dashboard_store' => 'store:'.$store->id,
                'dashboard_from' => now()->subDays(6)->toDateString(),
                'dashboard_to' => now()->toDateString(),
                'dashboard_currency' => 'KWD',
            ]));

        $response
            ->assertOk()
            ->assertSee('data-enterprise-bi-overview', false)
            ->assertSee('foodex-module-kpi', false)
            ->assertSee('data-bi-chart="orders"', false)
            ->assertSee(__('enterprise_bi_ui.forecast.title'))
            ->assertSee(__('enterprise_bi_ui.forecast.method'))
            ->assertSee(__('enterprise_bi_ui.saved_views.title'));
    }

    public function test_semantic_builder_accepts_only_registered_dimensions_measures_and_sort(): void
    {
        [$actor, $store] = $this->authorizedStoreActor('ERP-BI-UI-D');
        $day = now()->toDateString();

        $response = $this->actingAs($actor)
            ->get(route('admin.erp.bi', [
                'report' => 'orders.summary',
                'scope' => 'store:'.$store->id,
                'breakdown' => 'daily',
                'dimensions' => ['date'],
                'measures' => ['orders'],
                'sort_field' => 'orders',
                'sort_direction' => 'desc',
                'from' => $day,
                'to' => $day,
                'run' => 1,
            ]));

        $response
            ->assertOk()
            ->assertViewHas('selection', function (array $selection): bool {
                return $selection['dimensions'] === ['date']
                    && $selection['measures'] === ['orders']
                    && $selection['sort'] === [['field' => 'orders', 'direction' => 'desc']];
            })
            ->assertSee('name="dimensions[]"', false)
            ->assertSee('name="measures[]"', false);
    }

    public function test_authorized_user_can_create_edit_and_disable_a_scheduled_report(): void
    {
        [$actor, $store] = $this->authorizedStoreActor('ERP-BI-UI-E');
        $from = now()->subDay()->toDateString();
        $to = now()->toDateString();
        $nextRun = now()->addHour()->format('Y-m-d H:i:s');

        $this->actingAs($actor)
            ->post(route('admin.erp.bi.schedules.store'), [
                'report' => 'orders.summary',
                'scope_type' => 'store',
                'scope_id' => $store->id,
                'from' => $from,
                'to' => $to,
                'dimensions' => ['date'],
                'measures' => ['orders'],
                'sort_field' => 'orders',
                'sort_direction' => 'desc',
                'interval_minutes' => 1440,
                'next_run_at' => $nextRun,
            ])
            ->assertRedirect();

        $schedule = DB::table('enterprise_scheduled_reports')
            ->where('requested_by', $actor->id)
            ->where('report_code', 'orders.summary')
            ->first();

        $this->assertNotNull($schedule);
        $plan = json_decode((string) $schedule->query_payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['date'], $plan['dimensions']);
        $this->assertSame(['orders'], $plan['measures']);
        $this->assertSame([['field' => 'orders', 'direction' => 'desc']], $plan['sort']);

        $this->actingAs($actor)
            ->patch(route('admin.erp.bi.schedules.update', ['schedule' => $schedule->id]), [
                'enabled' => false,
                'interval_minutes' => 2880,
                'next_run_at' => now()->addHours(2)->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('enterprise_scheduled_reports', [
            'id' => $schedule->id,
            'enabled' => false,
            'interval_minutes' => 2880,
        ]);
    }

    public function test_user_without_reporting_permission_is_denied(): void
    {
        $actor = User::factory()->create(['is_active' => true]);

        $this->actingAs($actor)
            ->get(route('admin.erp.bi'))
            ->assertForbidden();
    }

    public function test_bi_ui_arabic_and_english_catalog_contracts_stay_in_parity(): void
    {
        $en = require lang_path('en/enterprise_bi_ui.php');
        $ar = require lang_path('ar/enterprise_bi_ui.php');

        $this->assertSame(array_keys($en), array_keys($ar));
        $this->assertSame(array_keys($en['reports']), array_keys($ar['reports']));
        $this->assertSame(array_keys($en['breakdowns']), array_keys($ar['breakdowns']));
        $this->assertSame(array_keys($en['fields']), array_keys($ar['fields']));
        $this->assertSame(array_keys($en['statuses']), array_keys($ar['statuses']));
        $this->assertSame(array_keys($en['overview']), array_keys($ar['overview']));
        $this->assertSame(array_keys($en['kpis']), array_keys($ar['kpis']));
        $this->assertSame(array_keys($en['charts']), array_keys($ar['charts']));
        $this->assertSame(array_keys($en['forecast']), array_keys($ar['forecast']));
        $this->assertSame(array_keys($en['saved_views']), array_keys($ar['saved_views']));
    }

    /** @return array{User,Store} */
    private function authorizedStoreActor(string $code): array
    {
        app(ModuleRegistry::class)->syncManifest('enterprise_bi');
        $lifecycle = app(ModuleLifecycleManager::class);
        $lifecycle->transition('enterprise_bi', ModuleState::ENABLING);
        $lifecycle->transition('enterprise_bi', ModuleState::ENABLED);

        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $store = Store::query()->create([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => 'Store '.$code,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        app(ModuleEntitlementManager::class)->set(
            'enterprise_bi',
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            true,
            ['reports.read', 'reports.export'],
        );

        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return [$actor, $store];
    }
}
