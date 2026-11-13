<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

final class V2UserArchiveNavigationAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_super_admin_can_discover_inactive_users_in_canonical_security_workbench_in_both_locales(): void
    {
        $admin = $this->withRole('SUPER_ADMIN');
        $inactive = $this->withRole('B2B_ADMIN');
        $inactive->forceFill(['is_active' => false, 'deactivated_at' => now()])->save();

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $admin->forceFill(['locale' => $locale])->save();

            $this->actingAs($admin->fresh())
                ->get(route('admin.administration.index'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-v2-user-lifecycle-entry="inactive"', false)
                ->assertSee(route('admin.security.index', ['section' => 'users', 'status' => 'inactive']))
                ->assertSee(__('admin.administration_hub.inactive_users', [], $locale));

            $this->actingAs($admin->fresh())
                ->get(route('admin.security.index', ['section' => 'users', 'status' => 'inactive']))
                ->assertOk()
                ->assertViewHas('securitySection', 'users')
                ->assertSee($inactive->email)
                ->assertSee('data-v2-open-user-dialog="status"', false)
                ->assertDontSee('data-v2-open-user-dialog="delete"', false);
        }
    }

    public function test_security_viewer_cannot_discover_lifecycle_action_or_change_user_status(): void
    {
        $viewer = $this->withRole('B2B_ADMIN');
        $reviewerRole = Role::query()->create([
            'code' => 'V2_P334_SECURITY_VIEW_ONLY',
            'name' => 'P334 Security Reviewer',
            'scope' => 'global',
            'is_active' => true,
            'is_system' => false,
        ]);
        $reviewerRole->permissions()->attach(
            Permission::query()->where('code', 'security.view')->firstOrFail(),
        );
        $viewer->roles()->attach($reviewerRole);

        $target = $this->withRole('B2B_ADMIN');

        $this->assertTrue(Gate::forUser($viewer)->allows('security.view'));
        $this->assertFalse(Gate::forUser($viewer)->allows('users.status.manage'));

        $this->actingAs($viewer)
            ->get(route('admin.administration.index'))
            ->assertOk()
            ->assertSee('data-admin-card="users-permissions"', false)
            ->assertDontSee('data-v2-user-lifecycle-entry="inactive"', false);

        $this->actingAs($viewer)
            ->get(route('admin.security.index', ['section' => 'users', 'status' => 'inactive']))
            ->assertOk();

        $this->actingAs($viewer)
            ->patch(route('admin.security.users.status', $target), [
                'is_active' => false,
                'reason' => 'Unauthorized lifecycle mutation',
                'impact_reviewed' => true,
                'confirm_email' => $target->email,
            ])
            ->assertForbidden();

        $this->assertTrue((bool) $target->fresh()->is_active);
    }

    public function test_wholesale_operator_cannot_discover_or_access_security_user_lifecycle(): void
    {
        $operator = $this->withRole('B2B_ADMIN');

        $this->assertFalse(Gate::forUser($operator)->allows('security.view'));

        $this->actingAs($operator)
            ->get(route('admin.administration.index'))
            ->assertOk()
            ->assertDontSee('data-v2-user-lifecycle-entry="inactive"', false);

        $this->actingAs($operator)
            ->get(route('admin.security.index', ['section' => 'users', 'status' => 'inactive']))
            ->assertForbidden();
    }

    private function withRole(string $code): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', $code)->firstOrFail());

        return $user;
    }
}
