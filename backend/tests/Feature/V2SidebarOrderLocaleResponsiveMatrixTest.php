<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P047: one authoritative, permission-filtered Sidebar order for AR/EN,
 * reused by both desktop and mobile web without duplicate route policies.
 */
final class V2SidebarOrderLocaleResponsiveMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_configured_group_and_item_order_is_stable_across_locales_and_rendered_sidebar(): void
    {
        $this->configureGroup('catalog', -200, 'الكتالوج التجريبي', 'Catalog QA');
        $this->configureGroup('overview', -100, 'نظرة عامة تجريبية', 'Overview QA');
        $this->configureItem('b2b_products', -200);
        $this->configureItem('catalog_management', -100);

        $user = $this->wholesaleAdmin();
        $expectedGroups = null;
        $expectedItems = null;

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $user->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $groups = app(AdminNavigation::class)->groupsFor($user);
            $groupKeys = array_column($groups, 'key');
            $this->assertSame(['catalog', 'overview'], array_slice($groupKeys, 0, 2));
            $this->assertSame($expectedGroups ?? $groupKeys, $groupKeys);
            $expectedGroups = $groupKeys;

            $catalog = collect($groups)->firstWhere('key', 'catalog');
            $this->assertIsArray($catalog);
            $this->assertSame(
                $locale === 'ar' ? 'الكتالوج التجريبي' : 'Catalog QA',
                $catalog['label_text'],
            );

            $itemKeys = array_column($catalog['children'], 'key');
            $this->assertSame(['b2b_products', 'catalog_management'], array_slice($itemKeys, 0, 2));
            $this->assertSame($expectedItems ?? $itemKeys, $itemKeys);
            $expectedItems = $itemKeys;

            $response = $this->actingAs($user)
                ->get(route('admin.b2b.dashboard'))
                ->assertOk()
                ->assertSee('lang="'.$locale.'"', false)
                ->assertSee('dir="'.$direction.'"', false);

            $html = $response->getContent();
            $this->assertIsString($html);
            $this->assertSame(1, preg_match_all('/<nav[^>]*data-foodex-nav(?:\s|>)/', $html));
            $this->assertSame(1, preg_match('/<nav class="foodex-nav"[^>]*>(.*?)<\/nav>/s', $html, $match));
            $navHtml = $match[1];

            $this->assertOrder($navHtml, 'data-nav-group="catalog"', 'data-nav-group="overview"');
            $this->assertOrder(
                $navHtml,
                'href="'.route('admin.b2b.module', ['module' => 'products']).'"',
                'href="'.route('admin.catalog.index').'"',
            );
            $this->assertStringContainsString($catalog['label_text'], $navHtml);

            // One ordered, server-authorized nav tree is shared by desktop and
            // the responsive drawer; breakpoints do not construct new links.
            $this->assertStringContainsString('@media(max-width:767px)', $html);
            $this->assertStringContainsString('foodex-mobile-nav-launcher', $html);
            $this->assertStringContainsString('html[dir=ltr]', $html);
            $this->assertStringContainsString('data-foodex-sidebar-toggle', $html);
            $this->assertStringContainsString('aria-controls', $html);
            $this->assertStringNotContainsString('href="'.route('admin.sidebar-access.roles').'"', $navHtml);

            $this->actingAs($user)
                ->get(route('admin.catalog.index'))
                ->assertOk();

            $this->actingAs($user)
                ->get(route('admin.sidebar-access.roles'))
                ->assertForbidden();
        }
    }

    public function test_reordered_visible_layout_does_not_grant_catalog_http_or_navigation_to_unprivileged_user(): void
    {
        $this->configureGroup('catalog', -200, 'الكتالوج التجريبي', 'Catalog QA');
        $this->configureItem('catalog_management', -200);

        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        foreach (['ar', 'en'] as $locale) {
            $user->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $keys = collect(app(AdminNavigation::class)->groupsFor($user))
                ->flatMap(static fn (array $group): array => $group['children'])
                ->pluck('key')
                ->all();

            $this->assertNotContains('catalog_management', $keys);
            $this->assertNotContains('b2b_products', $keys);
            $this->assertFalse($user->hasPermission('catalog.view'));

            $this->actingAs($user)
                ->get(route('admin.catalog.index'))
                ->assertForbidden();

            $this->actingAs($user)
                ->get(route('admin.b2b.module', ['module' => 'products']))
                ->assertForbidden();
        }
    }

    private function configureGroup(string $key, int $order, string $arabic, string $english): void
    {
        DB::table('admin_sidebar_groups')->updateOrInsert(
            ['group_key' => $key],
            [
                'label_ar' => $arabic,
                'label_en' => $english,
                'icon' => '▦',
                'sort_order' => $order,
                'is_enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function configureItem(string $key, int $order): void
    {
        DB::table('admin_sidebar_items')->updateOrInsert(
            ['item_key' => $key],
            [
                'group_key' => 'catalog',
                'parent_key' => null,
                'label_ar' => null,
                'label_en' => null,
                'sort_order' => $order,
                'is_enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function wholesaleAdmin(): User
    {
        $user = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());

        return $user;
    }

    private function assertOrder(string $html, string $first, string $second): void
    {
        $firstPosition = strpos($html, $first);
        $secondPosition = strpos($html, $second);

        $this->assertNotFalse($firstPosition, 'Missing ordered navigation entry: '.$first);
        $this->assertNotFalse($secondPosition, 'Missing ordered navigation entry: '.$second);
        $this->assertLessThan($secondPosition, $firstPosition, 'Navigation order differs from configured server order.');
    }
}
