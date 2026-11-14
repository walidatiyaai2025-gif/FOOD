<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class V2NotificationLiteralSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authorized_literal_search_does_not_expand_wildcards_across_arabic_and_english_fields(): void
    {
        $admin = $this->user('v2-notification-search-admin@example.test');
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        foreach (['title_ar', 'title_en', 'body_ar', 'body_en'] as $index => $field) {
            $literal = "REF-{$index}%_!end";
            $lookalike = "REF-{$index}AB!end";

            $hit = $this->notification("HIT-{$index}");
            $hit->{$field} = $literal;
            $hit->save();

            $decoy = $this->notification("DECOY-{$index}");
            $decoy->{$field} = $lookalike;
            $decoy->save();
        }

        $this->notification('NORMAL-SEARCH-TOKEN');
        $this->notification('PUBLISHED-FILTER', 'published');

        $before = DB::table('notifications')->orderBy('id')->get()->toArray();

        foreach (['ar', 'en'] as $locale) {
            $admin->forceFill(['locale' => $locale])->save();

            foreach (range(0, 3) as $index) {
                $this->actingAs($admin)
                    ->get(route('admin.notifications.index', ['q' => "REF-{$index}%_!end"]))
                    ->assertOk()
                    ->assertSee("HIT-{$index}")
                    ->assertDontSee("DECOY-{$index}");
            }

            $this->actingAs($admin)->get(route('admin.notifications.index', [
                'q' => 'NORMAL-SEARCH',
            ]))->assertOk()->assertSee('NORMAL-SEARCH-TOKEN')
                ->assertDontSee('PUBLISHED-FILTER');

            $this->actingAs($admin)->get(route('admin.notifications.index', [
                'q' => 'PUBLISHED-FILTER',
                'status' => 'draft',
            ]))->assertOk()->assertDontSee('PUBLISHED-FILTER');

            $this->actingAs($admin)->get(route('admin.notifications.index', [
                'q' => 'PUBLISHED-FILTER',
                'status' => 'published',
            ]))->assertOk()->assertSee('PUBLISHED-FILTER');
        }

        $this->assertEquals($before, DB::table('notifications')->orderBy('id')->get()->toArray());
    }

    public function test_non_super_admin_cannot_use_notification_center_search(): void
    {
        $user = $this->user('v2-notification-search-unauthorized@example.test');

        $this->actingAs($user)->get(route('admin.notifications.index', ['q' => '%_!']))
            ->assertForbidden();
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'name' => $email,
            'email' => $email,
            'password' => 'password',
            'locale' => 'ar',
            'is_active' => true,
        ]);
    }

    private function notification(string $tag, string $status = 'draft'): Notification
    {
        return Notification::query()->create([
            'channel' => 'in_app',
            'type' => 'general',
            'title' => $tag,
            'body' => $tag,
            'title_ar' => $tag.' عربي',
            'title_en' => $tag.' English',
            'body_ar' => $tag.' نص عربي',
            'body_en' => $tag.' English body',
            'audience' => 'all',
            'app' => 'dashboard',
            'target_channel' => 'all',
            'status' => $status,
            'published_at' => $status === 'published' ? now() : null,
        ]);
    }
}
