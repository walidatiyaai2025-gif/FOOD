<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class V2ResultingSidebarTreePreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_resulting_tree_is_bilingual_and_tracks_the_canonical_page_matrix_without_creating_grants(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'v2-p365-owner@example.test');
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $before = $role->permissions()->pluck('permissions.id')->all();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $admin->forceFill(['locale' => $locale])->save();

            $response = $this->actingAs($admin)
                ->get(route('admin.sidebar-access.roles', ['role_id' => $role->id], false))
                ->assertOk()
                ->assertSee(__('v2_role_permissions.result_tree_title'))
                ->assertSee(__('v2_role_permissions.result_tree_action_boundary'));

            $html = (string) $response->getContent();
            $this->assertStringContainsString('<html lang="'.$locale.'" dir="'.$direction.'">', $html);
            $this->assertStringContainsString('data-v2-result-tree', $html);
            $this->assertStringContainsString('data-v2-role-permission-code=', $html);
            $this->assertStringContainsString('result.dataset.v2ResultState', $html);
            $this->assertStringContainsString('data-v2-preview-no-action-grant', $html);

            preg_match_all('/\\sdata-v2-role-preview-node(?:\\s|>)/', $html, $editorNodes);
            preg_match_all('/\\sdata-v2-result-item=/', $html, $resultNodes);
            $this->assertGreaterThan(0, count($editorNodes[0]));
            $this->assertCount(count($editorNodes[0]), $resultNodes[0]);
        }

        $this->assertSame($before, $role->fresh()->permissions()->pluck('permissions.id')->all());
    }

    public function test_unprivileged_actor_cannot_read_the_role_tree_or_modify_role_permissions(): void
    {
        $user = User::query()->create([
            'name' => 'Unprivileged',
            'email' => 'v2-p365-deny@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();

        $this->actingAs($user)
            ->get(route('admin.sidebar-access.roles', ['role_id' => $role->id], false))
            ->assertForbidden();

        $this->actingAs($user)
            ->patch(route('admin.security.roles.update', $role, false), [])
            ->assertForbidden();
    }

    private function userWithRole(string $code, string $email): User
    {
        $user = User::query()->create([
            'name' => $code,
            'email' => $email,
            'password' => 'password',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $code)->firstOrFail());

        return $user;
    }
}
