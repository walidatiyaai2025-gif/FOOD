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
 * P174: Overview and Workspaces is a presentation group, not an authorization grant.
 */
final class V2OverviewWorkspacesGroupAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_overview_links_are_registered_unique_and_visible_to_authorized_administrator(): void
    {
        $admin = $this->superAdmin();
        $group = collect(app(AdminNavigation::class)->groupsFor($admin))
            ->firstWhere('key', 'overview');

        $this->assertIsArray($group);
        $this->assertSame('admin.nav_groups.overview', $group['label']);
        $this->assertNotEmpty($group['children']);

        $keys = collect($group['children'])->pluck('key')->all();
        $this->assertSame(array_values(array_unique($keys)), $keys);

        foreach ($group['children'] as $item) {
            $this->assertTrue(Route::has($item['route']), $item['key']);
            $this->assertNotSame('', (string) $item['key']);
        }

        $this->assertNotContains('platform_owner_modules', $keys);
        $this->assertArrayNotHasKey('route', $group);
    }

    public function test_roleless_and_inactive_accounts_never_receive_overview_destinations(): void
    {
        $roleless = User::factory()->create(['is_active' => true]);
        $inactive = $this->superAdmin();
        $inactive->forceFill(['is_active' => false])->save();

        foreach ([$roleless, $inactive] as $user) {
            $groups = app(AdminNavigation::class)->groupsFor($user->fresh());
            $this->assertNotContains('overview', collect($groups)->pluck('key')->all());
        }

        $this->actingAs($roleless)
            ->get(route('admin.enterprise-workspaces.index'))
            ->assertForbidden();
    }

    public function test_overview_navigation_renders_in_both_supported_locales(): void
    {
        $admin = $this->superAdmin();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $admin->forceFill(['locale' => $locale])->save();
            $this->actingAs($admin->fresh())
                ->get(route('admin.administration.index'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__('admin.nav_groups.overview', [], $locale));
        }
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }
}
