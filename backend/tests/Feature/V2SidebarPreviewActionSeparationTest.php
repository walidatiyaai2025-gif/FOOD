<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2SidebarPreviewActionSeparationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_live_sidebar_preview_explicitly_separates_page_visibility_from_action_approval_in_both_locales(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'p083-admin@example.test');
        $role = $this->previewRole('P083_PREVIEW_ROLE');
        $permission = Permission::query()->where('code', 'catalog.view')->firstOrFail();
        $role->permissions()->attach($permission->id);

        $baseline = $role->permissions()->pluck('permissions.id')->all();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $admin->forceFill(['locale' => $locale])->save();

            $response = $this->actingAs($admin)
                ->get(route('admin.sidebar-access.roles', ['role_id' => $role->id], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-v2-preview-summary', false)
                ->assertSee('data-v2-role-permission-code="catalog.view"', false)
                ->assertSee('aria-describedby="v2-role-preview-action-boundary"', false)
                ->assertSee('data-v2-preview-no-action-grant', false)
                ->assertSee(__('v2_role_permissions.preview_action_boundary', [], $locale));

            $html = (string) $response->getContent();
            $this->assertSame(
                1,
                substr_count($html, 'id="v2-role-preview-action-boundary"'),
            );
            $this->assertStringContainsString('aria-live="polite"', $html);
            $this->assertStringContainsString("form.addEventListener('change'", $html);
            $this->assertStringContainsString("event.target.matches('[data-v2-role-permission-code]')", $html);
        }

        $this->assertEqualsCanonicalizing($baseline, $role->fresh()->permissions()->pluck('permissions.id')->all());
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'role.updated')->count());
        $this->assertSame(0, DB::table('admin_sidebar_role_visibility')->where('role_id', $role->id)->count());
    }

    public function test_actor_authorized_to_read_preview_cannot_use_it_to_approve_role_assignments_or_dispatch(): void
    {
        $previewEditor = $this->previewRole('P083_PREVIEW_EDITOR');
        $rolesManage = Permission::query()->where('code', 'roles.manage')->firstOrFail();
        $previewEditor->permissions()->attach($rolesManage->id);

        $actor = User::factory()->create([
            'locale' => 'en',
            'is_active' => true,
        ]);
        $actor->roles()->attach($previewEditor->id);

        $target = User::factory()->create(['is_active' => true]);
        $this->assertTrue($actor->hasPermission('roles.manage'));
        $this->assertFalse($actor->hasPermission('users.roles.manage'));
        $this->assertFalse($actor->hasPermission('orders.dispatch'));

        $this->actingAs($actor)
            ->get(route('admin.sidebar-access.roles', ['role_id' => $previewEditor->id], false))
            ->assertOk()
            ->assertSee(__('v2_role_permissions.preview_action_boundary', [], 'en'));

        $this->actingAs($actor)
            ->put(route('admin.security.users.roles', $target, false), [
                'global_role_ids' => [$previewEditor->id],
                'store_role_ids' => [],
            ])
            ->assertForbidden();

        $this->assertFalse($target->fresh()->roles()->exists());
        $this->assertDatabaseMissing('role_user', [
            'user_id' => $target->id,
            'role_id' => $previewEditor->id,
        ]);
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'user.roles_changed')->count());
    }

    private function previewRole(string $code): Role
    {
        return Role::query()->create([
            'code' => $code,
            'name' => 'P083 Preview Role',
            'description' => 'P083 preview action separation',
            'scope' => 'global',
            'is_system' => false,
            'is_active' => true,
        ]);
    }

    private function userWithRole(string $code, string $email): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $code)->firstOrFail());

        return $user;
    }
}
