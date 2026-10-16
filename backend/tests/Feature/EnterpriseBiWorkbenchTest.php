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
