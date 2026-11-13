<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class V2UserLifecycleRowActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_super_admin_sees_exact_user_ellipsis_menu_with_authorized_lifecycle_dialogs_in_both_locales(): void
    {
        $admin = $this->withRole('SUPER_ADMIN');
        $target = $this->withRole('B2B_ADMIN');

        foreach (['en', 'ar'] as $locale) {
            $admin->forceFill(['locale' => $locale])->save();

            $response = $this->actingAs($admin->fresh())
                ->get(route('admin.security.index', ['section' => 'users']));

            $response->assertOk()
                ->assertSee('data-v2-user-action-menu', false)
                ->assertSee('data-v2-open-user-dialog="manage"', false)
                ->assertSee('data-v2-open-user-dialog="status"', false)
                ->assertSee('data-v2-user-dialog="manage"', false)
                ->assertSee('data-v2-user-dialog="status"', false)
                ->assertSee($target->email)
                ->assertSee('route', false);

            $this->assertStringContainsString(
                __('admin.security.actions', [], $locale),
                $response->getContent(),
            );
        }
    }

    public function test_inactive_user_gets_reactivation_action_but_not_unverified_delete(): void
    {
        $admin = $this->withRole('SUPER_ADMIN');
        $target = $this->withRole('B2B_ADMIN');
        $target->forceFill(['is_active' => false, 'deactivated_at' => now()])->save();

        $this->actingAs($admin)
            ->get(route('admin.security.index', ['section' => 'users', 'status' => 'inactive']))
            ->assertOk()
            ->assertSee('data-v2-open-user-dialog="status"', false)
            ->assertSee(__('admin.security.activate'))
            ->assertDontSee('data-v2-open-user-dialog="delete"', false);
    }

    public function test_unprivileged_operator_cannot_discover_security_user_menu_or_mutate_user_status(): void
    {
        $operator = $this->withRole('B2B_ADMIN');
        $target = $this->withRole('B2B_ADMIN');

        $this->actingAs($operator)
            ->get(route('admin.security.index', ['section' => 'users']))
            ->assertForbidden();

        $this->actingAs($operator)
            ->patch(route('admin.security.users.status', $target), [
                'is_active' => false,
                'reason' => 'Unauthorized user lifecycle change',
                'impact_reviewed' => true,
                'confirm_email' => $target->email,
            ])->assertForbidden();

        $this->assertTrue((bool) $target->fresh()->is_active);
    }

    private function withRole(string $code): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', $code)->firstOrFail()->id);

        return $user;
    }
}
