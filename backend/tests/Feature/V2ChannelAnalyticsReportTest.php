<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Services\ManagementReportService;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2ChannelAnalyticsReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_real_channel_store_groups_are_localized_and_aggregate_kpis_are_independent_of_pagination(): void
    {
        $retailA = $this->store('V2-CHAN-RETAIL-A', 'B2C');
        $retailB = $this->store('V2-CHAN-RETAIL-B', 'B2C');
        $wholesale = $this->store('V2-CHAN-WHOLESALE', 'B2B');
        $this->order($retailA, 'b2c', 'delivered', 30, 'V2-C-A1');
        $this->order($retailA, 'b2c', 'cancelled', 90, 'V2-C-A2');
        $this->order($retailB, 'b2c', 'delivered', 50, 'V2-C-B1');
        $this->order($wholesale, 'b2b', 'delivered', 70, 'V2-C-W1');

        $admin = $this->admin();
        $filters = ['from' => '2026-11-01', 'to' => '2026-11-30'];

        foreach (['ar' => ['rtl', 'الجملة', 'التجزئة'], 'en' => ['ltr', 'Wholesale', 'Retail']] as $locale => [$direction, $b2b, $b2c]) {
            $admin->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $keys = collect(app(AdminNavigation::class)->groupsFor($admin->fresh()))
                ->flatMap(static fn (array $group): array => $group['children'])
                ->keyBy('key');
            $this->assertSame('admin.reports.index', $keys['reports_center']['route']);

            $this->actingAs($admin->fresh())
                ->get(route('admin.reports.index', ['report' => 'channels', ...$filters], false))
                ->assertOk()
                ->assertViewIs('admin.reports')
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__('reports.families.channels'))
                ->assertViewHas('data', static function (array $data) use ($b2b, $b2c): bool {
                    $rows = collect($data['rows'])->keyBy('store');

                    return $data['report'] === 'channels'
                        && $data['kpis']['orders'] === 4
                        && $data['kpis']['reporting_stores'] === 3
                        && (float) $data['kpis']['gross_value'] === 240.0
                        && (float) $data['kpis']['recognized_revenue'] === 150.0
                        && $data['meta']['row_count'] === 3
                        && $rows['V2-CHAN-RETAIL-A']['channel'] === $b2c
                        && $rows['V2-CHAN-WHOLESALE']['channel'] === $b2b
                        && $rows['V2-CHAN-RETAIL-A']['orders'] === 2
                        && $rows['V2-CHAN-RETAIL-A']['excluded_orders'] === 1
                        && (float) $rows['V2-CHAN-RETAIL-A']['revenue'] === 30.0;
                });
        }

        $page = app(ManagementReportService::class)->run($admin->fresh(), 'channels', $filters, 1, 1);
        $this->assertSame(3, $page['meta']['row_count']);
        $this->assertSame(4, $page['kpis']['orders']);
        $this->assertCount(1, $page['rows']);

        $export = $this->actingAs($admin->fresh())->get(route('admin.reports.export', [
            'report' => 'channels', 'format' => 'xlsx', ...$filters,
        ], false))->assertOk();
        $this->assertStringStartsWith('PK', $export->getContent());
        $this->assertStringContainsString('no-store', (string) $export->headers->get('Cache-Control'));
        $this->assertDatabaseHas('audit_logs', ['user_id' => $admin->id, 'event' => 'report.exported']);
    }

    public function test_retail_role_cannot_read_other_store_wholesale_channel_or_cross_store_exports(): void
    {
        $own = $this->store('V2-CHAN-OWN', 'B2C');
        $other = $this->store('V2-CHAN-OTHER', 'B2C');
        $wholesale = $this->store('V2-CHAN-B2B', 'B2B');
        $this->order($own, 'b2c', 'delivered', 25, 'V2-C-OWN');
        $this->order($other, 'b2c', 'delivered', 999, 'V2-C-OTHER');
        $this->order($wholesale, 'b2b', 'delivered', 500, 'V2-C-WHOLE');

        $actor = User::factory()->create(['is_active' => true]);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $own,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $this->actingAs($actor->fresh())->get(route('admin.reports.index', [
                'report' => 'channels', 'store_id' => $own, 'channel' => 'b2c',
                'from' => '2026-11-01', 'to' => '2026-11-30',
            ], false))
                ->assertOk()
                ->assertViewHas('data', static fn (array $data): bool => count($data['rows']) === 1
                    && $data['kpis']['orders'] === 1
                    && (float) $data['kpis']['recognized_revenue'] === 25.0
                    && $data['rows'][0]['store'] === 'V2-CHAN-OWN')
                ->assertDontSee('V2-CHAN-OTHER')
                ->assertDontSee('V2-CHAN-B2B');

            $this->actingAs($actor->fresh())->get(route('admin.reports.index', [
                'report' => 'channels', 'store_id' => $other, 'channel' => 'b2c',
            ], false))->assertForbidden();
            $this->actingAs($actor->fresh())->get(route('admin.reports.index', [
                'report' => 'channels', 'store_id' => $own, 'channel' => 'b2b',
            ], false))->assertForbidden();
            $this->actingAs($actor->fresh())->get(route('admin.reports.export', [
                'report' => 'channels', 'format' => 'pdf', 'store_id' => $other,
            ], false))->assertForbidden();
        }
    }

    public function test_roleless_actor_is_denied_navigation_report_and_export(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $keys = collect(app(AdminNavigation::class)->groupsFor($actor))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->pluck('key');
        $this->assertNotContains('reports_center', $keys->all());

        $this->actingAs($actor)->get(route('admin.reports.index', [
            'report' => 'channels',
        ], false))->assertForbidden();
        $this->actingAs($actor)->get(route('admin.reports.export', [
            'report' => 'channels', 'format' => 'xlsx',
        ], false))->assertForbidden();
    }

    private function store(string $code, string $type): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $type)->value('id'),
            'business_profile' => $type === 'B2C' ? StoreBusinessProfile::RETAIL_STORE->value : null,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function order(int $store, string $channel, string $status, float $total, string $number): void
    {
        $customer = Customer::query()->create([
            'type' => $channel,
            'name' => 'Channel Test '.$number,
            'email' => strtolower($number).'@example.test',
        ]);

        DB::table('orders')->insert([
            'store_id' => $store,
            'customer_id' => $customer->id,
            'order_number' => $number,
            'channel' => $channel,
            'status' => $status,
            'currency' => 'EGP',
            'subtotal' => $total,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => $total,
            'created_at' => '2026-11-03 10:00:00',
            'updated_at' => '2026-11-03 10:00:00',
        ]);
    }

    private function admin(): User
    {
        $actor = User::factory()->create(['is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $actor;
    }
}
