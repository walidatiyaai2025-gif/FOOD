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

/**
 * V2 P310: Customer-service reporting reuses the genuine Customers family
 * in the authorized Reports Center, with server-side store/channel isolation.
 */
final class V2CustomerServiceReportsAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_customer_service_report_is_discoverable_and_renders_real_customer_metrics_in_both_locales(): void
    {
        $ownStore = $this->retailStore('V2-P310-REPORT');
        $customer = $this->retailCustomer($ownStore, 'V2 Service Customer', 'v2-service-customer@example.test');
        $this->order($ownStore, $customer, 'V2-P310-PAID', 'delivered', 30, '2026-11-03 10:00:00');
        $this->order($ownStore, $customer, 'V2-P310-CANCEL', 'cancelled', 99, '2026-11-04 10:00:00');

        $actor = $this->superAdmin();
        $catalog = app(ManagementReportService::class)->catalog();
        $this->assertSame('reports.families.customers', $catalog['customers']);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $analytics = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
                ->firstWhere('key', 'analytics');
            $this->assertNotNull($analytics);

            $items = collect($analytics['children'])->where('key', 'reports_center')->values();
            $this->assertCount(1, $items);
            $this->assertSame('admin.reports.index', $items->first()['route']);
            $this->assertSame('reports.view', $items->first()['permission']);

            $this->actingAs($actor->fresh())
                ->get(route('admin.reports.index', [
                    'report' => 'customers',
                    'store_id' => $ownStore,
                    'channel' => 'b2c',
                    'from' => '2026-11-01',
                    'to' => '2026-11-30',
                ], false))
                ->assertOk()
                ->assertViewIs('admin.reports')
                ->assertViewHas('report', 'customers')
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__($catalog['customers']))
                ->assertViewHas('data', static function (array $data): bool {
                    return $data['report'] === 'customers'
                        && $data['kpis']['active_customers'] === 1
                        && $data['kpis']['new_customers'] === 1
                        && $data['kpis']['returning_customers'] === 0
                        && (float) $data['kpis']['customer_value'] === 30.0
                        && count($data['rows']) === 1
                        && $data['rows'][0]['customer'] === 'V2 Service Customer'
                        && $data['rows'][0]['orders'] === 2
                        && (float) $data['rows'][0]['value'] === 30.0;
                });
        }
    }

    public function test_roleless_actor_cannot_discover_customer_reporting_or_export_directly(): void
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
                ->get(route('admin.reports.index', ['report' => 'customers'], false))
                ->assertForbidden();

            $this->actingAs($actor)
                ->get(route('admin.reports.export', [
                    'report' => 'customers',
                    'format' => 'pdf',
                ], false))
                ->assertForbidden();
        }
    }

    public function test_retail_customer_reporting_cannot_read_foreign_store_or_b2b_customer_data(): void
    {
        $ownStore = $this->retailStore('V2-P310-OWN');
        $otherStore = $this->retailStore('V2-P310-OTHER');
        $ownCustomer = $this->retailCustomer($ownStore, 'V2 Assigned Customer', 'v2-assigned@example.test');
        $otherCustomer = $this->retailCustomer($otherStore, 'V2 Foreign Customer', 'v2-foreign@example.test');
        $this->order($ownStore, $ownCustomer, 'V2-P310-OWN-ORDER', 'delivered', 24, '2026-11-03 10:00:00');
        $this->order($otherStore, $otherCustomer, 'V2-P310-OTHER-ORDER', 'delivered', 500, '2026-11-03 10:00:00');

        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->getKey(),
            'store_id' => $ownStore,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['ar', 'en'] as $locale) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $keys = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
                ->flatMap(static fn (array $group): array => $group['children'])
                ->pluck('key')->all();
            $this->assertContains('b2c_reports', $keys);
            $this->assertNotContains('reports_center', $keys);

            $this->actingAs($actor->fresh())
                ->get(route('admin.b2c.module', ['module' => 'reports', 'store_id' => $ownStore], false))
                ->assertOk();

            $this->actingAs($actor->fresh())
                ->get(route('admin.b2c.module', ['module' => 'reports', 'store_id' => $otherStore], false))
                ->assertNotFound();

            $this->actingAs($actor->fresh())
                ->get(route('admin.reports.index', ['report' => 'customers'], false))
                ->assertRedirect()
                ->assertSessionHasErrors('store_id');

            $this->actingAs($actor->fresh())
                ->get(route('admin.reports.index', [
                    'report' => 'customers',
                    'store_id' => $otherStore,
                    'channel' => 'b2c',
                ], false))
                ->assertForbidden();

            $this->actingAs($actor->fresh())
                ->get(route('admin.reports.index', [
                    'report' => 'customers',
                    'store_id' => $ownStore,
                    'channel' => 'b2b',
                ], false))
                ->assertForbidden();

            $this->actingAs($actor->fresh())
                ->get(route('admin.reports.index', [
                    'report' => 'customers',
                    'store_id' => $ownStore,
                    'channel' => 'b2c',
                    'from' => '2026-11-01',
                    'to' => '2026-11-30',
                ], false))
                ->assertOk()
                ->assertViewHas('report', 'customers')
                ->assertViewHas('data', static function (array $data): bool {
                    return count($data['rows']) === 1
                        && $data['rows'][0]['customer'] === 'V2 Assigned Customer'
                        && (float) $data['kpis']['customer_value'] === 24.0
                        && (float) $data['rows'][0]['value'] === 24.0;
                })
                ->assertDontSee('V2 Foreign Customer');
        }
    }

    private function superAdmin(): User
    {
        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $actor;
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

    private function retailCustomer(int $storeId, string $name, string $email): int
    {
        // The legacy customers foreign key remains non-null on orders. Keep
        // the historical identity alongside the channel-specific customer.
        $legacy = Customer::query()->create([
            'type' => 'b2c',
            'name' => $name,
            'email' => $email,
        ]);

        return (int) DB::table('b2c_customers')->insertGetId([
            'legacy_customer_id' => $legacy->getKey(),
            'store_id' => $storeId,
            'user_id' => null,
            'name' => $name,
            'phone' => null,
            'email' => $email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function order(int $storeId, int $customerId, string $number, string $status, float $total, string $date): void
    {
        DB::table('orders')->insert([
            'store_id' => $storeId,
            'customer_id' => (int) DB::table('b2c_customers')->where('id', $customerId)->value('legacy_customer_id'),
            'b2c_customer_id' => $customerId,
            'order_number' => $number,
            'channel' => 'b2c',
            'status' => $status,
            'currency' => 'KWD',
            'subtotal' => $total,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => $total,
            'created_at' => $date,
            'updated_at' => $date,
        ]);
    }
}
