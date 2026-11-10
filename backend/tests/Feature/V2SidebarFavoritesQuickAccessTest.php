<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2SidebarFavoritesQuickAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_top_favorite_shortcuts_reuse_authorized_business_groups_in_both_locales(): void
    {
        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor = $this->globalUser('B2B_ADMIN', $locale);
            $response = $this->actingAs($actor)->get(route('admin.b2b.dashboard'))->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-foodex-nav-favorites', false)
                ->assertSee('data-foodex-nav-favorites-list', false)
                ->assertSee(__('sidebar_personalization.favorites_title', [], $locale));

            $html = (string) $response->getContent();
            $this->assertStringContainsString("nav.before(favoritesPanel)", $html);
            $this->assertStringContainsString("favoritesList.replaceChildren()", $html);

            preg_match_all('/data-nav-group="([^"]+)"/', $html, $matches);
            $this->assertEqualsCanonicalizing(
                collect(app(AdminNavigation::class)->groupsFor($actor))->pluck('key')->all(),
                $matches[1],
            );
            $this->assertStringNotContainsString('href="'.route('admin.sidebar-access.roles').'"', $html);
            $this->actingAs($actor)->get(route('admin.sidebar-access.roles'))->assertForbidden();
        }
    }

    public function test_favorite_quick_access_does_not_expose_other_retail_store_or_wholesale_routes(): void
    {
        $ownedStore = $this->retailStore('V2-FAVORITES-OWN');
        $foreignStore = $this->retailStore('V2-FAVORITES-FOREIGN');
        $actor = User::factory()->create(['locale' => 'ar', 'is_active' => true]);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $ownedStore,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($actor)
            ->get(route('admin.b2c.module', ['module' => 'dashboard', 'store_id' => $ownedStore]))
            ->assertOk()->assertSee('data-foodex-nav-favorites', false);

        $html = (string) $response->getContent();
        $this->assertStringNotContainsString('href="'.route('admin.b2b.dashboard').'"', $html);
        $this->actingAs($actor)->get(route('admin.b2b.dashboard'))->assertForbidden();
        $this->actingAs($actor)
            ->get(route('admin.b2c.module', ['module' => 'dashboard', 'store_id' => $foreignStore]))
            ->assertNotFound();
    }

    public function test_favorite_controls_never_build_navigation_urls_or_accept_untrusted_group_keys(): void
    {
        $component = file_get_contents(resource_path('views/admin/_sidebar-personalization.blade.php'));
        $this->assertIsString($component);
        $this->assertStringContainsString("querySelectorAll(':scope > details[data-nav-group]')", $component);
        $this->assertStringContainsString('authorized.has(value)', $component);
        $this->assertStringContainsString("group.querySelector('a.foodex-nav-link')", $component);
        $this->assertStringContainsString('document.createElement(\'button\')', $component);
        $this->assertStringContainsString('button.textContent = title', $component);
        $this->assertStringContainsString('group.open = true', $component);
        $this->assertStringContainsString("favoritesPanel.hidden = favoritesList.childElementCount === 0", $component);
        $this->assertStringContainsString("search.dispatchEvent(new Event('input', {bubbles:true}))", $component);
        $this->assertStringContainsString('min-height:44px', $component);
        $this->assertStringNotContainsString("createElement('a')", $component);
        $this->assertStringNotContainsString('.href =', $component);
        $this->assertStringNotContainsString('fetch(', $component);

        foreach (['ar', 'en'] as $locale) {
            foreach (['favorites_title', 'open_favorite', 'favorite', 'unfavorite'] as $key) {
                $this->assertNotSame(
                    'sidebar_personalization.'.$key,
                    trans('sidebar_personalization.'.$key, [], $locale),
                );
            }
        }
    }

    private function globalUser(string $roleCode, string $locale): User
    {
        $actor = User::factory()->create(['locale' => $locale, 'is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail()->id);

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
}
