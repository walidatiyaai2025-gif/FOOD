<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P022: Sidebar display preferences are applied after canonical authorization.
 * Exercise the actual role-editor HTTP writer, not only direct database toggles.
 */
final class V2AdminSidebarPostAuthorizationContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_role_visibility_editor_hides_menu_without_changing_grants_or_http_access(): void
    {
        $owner = $this->userWithRole('SUPER_ADMIN', 'v2-p022-owner@example.test');
        $actor = $this->userWithRole('B2B_ADMIN', 'v2-p022-operator@example.test');
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $grantedIds = DB::table('permission_role')
            ->where('role_id', $role->id)
            ->orderBy('permission_id')
            ->pluck('permission_id')
            ->all();

        $this->assertTrue($actor->hasPermission('catalog.view'));
        $this->assertContains('catalog_management', $this->visibleKeys($actor));

        $this->actingAs($owner)
            ->put(route('admin.sidebar-access.roles.update', ['role' => $role->id], false), [
                'item_keys' => [],
            ])
            ->assertRedirect(route('admin.sidebar-access.roles', ['role_id' => $role->id], false))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('admin_sidebar_role_visibility', [
            'role_id' => $role->id,
            'item_key' => 'catalog_management',
            'is_visible' => false,
        ]);
        $this->assertSame($grantedIds, DB::table('permission_role')
            ->where('role_id', $role->id)
            ->orderBy('permission_id')
            ->pluck('permission_id')
            ->all());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'admin.sidebar.role_visibility.updated',
            'auditable_type' => 'role',
            'auditable_id' => $role->id,
        ]);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $this->assertNotContains('catalog_management', $this->visibleKeys($actor->fresh()));
            $this->assertTrue($actor->fresh()->hasPermission('catalog.view'));
            $this->actingAs($actor->fresh())
                ->get(route('admin.catalog.index', [], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);
        }
    }

    public function test_group_key_as_item_is_rejected_without_partial_write_or_audit(): void
    {
        $owner = $this->userWithRole('SUPER_ADMIN', 'v2-p022-validation@example.test');
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $rowsBefore = DB::table('admin_sidebar_role_visibility')->where('role_id', $role->id)->count();
        $auditBefore = DB::table('audit_logs')->where('event', 'admin.sidebar.role_visibility.updated')->count();

        $this->actingAs($owner)
            ->put(route('admin.sidebar-access.roles.update', ['role' => $role->id], false), [
                'item_keys' => ['catalog_management', 'catalog'],
            ])
            ->assertSessionHasErrors('item_keys.1');

        $this->assertSame($rowsBefore, DB::table('admin_sidebar_role_visibility')->where('role_id', $role->id)->count());
        $this->assertSame($auditBefore, DB::table('audit_logs')->where('event', 'admin.sidebar.role_visibility.updated')->count());
    }

    public function test_roleless_actor_cannot_access_role_editor_or_change_visibility(): void
    {
        $restricted = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();

        $this->actingAs($restricted)
            ->get(route('admin.sidebar-access.roles', [], false))
            ->assertForbidden();
        $this->actingAs($restricted)
            ->put(route('admin.sidebar-access.roles.update', ['role' => $role->id], false), [
                'item_keys' => ['catalog_management'],
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('admin_sidebar_role_visibility', [
            'role_id' => $role->id,
            'item_key' => 'catalog_management',
        ]);
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'admin.sidebar.role_visibility.updated')->count());
    }

    /** @return list<string> */
    private function visibleKeys(User $actor): array
    {
        $keys = [];
        foreach (app(AdminNavigation::class)->groupsFor($actor) as $group) {
            foreach ($group['children'] as $item) {
                $keys[] = $item['key'];
                foreach ($item['children'] ?? [] as $child) {
                    $keys[] = $child['key'];
                }
            }
        }

        return $keys;
    }

    private function userWithRole(string $roleCode, string $email): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'is_active' => true,
            'locale' => 'ar',
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail()->id);

        return $user;
    }
}
