<?php

namespace Tests\Feature;

use App\Domain\SmartOrdering\RestaurantAnalyticsService;
use App\Domain\SmartOrdering\SmartOrderingAnalyticsService;
use Carbon\CarbonImmutable;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SmartOrderingAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_event_write_is_idempotent_and_rollup_does_not_double_count(): void
    {
        [$store] = $this->stores();
        $analytics = app(SmartOrderingAnalyticsService::class);

        $first = $analytics->record($store, 'storefront_viewed', 'view:stable-1', [
            'visitor_token' => 'visitor-token-abcdefghijklmnop',
        ]);
        $retry = $analytics->record($store, 'storefront_viewed', 'view:stable-1', [
            'visitor_token' => 'visitor-token-abcdefghijklmnop',
        ]);

        $this->assertTrue($first);
        $this->assertFalse($retry);
        $this->assertDatabaseCount('smart_ordering_analytics_events', 1);
        $this->assertSame(
            1,
            (int) DB::table('smart_ordering_analytics_daily')->sum('event_count'),
        );
    }

    public function test_dashboard_is_strictly_tenant_scoped(): void
    {
        [$storeA, $storeB] = $this->stores();
        $analytics = app(SmartOrderingAnalyticsService::class);

        $analytics->record($storeA, 'storefront_viewed', 'a:view');
        $analytics->record($storeA, 'order_created', 'a:order');
        $analytics->record($storeB, 'storefront_viewed', 'b:view-1');
        $analytics->record($storeB, 'storefront_viewed', 'b:view-2');
        $analytics->record($storeB, 'order_created', 'b:order-1');
        $analytics->record($storeB, 'order_created', 'b:order-2');

        $dashboard = app(RestaurantAnalyticsService::class)->dashboard([$storeA], [
            'from' => now()->toDateString(),
            'to' => now()->toDateString(),
        ]);

        $this->assertSame(1, $dashboard['kpis']['menu_views']);
        $this->assertSame(1, $dashboard['kpis']['orders']);
        $this->assertSame(100.0, $dashboard['kpis']['conversion_rate']);
    }

    public function test_kpi_formulas_are_reproducible_and_cancelled_revenue_is_excluded(): void
    {
        [$store] = $this->stores();
        $analytics = app(SmartOrderingAnalyticsService::class);

        for ($i = 1; $i <= 10; $i++) {
            $analytics->record($store, 'storefront_viewed', 'view:'.$i, [
                'visitor_token' => 'visitor-token-'.str_pad((string) $i, 16, 'x'),
            ]);
        }
        for ($i = 1; $i <= 4; $i++) {
            $analytics->record($store, 'cart_started', 'cart:'.$i);
        }
        for ($i = 1; $i <= 3; $i++) {
            $analytics->record($store, 'checkout_started', 'checkout:'.$i);
        }

        $analytics->record($store, 'order_created', 'order:1:created');
        $analytics->record($store, 'order_created', 'order:2:created');
        $analytics->record($store, 'order_completed', 'order:1:completed', ['revenue' => 20.000]);
        $analytics->record($store, 'order_cancelled', 'order:2:cancelled', ['revenue' => 999.000]);

        $dashboard = app(RestaurantAnalyticsService::class)->dashboard([$store], [
            'from' => now()->toDateString(),
            'to' => now()->toDateString(),
        ]);

        $kpi = $dashboard['kpis'];
        $this->assertSame(10, $kpi['menu_views']);
        $this->assertSame(4, $kpi['carts']);
        $this->assertSame(3, $kpi['checkout_starts']);
        $this->assertSame(2, $kpi['orders']);
        $this->assertSame(1, $kpi['completed_orders']);
        $this->assertSame(20.0, $kpi['conversion_rate']);
        $this->assertSame(20.0, $kpi['revenue']);
        $this->assertSame(20.0, $kpi['aov']);
        $this->assertSame(50.0, $kpi['cancellation_rate']);
        $this->assertSame(2, $kpi['abandoned_carts']);
        $this->assertSame('smart_ordering_analytics_v1', $dashboard['formula_version']);
    }

    public function test_sensitive_metadata_is_not_persisted_in_analytics(): void
    {
        [$store] = $this->stores();
        $analytics = app(SmartOrderingAnalyticsService::class);

        $analytics->record($store, 'feedback_submitted', 'feedback:1', [
            'metadata' => [
                'rating' => 5,
                'phone' => '+96555555555',
                'address' => 'private address',
                'feedback' => 'private free text',
                'order_reference' => 'SO-100',
            ],
        ]);

        $metadata = DB::table('smart_ordering_analytics_events')->value('metadata');
        $decoded = json_decode((string) $metadata, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(5, $decoded['rating']);
        $this->assertSame('SO-100', $decoded['order_reference']);
        $this->assertArrayNotHasKey('phone', $decoded);
        $this->assertArrayNotHasKey('address', $decoded);
        $this->assertArrayNotHasKey('feedback', $decoded);
    }

    public function test_rollup_uses_branch_timezone_at_midnight_boundary(): void
    {
        [$store] = $this->stores();
        $branchId = $this->branch($store, 'Asia/Kuwait');
        $analytics = app(SmartOrderingAnalyticsService::class);

        $analytics->record(
            $store,
            'storefront_viewed',
            'tz:boundary',
            ['branch_id' => $branchId],
            CarbonImmutable::parse('2026-10-14T21:30:00Z'),
        );

        $date = DB::table('smart_ordering_analytics_daily')->value('event_date');
        $this->assertSame('2026-10-15', (string) $date);

        $dashboard = app(RestaurantAnalyticsService::class)->dashboard([$store], [
            'from' => '2026-10-15',
            'to' => '2026-10-15',
            'branch_id' => $branchId,
        ]);
        $this->assertSame(1, $dashboard['kpis']['menu_views']);
    }

    public function test_public_event_rejects_unsigned_campaign_dimensions_and_dedupes_retries(): void
    {
        [$store] = $this->stores();
        $branchId = $this->branch($store, 'Asia/Kuwait');
        $site = DB::table('smart_ordering_sites')
            ->where('store_id', $store)
            ->first(['public_id']);
        $branch = DB::table('smart_ordering_branches')
            ->where('id', $branchId)
            ->first(['public_id']);

        $eventId = (string) Str::uuid();
        $payload = [
            'site_public_id' => $site->public_id,
            'branch_public_id' => $branch->public_id,
            'event_name' => 'storefront_viewed',
            'event_id' => $eventId,
            'visitor_token' => 'visitor-public-abcdefghijklmnop',
            'source' => 'forged-source',
            'campaign' => 'forged-campaign',
        ];

        $this->postJson('/api/smart-ordering/analytics/events', $payload)
            ->assertCreated();
        $this->postJson('/api/smart-ordering/analytics/events', $payload)
            ->assertOk()
            ->assertJson(['recorded' => false]);

        $row = DB::table('smart_ordering_analytics_events')->first();
        $this->assertNull($row->source_snapshot);
        $this->assertNull($row->campaign_snapshot);
        $this->assertDatabaseCount('smart_ordering_analytics_events', 1);
    }

    /** @return array{0:int,1:int} */
    private function stores(): array
    {
        $type = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $now = now();

        $a = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $type,
            'code' => 'SO-AN-A-'.Str::lower(Str::random(5)),
            'name' => 'Analytics Restaurant A',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $b = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $type,
            'code' => 'SO-AN-B-'.Str::lower(Str::random(5)),
            'name' => 'Analytics Restaurant B',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$a, $b];
    }

    private function branch(int $storeId, string $timezone): int
    {
        $siteId = (int) DB::table('smart_ordering_sites')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'store_id' => $storeId,
            'slug' => 'analytics-'.Str::lower(Str::random(10)),
            'runtime_state' => 'ENABLED',
            'master_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('smart_ordering_branches')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'site_id' => $siteId,
            'code' => 'MAIN',
            'name_en' => 'Main',
            'timezone' => $timezone,
            'is_default' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
