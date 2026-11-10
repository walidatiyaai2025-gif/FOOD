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

final class V2SidebarBusinessGroupCollapseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_arabic_and_english_sidebar_uses_native_accessible_disclosure_for_only_authorized_groups(): void
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

            $this->assertStringContainsString('class="foodex-nav"', $html);
            $this->assertStringContainsString('const groupStateKey = \'foodex.sidebar.groups.'.$user->id.'\';', $html);
            $this->assertStringContainsString("group.querySelector(':scope > summary')?.addEventListener('click'", $html);
            $this->assertStringContainsString('if (search?.value.trim() || !key) return;', $html);
            $this->assertStringContainsString('typeof savedGroupState[key] === \'boolean\'', $html);
            $this->assertSame(
                $this->authorizedGroupKeys($user->fresh()),
                $this->renderedGroupKeys($html),
            );
            $this->assertSame(substr_count($html, 'class="foodex-nav-chevron"'), count($this->renderedGroupKeys($html)));
        }
    }

    public function test_sidebar_disclosure_preferences_are_isolated_by_authenticated_user_identity(): void
    {
        $first = $this->globalUser('B2B_ADMIN');
        $second = $this->globalUser('B2B_ADMIN');

        $firstHtml = (string) $this->actingAs($first)->get(route('admin.b2b.dashboard'))->assertOk()->getContent();
        $secondHtml = (string) $this->actingAs($second)->get(route('admin.b2b.dashboard'))->assertOk()->getContent();

        $this->assertNotSame($first->id, $second->id);
        $this->assertStringContainsString('foodex.sidebar.groups.'.$first->id, $firstHtml);
        $this->assertStringNotContainsString('foodex.sidebar.groups.'.$second->id, $firstHtml);
        $this->assertStringContainsString('foodex.sidebar.groups.'.$second->id, $secondHtml);
        $this->assertSame($this->authorizedGroupKeys($first), $this->renderedGroupKeys($firstHtml));
        $this->assertSame($this->authorizedGroupKeys($second), $this->renderedGroupKeys($secondHtml));
    }

    public function test_retail_disclosure_never_includes_wholesale_only_groups_or_foreign_store_links(): void
    {
        $owned = $this->retailStore('V2-P162-OWN');
        $foreign = $this->retailStore('V2-P162-FOREIGN');
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $owned,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $user->forceFill(['locale' => $locale])->save();
            $html = (string) $this->actingAs($user->fresh())
                ->get(route('admin.b2c.module', ['module' => 'dashboard', 'store_id' => $owned]))
                ->assertOk()
                ->getContent();

            $this->assertSame($this->authorizedGroupKeys($user->fresh()), $this->renderedGroupKeys($html));
            $this->assertStringNotContainsString('href="'.route('admin.b2b.dashboard').'"', $html);
            $this->actingAs($user->fresh())->get(route('admin.b2b.dashboard'))->assertForbidden();
            $this->actingAs($user->fresh())
                ->get(route('admin.b2c.module', ['module' => 'dashboard', 'store_id' => $foreign]))
                ->assertNotFound();
        }
    }

    public function test_roleless_and_inactive_users_cannot_receive_the_sidebar_disclosure_navigation(): void
    {
        $roleless = User::factory()->create(['is_active' => true]);
        $inactive = $this->globalUser('B2B_ADMIN');
        $inactive->forceFill(['is_active' => false])->save();

        foreach ([$roleless, $inactive] as $user) {
            $this->assertSame([], app(AdminNavigation::class)->groupsFor($user->fresh()));
            $this->actingAs($user->fresh())->get(route('admin.b2b.dashboard'))->assertForbidden();
        }
    }

    /** @return list<string> */
    private function authorizedGroupKeys(User $user): array
    {
        return collect(app(AdminNavigation::class)->groupsFor($user))
            ->pluck('key')
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function renderedGroupKeys(string $html): array
    {
        $this->assertSame(1, preg_match('/<nav class="foodex-nav"[^>]*>(.*?)<\/nav>/s', $html, $nav));
        preg_match_all(
            '/<details class="foodex-nav-group[^"]*" data-group="([^"]+)" data-nav-group="([^"]+)"[^>]*>\s*<summary>/s',
            $nav[1],
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $match) {
            $this->assertSame($match[1], $match[2]);
        }

        return array_column($matches, 1);
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
