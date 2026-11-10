<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class V2SidebarPersonalizationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_personal_sidebar_preferences_cannot_grant_denied_routes_in_arabic_or_english(): void
    {
        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $user = User::factory()->create(['locale' => $locale, 'is_active' => true]);
            $user->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());

            $visible = collect(app(AdminNavigation::class)->groupsFor($user))
                ->flatMap(static fn (array $group): array => $group['children'])
                ->pluck('key')
                ->all();

            $this->assertNotContains('sidebar_role_access', $visible);
            $this->assertNotContains('sidebar_layout', $visible);

            $this->actingAs($user)
                ->get(route('admin.b2b.dashboard'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-foodex-nav-personalization', false)
                ->assertSee('sidebar.personal.v1', false)
                ->assertDontSee('href="'.route('admin.sidebar-access.roles').'"', false);

            // The actual controller retains permission and channel/tenant denial.
            $this->actingAs($user)
                ->get(route('admin.sidebar-access.roles'))
                ->assertForbidden();
            $this->actingAs($user)
                ->get(route('admin.sidebar-layout.index'))
                ->assertForbidden();
            $this->actingAs($user)
                ->get(route('admin.b2c.dashboard'))
                ->assertForbidden();
        }
    }

    public function test_client_prefs_can_only_reorder_existing_authorized_groups(): void
    {
        $component = file_get_contents(resource_path('views/admin/_sidebar-personalization.blade.php'));
        $sidebar = file_get_contents(resource_path('views/admin/_sidebar.blade.php'));
        $this->assertIsString($component);
        $this->assertIsString($sidebar);

        $this->assertStringContainsString("@include('admin._sidebar-personalization')", $sidebar);
        $this->assertStringContainsString("querySelectorAll(':scope > details[data-nav-group]')", $component);
        $this->assertStringContainsString('authorized.has(value)', $component);
        $this->assertStringContainsString('nav.appendChild(group)', $component);
        $this->assertStringContainsString('group.querySelector(\'[aria-current="page"]\')', $component);
        $this->assertStringNotContainsString("createElement('a')", $component);
        $this->assertStringNotContainsString('.href =', $component);
        $this->assertStringNotContainsString('fetch(', $component);

        foreach (['ar', 'en'] as $locale) {
            foreach (['customize', 'reset', 'favorite', 'unfavorite', 'up', 'down', 'hide', 'show'] as $key) {
                $this->assertNotSame(
                    'sidebar_personalization.'.$key,
                    trans('sidebar_personalization.'.$key, [], $locale)
                );
            }
        }
    }
}
