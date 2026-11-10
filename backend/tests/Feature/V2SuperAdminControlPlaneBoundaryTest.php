<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class V2SuperAdminControlPlaneBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_super_admin_lands_in_governance_not_an_operational_workspace_for_either_channel(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $user = $this->userWithRole('SUPER_ADMIN', $locale);
            $navigation = app(AdminNavigation::class);

            $this->assertSame(
                ['b2b', 'b2c'],
                array_keys($navigation->for($user)),
                'Control-plane landing must not silently revoke channel access.',
            );

            foreach (['b2b', 'b2c'] as $channel) {
                $this->assertSame(
                    route('admin.administration.index'),
                    $navigation->defaultWorkspaceUrl($user, $channel),
                );
            }

            $this->assertFalse($user->hasRole('B2B_ADMIN'));
            $this->assertFalse($user->hasRole('RETAIL_OPERATIONS'));

            $this->actingAs($user)
                ->get(route('admin.administration.index'))
                ->assertOk()
                ->assertViewHas('navContext', 'administration_hub');
        }
    }

    public function test_wholesale_operator_keeps_business_landing_and_does_not_inherit_control_plane_powers(): void
    {
        $user = $this->userWithRole('B2B_ADMIN', 'en');

        $this->assertSame(
            route('admin.b2b.dashboard'),
            app(AdminNavigation::class)->defaultWorkspaceUrl($user, 'b2b'),
        );

        $this->actingAs($user)
            ->get(route('admin.b2b.dashboard'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('admin.b2c.dashboard'))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('admin.system-update.index'))
            ->assertForbidden();
    }

    public function test_an_inactive_super_admin_and_a_user_without_management_roles_are_denied_by_http(): void
    {
        $inactive = $this->userWithRole('SUPER_ADMIN', 'ar');
        $inactive->forceFill(['is_active' => false])->save();

        $this->actingAs($inactive)
            ->get(route('admin.administration.index'))
            ->assertForbidden();

        $unprivileged = User::factory()->create([
            'is_active' => true,
            'locale' => 'en',
        ]);

        $this->actingAs($unprivileged)
            ->get(route('admin.administration.index'))
            ->assertForbidden();
    }

    private function userWithRole(string $code, string $locale): User
    {
        $user = User::factory()->create([
            'is_active' => true,
            'locale' => $locale,
        ]);
        $user->roles()->attach(Role::query()->where('code', $code)->firstOrFail());

        return $user;
    }
}
