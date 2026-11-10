<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P145: the Sidebar is a locale-aware projection, not a security boundary.
 * Exercise registered URLs and actual HTTP authorization in addition to markup.
 */
final class V2SidebarLocaleResponsiveRouteQaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authorized_navigation_has_registered_non_duplicate_destinations_in_both_locales(): void
    {
        $user = $this->wholesaleAdmin();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $user->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $groups = app(AdminNavigation::class)->groupsFor($user);
            $this->assertNotEmpty($groups);
            $visibleKeys = [];

            foreach ($groups as $group) {
                $this->assertNotEmpty($group['children'], 'A blank navigation group must not be rendered.');
                $this->assertNotSame($group['label'], __($group['label']), 'Missing group translation: '.$group['label']);

                foreach ($group['children'] as $item) {
                    $this->assertTrue(Route::has($item['route']), 'Dead Sidebar route: '.$item['route']);
                    $this->assertIsString(route($item['route'], $item['params']));
                    $this->assertNotEmpty(parse_url(route($item['route'], $item['params']), PHP_URL_PATH));
                    $this->assertNotContains($item['key'], $visibleKeys, 'Duplicated Sidebar key: '.$item['key']);
                    $visibleKeys[] = $item['key'];
                }
            }

            $this->assertContains('catalog_management', $visibleKeys);
            $this->assertNotContains('sidebar_role_access', $visibleKeys);
            $this->assertSame($locale === 'ar' ? 'المنتجات والكتالوج' : 'Catalog', __('admin.nav_groups.catalog'));

            $this->actingAs($user)
                ->get(route('admin.b2b.dashboard'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('lang="'.$locale.'"', false);
        }
    }

    public function test_authorized_dashboard_renders_mobile_navigation_accessibility_contract_in_both_directions(): void
    {
        $user = $this->wholesaleAdmin();

        foreach (['ar', 'en'] as $locale) {
            $user->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $html = $this->actingAs($user)
                ->get(route('admin.b2b.dashboard'))
                ->assertOk()
                ->getContent();

            $this->assertIsString($html);
            $this->assertStringContainsString('data-foodex-nav', $html);
            $this->assertStringContainsString('data-foodex-sidebar-toggle', $html);
            $this->assertStringContainsString('foodex-mobile-nav-launcher', $html);
            $this->assertStringContainsString('@media(max-width:767px)', $html);
            $this->assertStringContainsString('html[dir=ltr]', $html);
            $this->assertStringContainsString("launcher.setAttribute('aria-controls', shell.id)", $html);
            $this->assertStringContainsString("launcher.setAttribute('aria-expanded', 'false')", $html);
            $this->assertStringContainsString("event.key === 'Escape'", $html);
            $this->assertStringContainsString("event.key !== 'Tab'", $html);
            $this->assertStringContainsString("setBackgroundInert(true)", $html);
            $this->assertStringContainsString('href="'.route('admin.catalog.index').'"', $html);
            $this->assertStringNotContainsString('href="'.route('admin.sidebar-access.roles').'"', $html);
        }
    }

    public function test_hidden_permission_and_foreign_channel_stay_denied_by_real_http_routes(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $user = $this->wholesaleAdmin($locale);

            $this->assertTrue($user->hasPermission('catalog.view'));
            $this->assertFalse($user->hasPermission('roles.manage'));

            $this->actingAs($user)
                ->get(route('admin.catalog.index'))
                ->assertOk();

            // A hidden management entry is not a substitute for a server 403.
            $this->actingAs($user)
                ->get(route('admin.sidebar-access.roles'))
                ->assertForbidden();

            $this->actingAs($user)
                ->get(route('admin.sidebar-layout.index'))
                ->assertForbidden();

            // The user has Wholesale access, not unrestricted Retail access.
            $this->actingAs($user)
                ->get(route('admin.b2c.dashboard'))
                ->assertForbidden();
        }
    }

    private function wholesaleAdmin(string $locale = 'en'): User
    {
        $user = User::factory()->create([
            'is_active' => true,
            'locale' => $locale,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());

        return $user;
    }
}
