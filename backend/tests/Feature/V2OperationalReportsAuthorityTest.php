<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ManagementReportService;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 P306: operational reports are real, reachable through the authorized
 * Reports Center and never disclose another Retail store's order aggregates.
 */
final class V2OperationalReportsAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_global_operational_report_has_real_kpis_and_localized_authorized_entry_point(): void
    {
        $ownStore = $this->retailStore('P306-STORE-A', 'P306 Operations A');
        $otherStore = $this->retailStore('P306-STORE-B', 'P306 Operations B');
        $customerId = $this->legacyCustomer();

        $this->order($ownStore, $customerId, 'P306-A-DELIVERED', 'delivered', 50);
        $this->order($ownStore, $customerId, 'P306-A-CANCELLED', 'cancelled', 999);
        $this->order($otherStore, $customerId, 'P306-B-DELIVERED', 'delivered', 75);
        $actor = $this->superAdmin();
        $catalog = app(ManagementReportService::class)->catalog();

        $this->assertSame('reports.families.operations', $catalog['operations']);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $analytics = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
                ->firstWhere('key', 'analytics');
            $this->assertNotNull($analytics);

            $items = collect($analytics['children'])->where('key', 'reports_center')->values();
            $this->assertCount(1, $items);
            $this->assertSame('admin.reports.index', $items->first()['route']);
            $this->assertSame('reports.view', $items->first()['permission']);

            $this->actingAs($actor->fresh())
                ->get(route('admin.reports.index', $this->query(), false))
                ->assertOk()
                ->assertViewIs('admin.reports')
                ->assertViewHas('report', 'operations')
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__($catalog['operations']))
                ->assertViewHas('data', static function (array $data): bool {
                    $rows = collect($data['rows'])->keyBy('store');

                    return $data['report'] === 'operations'
                        && $data['kpis']['orders'] === 3
                        && (float) $data['kpis']['recognized_revenue'] === 125.0
                        && $data['kpis']['delivery_assignments'] === 0
                        && $data['kpis']['completed_deliveries'] === 0
                        && $data['meta']['row_count'] === 2
                        && $rows->count() === 2
                        && $rows->get('P306 Operations A')['orders'] === 2
                        && $rows->get('P306 Operations A')['delivered'] === 1
                        && $rows->get('P306 Operations A')['cancelled'] === 1
                        && (float) $rows->get('P306 Operations A')['revenue'] === 50.0
                        && (float) $rows->get('P306 Operations B')['revenue'] === 75.0;
                });
        }
    }

    public function test_roleless_user_cannot_discover_or_open_operational_reports_or_export(): void
    {
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor = User::factory()->create(['locale' => $locale, 'is_active' => true]);

            $keys = collect(app(AdminNavigation::class)->groupsFor($actor))
                ->flatMap(static fn (array $group): array => $group['children'])
                ->pluck('key')->all();
            $this->assertNotContains('reports_center', $keys);
            $this->assertNotContains('b2c_reports', $keys);

            $this->actingAs($actor)
                ->get(route('admin.reports.index', $this->query(), false))
                ->assertForbidden();

            $this->actingAs($actor)
                ->get(route('admin.reports.export', [
                    ...$this->query(),
                    'format' => 'pdf',
                ], false))
                ->assertForbidden();
        }
    }

    public function test_retail_operational_report_is_visible_for_assigned_store_only(): void
    {
        $ownStore = $this->retailStore('P306-RETAIL-OWN', 'P306 Own Retail Store');
        $foreignStore = $this->retailStore('P306-RETAIL-OTHER', 'P306 Foreign Retail Store');
        $customerId = $this->legacyCustomer();

        $this->order($ownStore, $customerId, 'P306-OWN-D', 'delivered', 50);
        $this->order($ownStore, $customerId, 'P306-OWN-C', 'cancelled', 999);
        $this->order($foreignStore, $customerId, 'P306-OTHER-D', 'delivered', 750);

        $actor = $this->retailAdmin($ownStore);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $analytics = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
                ->firstWhere('key', 'analytics');
            $this->assertNotNull($analytics);
            $children = collect($analytics['children']);
            $this->assertContains('b2c_reports', $children->pluck('key')->all());
            $this->assertNotContains('reports_center', $children->pluck('key')->all());
            $this->assertNotContains('b2b_reports', $children->pluck('key')->all());

            $item = $children->firstWhere('key', 'b2c_reports');
            $this->assertSame('admin.b2c.module', $item['route']);
            $this->assertSame((string) $ownStore, $item['params']['store_id']);

            $this->actingAs($actor->fresh())
                ->get(route('admin.b2c.module', [
                    'module' => 'reports',
                    'store_id' => $ownStore,
                ], false))
                ->assertOk();

            $this->actingAs($actor->fresh())
                ->get(route('admin.b2c.module', [
                    'module' => 'reports',
                    'store_id' => $foreignStore,
                ], false))
                ->assertNotFound();

            $this->actingAs($actor->fresh())
                ->get(route('admin.reports.index', $this->query(), false))
                ->assertRedirect()
                ->assertSessionHasErrors('store_id');

            $this->actingAs($actor->fresh())
                ->get(route('admin.reports.index', [
                    ...$this->query(), 'store_id' => $foreignStore, 'channel' => 'b2c',
                ], false))
                ->assertForbidden();

            $this->actingAs($actor->fresh())
                ->get(route('admin.reports.index', [
                    ...$this->query(), 'store_id' => $ownStore, 'channel' => 'b2b',
                ], false))
                ->assertForbidden();

            $this->actingAs($actor->fresh())
                ->get(route('admin.reports.index', [
                    ...$this->query(), 'store_id' => $ownStore, 'channel' => 'b2c',
                ], false))
                ->assertOk()
                ->assertViewHas('data', static function (array $data): bool {
                    return $data['report'] === 'operations'
                        && $data['kpis']['orders'] === 2
                        && (float) $data['kpis']['recognized_revenue'] === 50.0
                        && $data['meta']['row_count'] === 1
                        && count($data['rows']) === 1
                        && $data['rows'][0]['store'] === 'P306 Own Retail Store'
                        && $data['rows'][0]['delivered'] === 1
                        && $data['rows'][0]['cancelled'] === 1
                        && (float) $data['rows'][0]['revenue'] === 50.0;
                })
                ->assertDontSee('P306 Foreign Retail Store');
        }
    }

    public function test_store_scoped_explicit_deny_blocks_operational_report_and_menu(): void
    {
        $storeId = $this->retailStore('P306-DENY', 'P306 Denied Store');
        $actor = $this->retailAdmin($storeId);
        $permission = Permission::query()->where('code', 'reports.view')->firstOrFail();

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => $permission->id,
            'scope_key' => 'store:'.$storeId,
            'store_id' => $storeId,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        $analytics = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
            ->firstWhere('key', 'analytics');

        $this->assertTrue(
            $analytics === null || collect($analytics['children'])
                ->where('key', 'b2c_reports')->isEmpty(),
        );

        $this->actingAs($actor->fresh())
            ->get(route('admin.reports.index', [
                ...$this->query(), 'store_id' => $storeId, 'channel' => 'b2c',
            ], false))
            ->assertForbidden();
    }

    /** @return array<string, string> */
    private function query(): array
    {
        return ['report' => 'operations', 'from' => '2026-11-01', 'to' => '2026-11-30'];
    }

    private function superAdmin(): User
    {
        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $actor;
    }

    private function retailAdmin(int $storeId): User
    {
        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor;
    }

    private function retailStore(string $code, string $name): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'code' => $code,
            'name' => $name,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function legacyCustomer(): int
    {
        return (int) Customer::query()->create([
            'type' => 'b2c',
            'name' => 'P306 Synthetic Customer',
            'email' => 'p306-test@example.test',
        ])->getKey();
    }

    private function order(int $storeId, int $customerId, string $number, string $status, float $total): void
    {
        DB::table('orders')->insert([
            'store_id' => $storeId,
            'customer_id' => $customerId,
            'order_number' => $number,
            'channel' => 'b2c',
            'status' => $status,
            'currency' => 'KWD',
            'subtotal' => $total,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => $total,
            'created_at' => '2026-11-03 10:00:00',
            'updated_at' => '2026-11-03 10:00:00',
        ]);
    }
}
