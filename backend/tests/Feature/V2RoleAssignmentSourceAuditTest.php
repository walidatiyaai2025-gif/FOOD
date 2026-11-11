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

final class V2RoleAssignmentSourceAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_super_admin_audits_actual_global_and_store_links_without_mutation(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'en');
        $staff = User::factory()->create(['is_active' => true]);
        $globalRole = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $storeRole = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        $store = $this->store('V2-AUDIT-STORE');

        $before = app(RoleAssignmentAudit::class)->snapshot();
        $staff->roles()->attach($globalRole->id);
        $this->assignStore($staff, $store, $storeRole);
        $globalRows = DB::table('role_user')->count();
        $storeRows = DB::table('user_store_roles')->count();
        $auditEvents = DB::table('audit_logs')->count();

        $response = $this->actingAs($admin)
            ->get(route('admin.security.index', ['section' => 'roles']));

        $response->assertOk()
            ->assertSee('data-v2-role-assignment-audit', false)
            ->assertSee('Global and store role assignment audit')
            ->assertSee('31 global role assignments and 3 store-scoped assignments')
            ->assertSee('dir="ltr"', false);

        $snapshot = $response->viewData('roleAssignmentAudit');
        $this->assertIsArray($snapshot);
        $this->assertSame($before['global_assignments'] + 1, $snapshot['global_assignments']);
        $this->assertSame($before['store_assignments'] + 1, $snapshot['store_assignments']);
        $this->assertSame($globalRows, $snapshot['global_assignments']);
        $this->assertSame($storeRows, $snapshot['store_assignments']);
        $this->assertSame(1, $snapshot['distinct_stores']);
        $this->assertGreaterThanOrEqual(1, $snapshot['assigned_users']);
        $this->assertSame(1, collect($snapshot['stores'])->firstWhere('name', 'V2-AUDIT-STORE')['assignments']);
        $this->assertSame($globalRows, DB::table('role_user')->count());
        $this->assertSame($storeRows, DB::table('user_store_roles')->count());
        $this->assertSame($auditEvents, DB::table('audit_logs')->count());
        $this->assertDatabaseHas('user_store_roles', [
            'user_id' => $staff->id,
            'role_id' => $storeRole->id,
            'store_id' => $store,
        ]);
    }

    public function test_invalid_scope_or_inactive_store_is_reported_but_not_reassigned_or_deleted(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'en');
        $staff = User::factory()->create(['is_active' => true]);
        $storeId = $this->store('V2-AUDIT-DISABLED');
        $storeRole = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        $globalRole = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();

        $baseline = app(RoleAssignmentAudit::class)->snapshot();
        // Inject legacy-invalid assignments at the persistence boundary; this
        // audit must detect them, never use a normal grant endpoint to create them.
        $staff->roles()->attach($storeRole->id);
        $this->assignStore($staff, $storeId, $globalRole);
        DB::table('stores')->where('id', $storeId)->update(['is_active' => false]);

        $response = $this->actingAs($admin)
            ->get(route('admin.security.index', ['section' => 'roles']));

        $response->assertOk()
            ->assertSee('data-v2-assignment-anomalies', false);

        $audit = $response->viewData('roleAssignmentAudit');
        $this->assertIsArray($audit);
        $this->assertSame($baseline['invalid_global_scope'] + 1, $audit['invalid_global_scope']);
        $this->assertSame($baseline['invalid_store_scope'] + 1, $audit['invalid_store_scope']);
        $this->assertSame($baseline['inactive_store_links'] + 1, $audit['inactive_store_links']);
        $this->assertDatabaseHas('role_user', ['user_id' => $staff->id, 'role_id' => $storeRole->id]);
        $this->assertDatabaseHas('user_store_roles', [
            'user_id' => $staff->id,
            'role_id' => $globalRole->id,
            'store_id' => $storeId,
        ]);
    }

    public function test_arabic_inventory_is_rtl_and_non_roles_tab_has_no_audit_payload(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'ar');
        $this->actingAs($admin)
            ->get(route('admin.security.index', ['section' => 'roles']))
            ->assertOk()
            ->assertSee('تدقيق تعيينات الأدوار العامة وأدوار المتاجر')
            ->assertSee('dir="rtl"', false);

        $this->actingAs($admin)
            ->get(route('admin.security.index', ['section' => 'users']))
            ->assertOk()
            ->assertDontSee('data-v2-role-assignment-audit', false)
            ->assertViewHas('roleAssignmentAudit', null);
    }

    public function test_delegated_viewer_cannot_receive_unscoped_assignment_report(): void
    {
        $viewer = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $readRole = Role::query()->create([
            'code' => 'V2_ASSIGNMENT_READ_ONLY',
            'name' => 'Read only',
            'scope' => 'global',
            'is_active' => true,
            'is_system' => false,
        ]);
        $readRole->permissions()->attach(Permission::query()->where('code', 'security.view')->firstOrFail());
        $viewer->roles()->attach($readRole);

        $this->actingAs($viewer)
            ->get(route('admin.security.index', ['section' => 'roles']))
            ->assertOk()
            ->assertDontSee('data-v2-role-assignment-audit', false)
            ->assertViewHas('roleAssignmentAudit', null);
    }

    public function test_roleless_actor_is_denied_at_http_route(): void
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        $this->actingAs($user)
            ->get(route('admin.security.index', ['section' => 'roles']))
            ->assertForbidden();
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

    private function assignStore(User $user, int $store, Role $role): void
    {
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'store_id' => $store,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
