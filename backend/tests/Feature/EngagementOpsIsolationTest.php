<?php

namespace Tests\Feature;

use App\Models\LiveAd;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EngagementOpsIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_guest_customer_device_can_register_before_login_and_is_upserted_by_token(): void
    {
        $payload = [
            'app' => 'customer',
            'platform' => 'android',
            'environment' => 'production',
            'token' => 'guest-token-before-login',
            'install_id' => 'install-guest-001',
            'target_channel' => 'b2b',
            'locale' => 'ar',
        ];

        $this->postJson('/api/v1/push/devices/guest', $payload)
            ->assertCreated()
            ->assertJsonPath('data.app', 'customer')
            ->assertJsonPath('data.anonymous', true);

        $this->postJson('/api/v1/push/devices/guest', [...$payload, 'locale' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.anonymous', true);

        $this->assertDatabaseCount('push_device_tokens', 1);
        $this->assertDatabaseHas('push_device_tokens', [
            'token_hash' => hash('sha256', 'guest-token-before-login'),
            'install_id' => 'install-guest-001',
            'locale' => 'en',
            'user_id' => null,
        ]);
    }

    public function test_guest_push_registration_rejects_driver_missing_install_and_store_channel_mismatch(): void
    {
        $this->postJson('/api/v1/push/devices/guest', [
            'app' => 'driver',
            'platform' => 'android',
            'environment' => 'production',
            'token' => 'anonymous-driver-token',
            'install_id' => 'anonymous-driver-install',
        ])->assertUnauthorized();

        $this->postJson('/api/v1/push/devices/guest', [
            'app' => 'customer',
            'platform' => 'android',
            'environment' => 'production',
            'token' => 'missing-install-token',
        ])->assertStatus(422);

        $retail = $this->store('B2C', 'ENG-PUSH-RETAIL');

        $this->postJson('/api/v1/push/devices/guest', [
            'app' => 'customer',
            'platform' => 'ios',
            'environment' => 'production',
            'token' => 'wrong-channel-token',
            'install_id' => 'wrong-channel-install',
            'store_id' => $retail,
            'target_channel' => 'b2b',
        ])->assertStatus(422);
    }

    public function test_public_live_ads_are_strictly_store_scoped_scheduled_and_localized(): void
    {
        CarbonImmutable::setTestNow('2026-09-29 12:30:00');

        $retailA = $this->store('B2C', 'ENG-LIVE-A');
        $retailB = $this->store('B2C', 'ENG-LIVE-B');

        $mine = $this->liveAd('b2c', $retailA, 'mine', ['title_en' => 'Store A ad', 'priority' => 10]);
        $this->liveAd('b2c', $retailB, 'foreign', ['title_ar' => 'إعلان المتجر ب', 'priority' => 1]);
        $this->liveAd('b2c', $retailA, 'future', ['starts_at' => now()->addHour(), 'ends_at' => now()->addHours(2)]);
        $this->liveAd('b2c', $retailA, 'expired', ['starts_at' => now()->subHours(2), 'ends_at' => now()->subHour()]);
        $this->liveAd('b2c', $retailA, 'inactive', ['is_active' => false]);
        $this->liveAd('b2b', null, 'wholesale', ['title_en' => 'Wholesale ad']);

        $this->getJson("/api/v1/live-ads?channel=b2c&store_id={$retailA}&locale=en")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('data.0.title', 'Store A ad');

        $this->getJson("/api/v1/live-ads?channel=b2c&store_id={$retailB}&locale=ar")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'إعلان المتجر ب');

        $this->getJson('/api/v1/live-ads?channel=b2b&locale=en')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Wholesale ad');

        DB::table('stores')->where('id', $retailA)->update(['live_ads_enabled' => false]);
        $this->getJson("/api/v1/live-ads?channel=b2c&store_id={$retailA}")->assertNotFound();
    }

    public function test_retail_admin_cannot_toggle_foreign_store_live_ad(): void
    {
        $retailA = $this->store('B2C', 'ENG-ADMIN-A');
        $retailB = $this->store('B2C', 'ENG-ADMIN-B');
        $admin = $this->storeAdmin($retailA, 'engagement-a@example.test');

        $mine = $this->liveAd('b2c', $retailA, 'mine-admin');
        $foreign = $this->liveAd('b2c', $retailB, 'foreign-admin');

        $this->actingAs($admin)->patch("/admin/live-ads/{$foreign->id}/toggle")->assertNotFound();
        $this->actingAs($admin)->patch("/admin/live-ads/{$mine->id}/toggle")->assertRedirect();

        $this->assertDatabaseHas('live_ads', ['id' => $mine->id, 'is_active' => false]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'live_ad.status_changed', 'user_id' => $admin->id]);
    }

    private function store(string $type, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $type)->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'live_ads_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function liveAd(string $channel, ?int $storeId, string $name, array $overrides = []): LiveAd
    {
        return LiveAd::query()->create([
            'scope_key' => $channel === 'b2c' ? 'b2c:'.$storeId : 'b2b',
            'channel' => $channel,
            'store_id' => $storeId,
            'name' => $name,
            'title_ar' => 'عنوان '.$name,
            'title_en' => 'Title '.$name,
            'body_ar' => 'نص '.$name,
            'body_en' => 'Body '.$name,
            'frequency' => 'once_per_install',
            'is_dismissible' => true,
            'priority' => 100,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
            'is_active' => true,
            ...$overrides,
        ]);
    }

    private function storeAdmin(int $storeId, string $email): User
    {
        $user = User::query()->create([
            'name' => 'Retail Engagement Admin',
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }
}
