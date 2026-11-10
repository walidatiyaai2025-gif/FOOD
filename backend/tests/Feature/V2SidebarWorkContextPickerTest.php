<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use App\Support\V2SidebarContextLinks;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P160: the Dashboard context picker reuses only already-authorized links.
 * Changing context must never create a permission, channel or store grant.
 */
final class V2SidebarWorkContextPickerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_wholesale_actor_sees_only_authorized_workspaces_with_locale_and_valid_http_destinations(): void
    {
        $actor = $this->globalUser('B2B_ADMIN');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $html = (string) $this->actingAs($actor->fresh())
                ->get(route('admin.b2b.dashboard'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->getContent();

            $this->assertSame(1, substr_count($html, 'data-foodex-work-context'));
            $this->assertStringContainsString('aria-label="'.__('sidebar_contexts.title').'"', $html);
            $this->assertStringContainsString('data-work-context="wholesale"', $html);
            $this->assertStringContainsString(__('sidebar_contexts.wholesale'), $html);
            $this->assertStringNotContainsString('data-work-context="retail"', $html);
            $this->assertStringNotContainsString('data-work-context="restaurant"', $html);

            $this->assertVisiblePickerLinksComeFromAuthorizedNavigation($actor->fresh(), $html);
            $this->actingAs($actor->fresh())->get(route('admin.b2b.dashboard'))->assertOk();
        }
    }

    public function test_retail_store_actor_has_real_scoped_retail_link_but_no_wholesale_or_foreign_store_access(): void
    {
        $owned = $this->retailStore('P160-OWN');
        $foreign = $this->retailStore('P160-FOREIGN');
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $owned,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $html = (string) $this->actingAs($actor->fresh())
                ->get(route('admin.b2c.module', ['module' => 'dashboard', 'store_id' => $owned]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->getContent();

            $this->assertStringContainsString('data-work-context="retail"', $html);
            $this->assertStringContainsString(__('sidebar_contexts.retail'), $html);
            $this->assertStringNotContainsString('data-work-context="wholesale"', $html);
            $this->assertStringNotContainsString('data-work-context="platform"', $html);
            $this->assertStringNotContainsString('data-work-context="restaurant"', $html);
            $this->assertVisiblePickerLinksComeFromAuthorizedNavigation($actor->fresh(), $html);

            $this->actingAs($actor->fresh())->get(route('admin.b2b.dashboard'))->assertForbidden();
            $this->actingAs($actor->fresh())
                ->get(route('admin.b2c.module', ['module' => 'dashboard', 'store_id' => $foreign]))
                ->assertNotFound();
        }
    }

    public function test_picker_never_invents_contexts_or_routes_outside_final_authorized_menu(): void
    {
        $picker = app(V2SidebarContextLinks::class);

        $this->assertSame([], $picker->fromAuthorizedGroups([]));
        $this->assertSame([], $picker->fromAuthorizedGroups([[
            'key' => 'overview',
            'children' => [[
                'key' => 'forged_platform_page',
                'route' => 'admin.b2b.module',
                'params' => ['module' => 'dashboard'],
            ]],
        ]]));

        $actor = $this->globalUser('SUPER_ADMIN');
        $links = $picker->fromAuthorizedGroups(app(AdminNavigation::class)->groupsFor($actor));
        $keys = array_column($links, 'context');

        $this->assertSame(array_values(array_unique($keys)), $keys);
        $this->assertContains('platform', $keys);
        $this->assertContains('wholesale', $keys);

        foreach ($links as $link) {
            $this->assertTrue(Route::has($link['route']));
            $this->assertIsArray($link['params']);
        }
    }

    public function test_roleless_and_disabled_users_cannot_render_the_picker_or_open_workspaces(): void
    {
        $roleless = User::factory()->create(['is_active' => true]);
        $disabled = $this->globalUser('B2B_ADMIN');
        $disabled->forceFill(['is_active' => false])->save();

        foreach ([$roleless, $disabled] as $actor) {
            $this->actingAs($actor->fresh())
                ->get(route('admin.b2b.dashboard'))
                ->assertForbidden()
                ->assertDontSee('data-foodex-work-context', false);
        }
    }

    private function assertVisiblePickerLinksComeFromAuthorizedNavigation(User $actor, string $html): void
    {
        app()->forgetInstance(AdminNavigation::class);
        $authorized = app(V2SidebarContextLinks::class)
            ->fromAuthorizedGroups(app(AdminNavigation::class)->groupsFor($actor));

        foreach ($authorized as $context) {
            $url = route($context['route'], $context['params']);
            $this->assertStringContainsString(
                'data-work-context="'.$context['context'].'" href="'.e($url).'"',
                $html,
                $context['context'].' must reuse its already-authorized server destination',
            );
        }
    }

    private function globalUser(string $code): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', $code)->firstOrFail());

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
