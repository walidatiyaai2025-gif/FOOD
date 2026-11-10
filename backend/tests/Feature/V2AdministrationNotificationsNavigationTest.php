<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class V2AdministrationNotificationsNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_notifications_center_is_discoverable_only_under_administration_in_both_locales(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN');
        $this->assertTrue($admin->hasPermission('notifications.manage'));
        $this->assertSame('administration', config('admin_sidebar.items.notifications_center.group'));
        $this->assertSame('advertising', config('admin_sidebar.items.notification_campaigns.group'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $admin->forceFill(['locale' => $locale])->save();

            $groups = collect(app(AdminNavigation::class)->groupsFor($admin->fresh()));
            $administration = $groups->firstWhere('key', 'administration');
            $this->assertNotNull($administration);

            $notifications = collect($administration['children'])->firstWhere('key', 'notifications_center');
            $this->assertNotNull($notifications);
            $this->assertSame('admin.notifications.index', $notifications['route']);
            $this->assertSame([], $notifications['params']);
            $this->assertSame(__('notifications.title'), $notifications['label_text'] ?? __($notifications['label']));

            $overview = $groups->firstWhere('key', 'overview');
            $this->assertNotNull($overview);
            $this->assertNotContains('notifications_center', array_column($overview['children'], 'key'));

            $this->actingAs($admin->fresh())
                ->get(route('admin.notifications.index'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__('notifications.title'));
        }
    }

    public function test_other_roles_and_users_without_roles_cannot_see_or_open_notifications_management(): void
    {
        $wholesale = $this->userWithRole('B2B_ADMIN');
        $noRole = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            foreach ([$wholesale, $noRole] as $actor) {
                $actor->forceFill(['locale' => $locale])->save();
                $keys = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
                    ->flatMap(static fn (array $group): array => $group['children'])
                    ->pluck('key')
                    ->all();

                $this->assertNotContains('notifications_center', $keys);
                $this->actingAs($actor->fresh())
                    ->get(route('admin.notifications.index'))
                    ->assertForbidden();
            }
        }
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }
}
