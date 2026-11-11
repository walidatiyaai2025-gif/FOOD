<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2SidebarMultiRoleVisibilityPriorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_explicit_hidden_preference_cannot_be_revived_by_another_role_visible_flag(): void
    {
        $admin = $this->multiRoleUser();
        $roles = $admin->roles()->pluck('roles.id', 'roles.code');
        $this->assertTrue($admin->hasPermission('catalog.view'));

        foreach (['ar', 'en'] as $locale) {
            $admin->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $this->assertContains('catalog_management', $this->visibleKeys($admin->fresh()));

            $this->saveVisibility((int) $roles['B2B_ADMIN'], true);
            $this->saveVisibility((int) $roles['FINANCE'], false);

            $this->assertNotContains('catalog_management', $this->visibleKeys($admin->fresh()));
            $this->assertTrue($admin->fresh()->hasPermission('catalog.view'));
            $this->actingAs($admin->fresh())
                ->get(route('admin.catalog.index', [], false))
                ->assertOk();

            $this->saveVisibility((int) $roles['B2B_ADMIN'], false);
            $this->saveVisibility((int) $roles['FINANCE'], true);
            $this->assertNotContains('catalog_management', $this->visibleKeys($admin->fresh()));

            $this->saveVisibility((int) $roles['B2B_ADMIN'], true);
            $this->saveVisibility((int) $roles['FINANCE'], true);
            $this->assertContains('catalog_management', $this->visibleKeys($admin->fresh()));
        }
    }

    public function test_unconfigured_second_role_does_not_resurrect_an_explicitly_hidden_page(): void
    {
        $admin = $this->multiRoleUser();
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();

        $this->assertContains('catalog_management', $this->visibleKeys($admin));
        $this->saveVisibility((int) $role->getKey(), false);

        // A missing second-role preference is not an implicit display grant.
        $this->assertNotContains('catalog_management', $this->visibleKeys($admin->fresh()));
        $this->assertDatabaseCount('admin_sidebar_role_visibility', 1);

        DB::table('admin_sidebar_role_visibility')->delete();
        $this->assertContains('catalog_management', $this->visibleKeys($admin->fresh()));
    }

    public function test_saved_visible_preference_cannot_override_missing_canonical_http_permission(): void
    {
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $unprivileged = User::factory()->create(['is_active' => true]);

        $this->saveVisibility((int) $role->getKey(), true);

        foreach (['ar', 'en'] as $locale) {
            $unprivileged->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $this->assertNotContains('catalog_management', $this->visibleKeys($unprivileged->fresh()));
            $this->actingAs($unprivileged->fresh())
                ->get(route('admin.catalog.index', [], false))
                ->assertForbidden();
        }
    }

    /** @return list<string> */
    private function visibleKeys(User $user): array
    {
        $keys = [];
        foreach (app(AdminNavigation::class)->groupsFor($user) as $group) {
            foreach ($group['children'] as $page) {
                $keys[] = $page['key'];
                foreach ($page['children'] ?? [] as $child) {
                    $keys[] = $child['key'];
                }
            }
        }

        return $keys;
    }

    private function multiRoleUser(): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->whereIn('code', ['B2B_ADMIN', 'FINANCE'])->pluck('id'));

        return $user->fresh();
    }

    private function saveVisibility(int $roleId, bool $visible): void
    {
        DB::table('admin_sidebar_role_visibility')->updateOrInsert(
            ['role_id' => $roleId, 'item_key' => 'catalog_management'],
            [
                'is_visible' => $visible,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }
}
