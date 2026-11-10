<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P363: the role browser is a read-only presentation over existing RBAC.
 * Searchable role data must not change grants, assignments or HTTP guards.
 */
final class V2RoleListSearchResponsibilitiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authorized_operator_sees_localized_role_search_and_existing_role_responsibilities(): void
    {
        $actor = $this->superAdmin();
        $beforeRoleCount = Role::query()->count();
        $beforeOverrides = DB::table('user_permission_overrides')->count();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $response = $this->actingAs($actor->fresh())
                ->get(route('admin.security.index', ['section' => 'roles']));

            $response->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-role-search-panel', false)
                ->assertSee('id="security-role-search"', false)
                ->assertSee('aria-controls="security-role-grid"', false)
                ->assertSee('data-role-search-card', false)
                ->assertSee('data-role-search-text=', false)
                ->assertSee('data-role-search-empty', false)
                ->assertSee('data-role-results-count', false)
                ->assertSee(__('admin.security.role_search'))
                ->assertSee(__('admin.security.role_search_placeholder'))
                ->assertSee(__('admin.security.role_search_empty'))
                ->assertSee('SUPER_ADMIN', false)
                ->assertSee('job-role-responsibilities', false);

            $this->assertSame($beforeRoleCount, Role::query()->count());
            $this->assertSame($beforeOverrides, DB::table('user_permission_overrides')->count());
        }
    }

    public function test_role_search_does_not_expose_unrelated_user_section_or_remove_its_server_filters(): void
    {
        $actor = $this->superAdmin();

        $this->actingAs($actor)
            ->get(route('admin.security.index', ['section' => 'users', 'q' => 'not-present-p363']))
            ->assertOk()
            ->assertSee('name="q"', false)
            ->assertDontSee('id="security-role-search"', false);
    }

    public function test_roleless_user_cannot_open_role_search_even_by_forging_section_query(): void
    {
        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $this->actingAs($actor)
            ->get(route('admin.security.index', ['section' => 'roles', 'role_search' => 'SUPER_ADMIN']))
            ->assertForbidden();
    }

    private function superAdmin(): User
    {
        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $actor->fresh();
    }
}
