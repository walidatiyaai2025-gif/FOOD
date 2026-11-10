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

final class V2SidebarSearchAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_search_indexes_only_authorized_bilingual_wholesale_sidebar_links(): void
    {
        $user = $this->globalUser('B2B_ADMIN');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $user->forceFill(['locale' => $locale])->save();

            $response = $this->actingAs($user->fresh())
                ->get(route('admin.b2b.dashboard'))
                ->assertOk()
                ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'">', false);

            $html = (string) $response->getContent();
            $this->assertStringContainsString('data-foodex-nav-search aria-label="'.__('admin.sidebar_search').'"', $html);
            $this->assertStringContainsString('role="search"', $html);
            $this->assertStringContainsString('data-foodex-nav-search-empty role="status" aria-live="polite" hidden', $html);
            $this->assertStringContainsString(__('sidebar_admin.search_empty'), $html);
            $this->assertStringContainsString('.foodex-nav-link[hidden],.foodex-nav-subchildren[hidden]', $html);
            $this->assertStringContainsString("event.stopPropagation();", $html);
            $this->assertStringContainsString("const updateSearch = () => {", $html);
            $this->assertRenderedTargetsMatchAuthorizedNavigation($user->fresh(), $html);
        }
    }

    public function test_search_cannot_reveal_role_hidden_link_or_expand_effective_permissions(): void
    {
        $user = $this->globalUser('B2B_ADMIN');
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $catalog = collect(app(AdminNavigation::class)->groupsFor($user))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->firstWhere('key', 'catalog_management');

        $this->assertNotNull($catalog);
        $catalogUrl = route($catalog['route'], $catalog['params']);
        $this->assertTrue($user->hasPermission('catalog.view'));
        DB::table('admin_sidebar_role_visibility')->insert([
            'role_id' => $role->id,
            'item_key' => 'catalog_management',
            'is_visible' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('admin.b2b.dashboard'))->assertOk();
        $rendered = $this->renderedNavHrefs((string) $response->getContent());

        $this->assertNotContains($catalogUrl, $rendered);
        $this->assertRenderedTargetsMatchAuthorizedNavigation($user, (string) $response->getContent());
        $this->assertTrue($user->fresh()->hasPermission('catalog.view'), 'Search visibility cannot mutate permission state.');
    }

    public function test_retail_search_never_indexes_wholesale_destinations_or_foreign_store(): void
    {
        $ownedStore = $this->retailStore('V2-P161-OWN');
        $foreignStore = $this->retailStore('V2-P161-FOREIGN');
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $ownedStore,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $user->forceFill(['locale' => $locale])->save();
            $response = $this->actingAs($user->fresh())
                ->get(route('admin.b2c.module', ['module' => 'dashboard', 'store_id' => $ownedStore]))
                ->assertOk();
            $html = (string) $response->getContent();
            $hrefs = $this->renderedNavHrefs($html);

            $this->assertNotContains(route('admin.b2b.dashboard'), $hrefs);
            $this->assertRenderedTargetsMatchAuthorizedNavigation($user->fresh(), $html);
            $this->actingAs($user->fresh())
                ->get(route('admin.b2b.dashboard'))
                ->assertForbidden();
            $this->actingAs($user->fresh())
                ->get(route('admin.b2c.module', ['module' => 'dashboard', 'store_id' => $foreignStore]))
                ->assertNotFound();
        }
    }

    public function test_inactive_or_unassigned_actor_never_receives_a_searchable_wholesale_sidebar(): void
    {
        $unassigned = User::factory()->create(['is_active' => true]);
        $inactive = $this->globalUser('B2B_ADMIN');
        $inactive->forceFill(['is_active' => false])->save();

        foreach ([$unassigned, $inactive] as $user) {
            $this->assertSame([], app(AdminNavigation::class)->groupsFor($user->fresh()));
            $this->actingAs($user->fresh())->get(route('admin.b2b.dashboard'))->assertForbidden();
        }
    }

    private function assertRenderedTargetsMatchAuthorizedNavigation(User $user, string $html): void
    {
        $expected = [];
        $append = static function (array $items) use (&$append, &$expected): void {
            foreach ($items as $item) {
                $expected[] = route($item['route'], $item['params']);
                $append((array) ($item['children'] ?? []));
            }
        };
        foreach (app(AdminNavigation::class)->groupsFor($user) as $group) {
            $append((array) $group['children']);
        }

        $this->assertNotEmpty($expected);
        $this->assertEqualsCanonicalizing($expected, $this->renderedNavHrefs($html));
    }

    /** @return list<string> */
    private function renderedNavHrefs(string $html): array
    {
        $this->assertSame(1, preg_match('/<nav class="foodex-nav"[^>]*>(.*?)<\/nav>/s', $html, $nav));
        preg_match_all('/<a class="foodex-nav-link[^"]*" href="([^"]+)"/', $nav[1], $matches);

        return array_map(
            static fn (string $href): string => html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $matches[1],
        );
    }

    private function globalUser(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
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
