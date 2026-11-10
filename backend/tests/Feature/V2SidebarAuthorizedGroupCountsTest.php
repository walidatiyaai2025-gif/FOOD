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

final class V2SidebarAuthorizedGroupCountsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_wholesale_arabic_and_english_counters_count_only_authorized_nested_pages(): void
    {
        $user = $this->globalUser('B2B_ADMIN');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $user->forceFill(['locale' => $locale])->save();

            $html = (string) $this->actingAs($user->fresh())
                ->get(route('admin.b2b.dashboard'))
                ->assertOk()
                ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'">', false)
                ->getContent();

            $this->assertRenderedCountsMatchAuthorizedGroups($user->fresh(), $html);
            $this->assertStringContainsString('class="foodex-nav-group-icon"', $html);
            $this->assertStringContainsString('class="foodex-nav-chevron"', $html);
        }
    }

    public function test_hiding_one_authorized_page_removes_it_from_group_counter_without_changing_permission(): void
    {
        $user = $this->globalUser('B2B_ADMIN');
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();

        $before = app(AdminNavigation::class)->groupsFor($user);
        $catalog = collect($before)->firstWhere('key', 'catalog');
        $this->assertNotNull($catalog);
        $this->assertContains('catalog_management', $this->itemKeys((array) $catalog['children']));
        $this->assertTrue($user->hasPermission('catalog.view'));

        DB::table('admin_sidebar_role_visibility')->insert([
            'role_id' => $role->id,
            'item_key' => 'catalog_management',
            'is_visible' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $after = app(AdminNavigation::class)->groupsFor($user->fresh());
        $postCatalog = collect($after)->firstWhere('key', 'catalog');
        if ($postCatalog !== null) {
            $this->assertNotContains('catalog_management', $this->itemKeys((array) $postCatalog['children']));
            $this->assertSame(
                $this->countItems((array) $catalog['children']) - 1,
                $this->countItems((array) $postCatalog['children']),
            );
        }

        $html = (string) $this->actingAs($user->fresh())
            ->get(route('admin.b2b.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertRenderedCountsMatchAuthorizedGroups($user->fresh(), $html);
        $this->assertTrue($user->fresh()->hasPermission('catalog.view'), 'Presentation cannot revoke or grant a permission.');
    }

    public function test_retail_owned_store_counts_exclude_wholesale_and_foreign_store_destinations(): void
    {
        $own = $this->retailStore('V2-P369-OWN');
        $foreign = $this->retailStore('V2-P369-FOREIGN');
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $own,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $user->forceFill(['locale' => $locale])->save();
            $html = (string) $this->actingAs($user->fresh())
                ->get(route('admin.b2c.module', ['module' => 'dashboard', 'store_id' => $own]))
                ->assertOk()
                ->getContent();

            $this->assertRenderedCountsMatchAuthorizedGroups($user->fresh(), $html);
            $this->assertStringNotContainsString('href="'.route('admin.b2b.dashboard').'"', $html);
            $this->actingAs($user->fresh())->get(route('admin.b2b.dashboard'))->assertForbidden();
            $this->actingAs($user->fresh())
                ->get(route('admin.b2c.module', ['module' => 'dashboard', 'store_id' => $foreign]))
                ->assertNotFound();
        }
    }

    public function test_unassigned_and_inactive_actors_cannot_receive_page_counts(): void
    {
        $unassigned = User::factory()->create(['is_active' => true]);
        $inactive = $this->globalUser('B2B_ADMIN');
        $inactive->forceFill(['is_active' => false])->save();

        foreach ([$unassigned, $inactive] as $user) {
            $this->assertSame([], app(AdminNavigation::class)->groupsFor($user->fresh()));
            $this->actingAs($user->fresh())->get(route('admin.b2b.dashboard'))->assertForbidden();
        }
    }

    private function assertRenderedCountsMatchAuthorizedGroups(User $user, string $html): void
    {
        $this->assertSame(1, preg_match('/<nav class="foodex-nav"[^>]*>(.*?)<\/nav>/s', $html, $nav));
        preg_match_all(
            '/<details class="foodex-nav-group[^"]*" data-group="([^"]+)" data-nav-group="([^"]+)"[^>]*>\s*<summary>(.*?)<\/summary>/s',
            $nav[1],
            $matches,
            PREG_SET_ORDER,
        );

        $expected = app(AdminNavigation::class)->groupsFor($user);
        $this->assertCount(count($expected), $matches);

        foreach ($expected as $index => $group) {
            $match = $matches[$index];
            $this->assertSame((string) $group['key'], $match[1]);
            $this->assertSame((string) $group['key'], $match[2]);

            $count = $this->countItems((array) $group['children']);
            $this->assertGreaterThan(0, $count, 'A group cannot be rendered with zero authorized links.');
            $this->assertSame(1, substr_count($match[3], 'class="foodex-nav-group-icon"'));
            $this->assertSame(1, substr_count($match[3], 'class="foodex-nav-chevron"'));

            $found = preg_match(
                '/<span class="foodex-nav-count" aria-label="([^"]+)" data-authorized-page-count="([0-9]+)">([0-9]+)<\/span>/',
                $match[3],
                $badge,
            );
            if ($count <= 1) {
                $this->assertSame(0, $found, 'Single-link groups should not display a noisy count.');
                continue;
            }

            $this->assertSame(1, $found);
            $this->assertSame($count, (int) $badge[2]);
            $this->assertSame($count, (int) $badge[3]);
            $this->assertSame(
                __('sidebar_admin.authorized_pages_count', ['count' => $count]),
                html_entity_decode($badge[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            );
        }
    }

    private function countItems(array $items): int
    {
        $count = 0;
        foreach ($items as $item) {
            if (isset($item['route'])) {
                $count++;
            }
            $count += $this->countItems((array) ($item['children'] ?? []));
        }

        return $count;
    }

    /** @return list<string> */
    private function itemKeys(array $items): array
    {
        $keys = [];
        foreach ($items as $item) {
            $keys[] = (string) $item['key'];
            array_push($keys, ...$this->itemKeys((array) ($item['children'] ?? [])));
        }

        return $keys;
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
