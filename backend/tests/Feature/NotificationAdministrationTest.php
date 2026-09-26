<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationAdministrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_super_admin_manages_bilingual_notification_and_publish_is_audited(): void
    {
        $admin = $this->user('admin-notifications@example.test');
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $this->actingAs($admin)->get('/admin/notifications')->assertOk();

        $this->actingAs($admin)->post('/admin/notifications', [
            'title_ar' => 'طلبك في الطريق',
            'title_en' => 'Your order is on the way',
            'body_ar' => 'سيصل قريباً',
            'body_en' => 'It will arrive soon',
            'type' => 'order',
            'audience' => 'customer',
            'app' => 'customer',
            'target_channel' => 'b2c',
            'channel' => 'both',
        ])->assertRedirect();

        $notification = Notification::query()->firstOrFail();
        $this->assertSame('draft', $notification->status);

        $this->actingAs($admin)->post("/admin/notifications/{$notification->id}/publish")->assertRedirect();
        $this->assertDatabaseHas('notifications', ['id' => $notification->id, 'status' => 'published']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'notification.created']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'notification.published']);
    }

    public function test_customer_receives_requested_locale_and_read_state_is_user_owned(): void
    {
        $ar = $this->user('customer-ar@example.test', 'ar');
        Customer::query()->create(['user_id' => $ar->id, 'type' => 'b2c', 'name' => 'AR', 'email' => $ar->email]);
        $en = $this->user('customer-en@example.test', 'en');
        Customer::query()->create(['user_id' => $en->id, 'type' => 'b2c', 'name' => 'EN', 'email' => $en->email]);

        $notification = Notification::query()->create([
            'channel' => 'push', 'type' => 'general',
            'title' => 'مرحبا', 'body' => 'نص',
            'title_ar' => 'مرحبا', 'title_en' => 'Hello',
            'body_ar' => 'نص عربي', 'body_en' => 'English body',
            'audience' => 'customer', 'app' => 'customer', 'target_channel' => 'b2c',
            'status' => 'published', 'published_at' => now(),
        ]);

        Sanctum::actingAs($en);
        $this->getJson('/api/v1/notifications?locale=en')
            ->assertOk()->assertJsonPath('data.0.title', 'Hello')->assertJsonPath('data.0.read_at', null);
        $this->postJson("/api/v1/notifications/{$notification->id}/read")->assertNoContent();
        $this->getJson('/api/v1/notifications?locale=en')->assertJsonPath('data.0.read_at', fn ($value) => is_string($value));

        Sanctum::actingAs($ar);
        $this->getJson('/api/v1/notifications?locale=ar')
            ->assertOk()->assertJsonPath('data.0.title', 'مرحبا')->assertJsonPath('data.0.read_at', null);
    }

    public function test_management_dashboard_live_feed_tracks_unread_and_read_state(): void
    {
        $admin = $this->user('live-admin@example.test', 'en');
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $first = Notification::query()->create([
            'channel' => 'in_app', 'type' => 'order',
            'title' => 'طلب جديد', 'body' => 'تم إنشاء طلب',
            'title_ar' => 'طلب جديد', 'title_en' => 'New order',
            'body_ar' => 'تم إنشاء طلب', 'body_en' => 'An order was created',
            'audience' => 'all', 'app' => 'all', 'target_channel' => 'all',
            'status' => 'published', 'published_at' => now(),
        ]);
        $second = Notification::query()->create([
            'channel' => 'in_app', 'type' => 'delivery',
            'title' => 'تحديث التوصيل', 'body' => 'تم تحديث الحالة',
            'title_ar' => 'تحديث التوصيل', 'title_en' => 'Delivery update',
            'body_ar' => 'تم تحديث الحالة', 'body_en' => 'Delivery status changed',
            'audience' => 'all', 'app' => 'all', 'target_channel' => 'all',
            'status' => 'published', 'published_at' => now(),
        ]);

        $this->actingAs($admin)->getJson('/admin/notifications/live')
            ->assertOk()
            ->assertJsonPath('meta.unread_count', 2)
            ->assertJsonPath('data.0.title', 'Delivery update');

        $this->actingAs($admin)->postJson("/admin/notifications/{$second->id}/read")
            ->assertNoContent();
        $this->actingAs($admin)->getJson("/admin/notifications/live?after_id={$first->id}")
            ->assertOk()
            ->assertJsonPath('meta.unread_count', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.read', true);

        $this->actingAs($admin)->postJson('/admin/notifications/read-all')->assertNoContent();
        $this->actingAs($admin)->getJson('/admin/notifications/live')
            ->assertOk()
            ->assertJsonPath('meta.unread_count', 0);
    }

    public function test_management_live_feed_does_not_leak_user_targeted_notification(): void
    {
        $admin = $this->user('live-scope-admin@example.test');
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());
        $other = $this->user('live-other@example.test');

        Notification::query()->create([
            'channel' => 'in_app', 'type' => 'general',
            'title' => 'خاص', 'body' => 'خاص',
            'title_ar' => 'خاص', 'title_en' => 'Private',
            'body_ar' => 'خاص', 'body_en' => 'Private body',
            'audience' => 'user', 'user_id' => $other->id,
            'app' => 'all', 'target_channel' => 'all',
            'status' => 'published', 'published_at' => now(),
        ]);

        $this->actingAs($admin)->getJson('/admin/notifications/live')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.unread_count', 0);
    }

    public function test_b2b_customer_cannot_receive_b2c_targeted_notification(): void
    {
        $user = $this->user('b2b-notification@example.test');
        Customer::query()->create(['user_id' => $user->id, 'type' => 'b2b', 'name' => 'B2B', 'email' => $user->email]);

        Notification::query()->create([
            'channel' => 'push', 'type' => 'general', 'title' => 'AR', 'body' => 'AR',
            'title_ar' => 'AR', 'title_en' => 'EN', 'body_ar' => 'AR', 'body_en' => 'EN',
            'audience' => 'customer', 'app' => 'customer', 'target_channel' => 'b2c',
            'status' => 'published', 'published_at' => now(),
        ]);

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(0, 'data');
    }

    private function user(string $email, string $locale = 'ar'): User
    {
        return User::query()->create(['name' => $email, 'email' => $email, 'password' => 'password', 'locale' => $locale, 'is_active' => true]);
    }
}
