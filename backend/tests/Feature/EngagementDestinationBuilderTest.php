<?php

namespace Tests\Feature;

use App\Models\NotificationCampaign;
use App\Models\Role;
use App\Models\User;
use App\Support\EngagementDestinationCatalog;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EngagementDestinationBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_live_ads_use_a_scoped_destination_builder_and_reject_unknown_routes(): void
    {
        $storeId = $this->store('B2C', 'DEST-LIVE-RETAIL');
        $admin = $this->superAdmin('en', 'dest-live@example.test');

        $this->actingAs($admin)
            ->get('/admin/live-ads')
            ->assertOk()
            ->assertSee('name="cta_destination"', false)
            ->assertDontSee('name="cta_target"', false)
            ->assertSee('Customer · Selected Retail store home');

        $this->actingAs($admin)->post('/admin/live-ads', $this->liveAdPayload([
            'channel' => 'b2c',
            'store_id' => $storeId,
            'cta_destination' => 'customer.retail_home',
        ]))->assertRedirect();

        $this->assertDatabaseHas('live_ads', [
            'channel' => 'b2c',
            'store_id' => $storeId,
            'cta_target' => "/retail/{$storeId}/home",
        ]);

        $this->actingAs($admin)->post('/admin/live-ads', $this->liveAdPayload([
            'channel' => 'b2c',
            'store_id' => $storeId,
            'cta_target' => '/not-a-canonical-destination',
        ]))->assertSessionHasErrors('cta_destination');

        $this->assertDatabaseCount('live_ads', 1);
    }

    public function test_campaign_popup_destination_is_app_channel_compatible_and_store_contextual(): void
    {
        $storeId = $this->store('B2C', 'DEST-CAMPAIGN-RETAIL');
        $admin = $this->superAdmin('en', 'dest-campaign@example.test');

        $this->actingAs($admin)
            ->get('/admin/notification-campaigns')
            ->assertOk()
            ->assertSee('name="popup_cta_destination"', false)
            ->assertDontSee('name="popup_cta_target"', false)
            ->assertSee('Customer · Offers');

        $this->actingAs($admin)->post('/admin/notification-campaigns', $this->campaignPayload([
            'target_channel' => 'b2c',
            'store_id' => $storeId,
            'popup_cta_destination' => 'customer.retail_home',
        ]))->assertRedirect();

        $this->assertDatabaseHas('notification_campaigns', [
            'target_channel' => 'b2c',
            'store_id' => $storeId,
            'popup_cta_target' => "/retail/{$storeId}/home",
        ]);

        $this->actingAs($admin)->post('/admin/notification-campaigns', $this->campaignPayload([
            'app' => 'driver',
            'audience' => 'driver',
            'target_channel' => 'b2c',
            'store_id' => $storeId,
            'popup_cta_destination' => 'customer.retail_offers',
        ]))->assertSessionHasErrors('popup_cta_destination');

        $this->actingAs($admin)->post('/admin/notification-campaigns', $this->campaignPayload([
            'target_channel' => 'b2c',
            'store_id' => $storeId,
            'popup_cta_target' => '/offers',
        ]))->assertRedirect();

        $this->assertDatabaseHas('notification_campaigns', [
            'target_channel' => 'b2c',
            'store_id' => $storeId,
            'popup_cta_target' => '/offers',
        ]);

        $this->actingAs($admin)->post('/admin/notification-campaigns', $this->campaignPayload([
            'target_channel' => 'b2c',
            'store_id' => $storeId,
            'popup_cta_target' => '/admin/users',
        ]))->assertSessionHasErrors('popup_cta_destination');
    }

    public function test_runtime_payloads_fail_closed_for_legacy_noncanonical_targets(): void
    {
        $storeId = $this->store('B2C', 'DEST-RUNTIME-RETAIL');
        $admin = $this->superAdmin('en', 'dest-runtime@example.test');

        $this->actingAs($admin)->post('/admin/live-ads', $this->liveAdPayload([
            'channel' => 'b2c',
            'store_id' => $storeId,
            'cta_destination' => 'customer.retail_offers',
        ]))->assertRedirect();

        DB::table('live_ads')->update(['cta_target' => '/admin/users']);

        $this->getJson("/api/v1/live-ads?channel=b2c&store_id={$storeId}&locale=en")
            ->assertOk()
            ->assertJsonPath('data.0.cta_target', null);

        NotificationCampaign::query()->create([
            'name' => 'Legacy unsafe target',
            'type' => 'promotion',
            'title_ar' => 'عرض',
            'title_en' => 'Offer',
            'body_ar' => 'نص',
            'body_en' => 'Body',
            'audience' => 'all',
            'app' => 'customer',
            'target_channel' => 'b2c',
            'delivery_channel' => 'in_app',
            'popup_frequency' => 'once_per_session',
            'popup_cta_target' => '/admin/users',
            'store_id' => $storeId,
            'schedule_kind' => 'once',
            'timezone' => 'Asia/Kuwait',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
            'next_run_at' => now(),
            'status' => 'active',
        ]);

        $this->getJson(
            "/api/v1/notification-campaign-popups?channel=b2c&store_id={$storeId}&locale=en&install_id=dest-runtime",
        )
            ->assertOk()
            ->assertJsonPath('data.0.cta_target', null);
    }

    public function test_destination_catalog_is_bilingual_and_locked_to_registered_route_authorities(): void
    {
        $catalog = app(EngagementDestinationCatalog::class);
        $authority = file_get_contents(base_path('../docs/execution/UI_ROUTE_AUTHORITY.json'));
        $driverNavigation = file_get_contents(base_path('../apps/driver_app/lib/navigation.dart'));

        $this->assertIsString($authority);
        $this->assertIsString($driverNavigation);

        foreach ($catalog->uiDefinitions() as $destination) {
            if ($destination['code'] === 'none') {
                continue;
            }

            if (in_array('customer', $destination['apps'], true)) {
                $channel = in_array('b2c', $destination['channels'], true) ? 'b2c' : 'b2b';
                $target = $catalog->resolve($destination['code'], 'customer', $channel, 42);
                $registeredTarget = preg_replace('#^/retail/42/home$#', '/retail/:store/home', (string) $target);
                $this->assertStringContainsString((string) $registeredTarget, $authority);

                continue;
            }

            if (in_array('driver', $destination['apps'], true)) {
                $target = $catalog->resolve($destination['code'], 'driver', 'b2c');
                $this->assertStringContainsString((string) $target, $authority);
                $this->assertStringContainsString((string) $target, $driverNavigation);
            }
        }

        $vanCodes = collect($catalog->uiDefinitions())
            ->filter(fn (array $destination): bool => in_array('van', $destination['apps'], true))
            ->pluck('code')
            ->values()
            ->all();
        $this->assertSame(['none'], $vanCodes);

        $arabic = $this->superAdmin('ar', 'dest-ar@example.test');
        $this->actingAs($arabic)
            ->get('/admin/live-ads')
            ->assertOk()
            ->assertSee('وجهة الإجراء')
            ->assertSee('العميل · العروض');
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function liveAdPayload(array $overrides = []): array
    {
        return [
            'channel' => 'b2b',
            'name' => 'Destination ad',
            'title_ar' => 'عرض',
            'title_en' => 'Offer',
            'body_ar' => 'نص',
            'body_en' => 'Body',
            'frequency' => 'once_per_session',
            'priority' => 10,
            'is_dismissible' => 1,
            'is_active' => 1,
            ...$overrides,
        ];
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function campaignPayload(array $overrides = []): array
    {
        return [
            'name' => 'Destination campaign',
            'title_ar' => 'عرض اليوم',
            'title_en' => 'Today offer',
            'body_ar' => 'خصم خاص',
            'body_en' => 'Special discount',
            'audience' => 'customer',
            'app' => 'customer',
            'target_channel' => 'b2b',
            'delivery_channel' => 'both',
            'popup_frequency' => 'once_per_session',
            'schedule_kind' => 'once',
            'starts_at' => '2026-10-16T10:00',
            ...$overrides,
        ];
    }

    private function store(string $type, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $type)->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'advertising_enabled' => true,
            'live_ads_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function superAdmin(string $locale, string $email): User
    {
        $user = User::query()->create([
            'name' => 'Destination Admin',
            'email' => $email,
            'password' => 'password',
            'locale' => $locale,
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }
}
