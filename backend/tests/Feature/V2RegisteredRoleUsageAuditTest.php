<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\RoleAssignmentAudit;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2RegisteredRoleUsageAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_audit_counts_distinct_assigned_roles_not_assigned_users_and_preserves_all_data(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'en');
        $before = app(RoleAssignmentAudit::class)->snapshot();

        $usedGlobal = $this->role('V2_USED_GLOBAL', 'global');
        $usedStore = $this->role('V2_USED_STORE', 'store');
        $unused = $this->role('V2_UNUSED_ROLE', 'global');
        $staff = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $store = $this->store('V2-ROLE-USAGE-STORE');
        $staff->roles()->attach($usedGlobal->id);
        DB::table('user_store_roles')->insert([
            'user_id' => $staff->id,
            'role_id' => $usedStore->id,
            'store_id' => $store,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $auditCount = DB::table('audit_logs')->count();
        $globalRows = DB::table('role_user')->count();
        $storeRows = DB::table('user_store_roles')->count();

        $response = $this->actingAs($admin)
            ->get(route('admin.security.index', ['section' => 'roles']));

        $response->assertOk()
            ->assertSee('data-v2-role-registry-audit', false)
            ->assertSee('48 registered roles, of which 21 DISTINCT roles were assigned')
            ->assertSee('dir="ltr"', false)
            ->assertViewHas('roleAssignmentAudit', function (array $stats) use ($before): bool {
                return $stats['role_registry_total'] === $before['role_registry_total'] + 3
                    && $stats['role_registry_assigned'] === $before['role_registry_assigned'] + 2
                    && $stats['role_registry_unassigned'] === $before['role_registry_unassigned'] + 1
                    && $stats['assigned_users'] === $before['assigned_users'] + 1
                    && $stats['role_registry_active_assigned'] === $before['role_registry_active_assigned'] + 2;
            });

        $this->assertDatabaseHas('roles', ['id' => $unused->id, 'is_active' => true]);
        $this->assertDatabaseHas('role_user', ['user_id' => $staff->id, 'role_id' => $usedGlobal->id]);
        $this->assertDatabaseHas('user_store_roles', ['user_id' => $staff->id, 'role_id' => $usedStore->id]);
        $this->assertSame($globalRows, DB::table('role_user')->count());
        $this->assertSame($storeRows, DB::table('user_store_roles')->count());
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }

    public function test_inactive_assigned_role_remains_in_historic_links_but_not_active_subset(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'en');
        $before = app(RoleAssignmentAudit::class)->snapshot();
        $inactiveRole = $this->role('V2_ARCHIVED_USED', 'global');
        $staff = User::factory()->create(['is_active' => true]);
        $staff->roles()->attach($inactiveRole->id);
        $inactiveRole->update(['is_active' => false]);

        $audit = $this->actingAs($admin)
            ->get(route('admin.security.index', ['section' => 'roles']))
            ->assertOk()
            ->viewData('roleAssignmentAudit');

        $this->assertIsArray($audit);
        $this->assertSame(
            Role::query()->count(),
            $audit['role_registry_total']
        );
        $usedIds = DB::table('role_user')->pluck('role_id')
            ->merge(DB::table('user_store_roles')->pluck('role_id'))
            ->unique();
        $this->assertSame($usedIds->count(), $audit['role_registry_assigned']);
        $this->assertSame($before['role_registry_assigned'] + 1, $audit['role_registry_assigned']);
        $this->assertSame($before['role_registry_active_assigned'], $audit['role_registry_active_assigned']);
        $this->assertDatabaseHas('role_user', ['user_id' => $staff->id, 'role_id' => $inactiveRole->id]);
    }

    public function test_arabic_and_non_super_admin_security_view_do_not_disclose_global_audit(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'ar');
        $this->actingAs($admin)
            ->get(route('admin.security.index', ['section' => 'roles']))
            ->assertOk()
            ->assertSee('جرد الأدوار المسجلة والمستخدمة في التعيينات')
            ->assertSee('dir="rtl"', false);

        $viewer = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $role = $this->role('V2_AUDIT_VIEWER', 'global');
        $role->permissions()->attach(Permission::query()->where('code', 'security.view')->firstOrFail()->id);
        $viewer->roles()->attach($role);

        $this->actingAs($viewer)
            ->get(route('admin.security.index', ['section' => 'roles']))
            ->assertOk()
            ->assertDontSee('data-v2-role-registry-audit', false)
            ->assertViewHas('roleAssignmentAudit', null);

        $roleless = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $this->actingAs($roleless)
            ->get(route('admin.security.index', ['section' => 'roles']))
            ->assertForbidden();
    }

    private function role(string $code, string $scope): Role
    {
        return Role::query()->create([
            'code' => $code,
            'name' => $code,
            'scope' => $scope,
            'is_active' => true,
            'is_system' => false,
        ]);
    }

    private function userWithRole(string $code, string $locale): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => $locale]);
        $user->roles()->attach(Role::query()->where('code', $code)->firstOrFail());

        return $user;
    }

    private function store(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
