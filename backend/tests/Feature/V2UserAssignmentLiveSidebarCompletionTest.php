<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P383: assigning the selected user and showing a provisional sidebar tree
 * share the existing authorized Security Center workflow, not a new RBAC grant.
 */
final class V2UserAssignmentLiveSidebarCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_existing_user_management_opens_a_bilingual_live_preview_and_tracks_selected_role_scope(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->create(['is_active' => true]);

        // The shared FOODEX UI converts native details to a button/host.
        // The picker must initialize from that real trigger, not only toggle.
        $sharedModals = file_get_contents(resource_path('views/admin/_brand-components.blade.php'));
        $this->assertIsString($sharedModals);
        $this->assertStringContainsString('trigger.dataset.foodexDialogOpen', $sharedModals);
        $this->assertStringContainsString('details.replaceWith(host)', $sharedModals);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $admin->forceFill(['locale' => $locale])->save();

            $response = $this->actingAs($admin)
                ->get(route('admin.security.index', [
                    'section' => 'users',
                    'q' => $target->email,
                ]));

            $response->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-foodex-operational-modal', false)
                ->assertSee('data-v2-user-page-tree', false)
                ->assertSee('data-v2-user-tree-scope', false)
                ->assertSee('data-v2-user-tree-status', false)
                ->assertSee("addEventListener('toggle'", false)
                ->assertSee('HTMLDetailsElement', false)
                ->assertSee("closest('[data-foodex-dialog-open]'", false)
                ->assertSee("closest('.foodex-operational-dialog-host')", false)
                ->assertSee('if (section) refresh(getForm(section))', false)
                ->assertSee('const nextScope = storeMatch ? storeMatch[1]', false)
                ->assertSee('selector.value = nextScope', false)
                ->assertSee("addEventListener('change'", false)
                ->assertSee('role="status"', false)
                ->assertSee('aria-live="polite"', false)
                ->assertSee('data-preview-url="'.route('admin.security.role-tree-preview').'"', false);

            $response->assertSee($locale === 'ar'
                ? 'معاينة شجرة الصفحات للدور والنطاق'
                : 'Role and scope page tree preview');
        }
    }

    public function test_assigned_roles_are_persisted_and_subsequent_live_readback_is_scoped_and_side_effect_free(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->create(['is_active' => true]);
        $global = $this->role('P383_GLOBAL_CATALOG', 'global', 'catalog.view');
        $storeRole = $this->role('P383_STORE_INVENTORY', 'store', 'inventory.view');
        $store1 = $this->store('B2C', 'P383-B2C-ONE');
        $store2 = $this->store('B2C', 'P383-B2C-TWO');

        $this->actingAs($admin)
            ->put(route('admin.security.users.roles', $target), [
                'global_role_ids' => [$global->id],
                'store_role_ids' => [$store1 => [$storeRole->id]],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('role_user', ['user_id' => $target->id, 'role_id' => $global->id]);
        $this->assertDatabaseHas('user_store_roles', [
            'user_id' => $target->id,
            'store_id' => $store1,
            'role_id' => $storeRole->id,
        ]);
        $this->assertDatabaseMissing('user_store_roles', [
            'user_id' => $target->id,
            'store_id' => $store2,
            'role_id' => $storeRole->id,
        ]);
        $this->assertTrue($target->fresh()->hasPermission('catalog.view'));
        $this->assertTrue($target->fresh()->hasPermission('inventory.view', $store1));
        $this->assertFalse($target->fresh()->hasPermission('inventory.view', $store2));

        $audits = DB::table('audit_logs')->count();
        $grants = DB::table('role_user')->count();
        $scopedGrants = DB::table('user_store_roles')->count();

        $this->actingAs($admin)
            ->getJson(route('admin.security.role-tree-preview', [
                'scope' => 'global',
                'global_role_ids' => [$global->id],
            ]))
            ->assertOk()
            ->assertJsonPath('projection_only', true)
            ->assertJsonStructure(['count', 'groups', 'disclaimer']);

        $this->actingAs($admin)
            ->getJson(route('admin.security.role-tree-preview', [
                'scope' => 'store',
                'store_id' => $store1,
                'global_role_ids' => [$global->id],
                'store_role_ids' => [$storeRole->id],
            ]))
            ->assertOk()
            ->assertJsonPath('projection_only', true)
            ->assertJsonStructure(['count', 'groups', 'disclaimer']);

        $this->assertSame($audits, DB::table('audit_logs')->count());
        $this->assertSame($grants, DB::table('role_user')->count());
        $this->assertSame($scopedGrants, DB::table('user_store_roles')->count());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.roles_changed',
            'user_id' => $admin->id,
        ]);
    }

    public function test_roleless_actor_and_cross_channel_candidate_are_denied_without_mutating_role_assignments(): void
    {
        $target = User::factory()->create(['is_active' => true]);
        $roleless = User::factory()->create(['is_active' => true]);
        $admin = $this->superAdmin();
        $global = $this->role('P383_GLOBAL', 'global', 'catalog.view');
        $storeRole = $this->role('P383_STORE', 'store', 'inventory.view');
        $wholesale = $this->store('B2B', 'P383-B2B');

        $this->actingAs($roleless)
            ->getJson(route('admin.security.role-tree-preview', [
                'scope' => 'global',
                'global_role_ids' => [$global->id],
            ]))
            ->assertForbidden();

        $this->actingAs($roleless)
            ->put(route('admin.security.users.roles', $target), [
                'global_role_ids' => [$global->id],
            ])
            ->assertForbidden();

        $this->actingAs($admin)
            ->getJson(route('admin.security.role-tree-preview', [
                'scope' => 'store',
                'store_id' => $wholesale,
                'store_role_ids' => [$storeRole->id],
            ]))
            ->assertUnprocessable();

        $this->actingAs($admin)
            ->getJson(route('admin.security.role-tree-preview', [
                'scope' => 'global',
                'store_role_ids' => [$storeRole->id],
            ]))
            ->assertUnprocessable();

        $this->actingAs($admin)
            ->from(route('admin.security.index', ['section' => 'users']))
            ->put(route('admin.security.users.roles', $target), [
                'global_role_ids' => [$global->id],
                'store_role_ids' => [$wholesale => [$storeRole->id]],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('store_roles');

        $this->assertDatabaseMissing('role_user', [
            'user_id' => $target->id,
            'role_id' => $global->id,
        ]);
        $this->assertSame(0, $target->fresh()->storeRoleAssignments()->count());
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail()->id);

        return $user;
    }

    private function role(string $code, string $scope, string $permissionCode): Role
    {
        $role = Role::query()->create([
            'code' => $code,
            'name' => $code,
            'scope' => $scope,
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->attach(Permission::query()->where('code', $permissionCode)->firstOrFail()->id);

        return $role;
    }

    private function store(string $type, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $type)->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
