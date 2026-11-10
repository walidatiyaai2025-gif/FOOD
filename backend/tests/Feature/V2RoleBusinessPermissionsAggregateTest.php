<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2RoleBusinessPermissionsAggregateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_role_business_summary_uses_recorded_permission_roles_and_keeps_non_business_privileges_out(): void
    {
        $global = $this->role('V2_BUSINESS_SUMMARY', 'global');
        $global->permissions()->attach($this->permissionIds([
            'orders.view',
            'orders.manage',
            'customers.view',
            'security.view',
        ]));

        $retail = $this->role('V2_STORE_SUMMARY', 'store');
        $retail->permissions()->attach($this->permissionIds(['inventory.view']));

        $admin = $this->superAdmin('en');
        $pivotRows = DB::table('permission_role')->count();
        $response = $this->actingAs($admin)
            ->get(route('admin.sidebar-access.roles', ['role_id' => $global->id]))
            ->assertOk()
            ->assertSee('dir="ltr"', false);

        $globalSummary = $this->roleSummary((string) $response->getContent(), $global->code);
        $this->assertStringContainsString('Aggregated role business permissions', $globalSummary);
        $this->assertStringContainsString('data-v2-business-role-scope="global"', $globalSummary);
        $this->assertStringContainsString('data-v2-business-domain="orders"', $globalSummary);
        $this->assertStringContainsString('2 permissions', $globalSummary);
        $this->assertStringContainsString('1 permissions', $globalSummary);
        $this->assertStringNotContainsString('data-v2-business-domain="security"', $globalSummary);
        $this->assertStringNotContainsString('security.view', $globalSummary);
        $this->assertStringContainsString('not a user', $globalSummary);

        $retailResponse = $this->actingAs($admin)
            ->get(route('admin.sidebar-access.roles', ['role_id' => $retail->id]))
            ->assertOk();
        $storeSummary = $this->roleSummary((string) $retailResponse->getContent(), $retail->code);
        $this->assertStringContainsString('data-v2-business-role-scope="store"', $storeSummary);
        $this->assertStringContainsString('1 permissions', $storeSummary);
        $this->assertStringNotContainsString('data-v2-business-domain="orders"', $storeSummary);
        $this->assertSame($pivotRows, DB::table('permission_role')->count());
    }

    public function test_bilingual_role_aggregate_reflects_live_role_grant_changes_without_editing_authority(): void
    {
        $role = $this->role('V2_LIVE_SUMMARY', 'global');
        $admin = $this->superAdmin('ar');

        $initial = $this->actingAs($admin)
            ->get(route('admin.sidebar-access.roles', ['role_id' => $role->id]))
            ->assertOk()
            ->assertSee('dir="rtl"', false);
        $initialSummary = $this->roleSummary((string) $initial->getContent(), $role->code);
        $this->assertStringContainsString('لا توجد صلاحيات تجارية', $initialSummary);

        $role->permissions()->attach($this->permissionIds(['orders.view', 'orders.manage']));

        $updated = $this->actingAs($admin)
            ->get(route('admin.sidebar-access.roles', ['role_id' => $role->id]))
            ->assertOk();
        $updatedSummary = $this->roleSummary((string) $updated->getContent(), $role->code);
        $this->assertStringContainsString('الصلاحيات التجارية المجمعة للدور', $updatedSummary);
        $this->assertStringContainsString('2 صلاحية', $updatedSummary);
        $this->assertStringNotContainsString('لا توجد صلاحيات تجارية', $updatedSummary);

        $admin->forceFill(['locale' => 'en'])->save();
        $english = $this->actingAs($admin)
            ->get(route('admin.sidebar-access.roles', ['role_id' => $role->id]))
            ->assertOk()
            ->assertSee('dir="ltr"', false);
        $this->assertStringContainsString('2 permissions', $this->roleSummary((string) $english->getContent(), $role->code));
    }

    public function test_role_permission_aggregate_does_not_bypass_security_route_permission(): void
    {
        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());

        $this->actingAs($actor)
            ->get(route('admin.sidebar-access.roles', ['role_id' => Role::query()->where('code', 'B2B_ADMIN')->value('id')]))
            ->assertForbidden();
    }

    private function role(string $code, string $scope): Role
    {
        return Role::query()->create([
            'code' => $code,
            'name' => $code,
            'scope' => $scope,
            'description' => 'V2 role authorization regression fixture',
            'is_system' => false,
            'is_active' => true,
        ]);
    }

    /** @param list<string> $codes
     * @return list<int>
     */
    private function permissionIds(array $codes): array
    {
        $permissions = Permission::query()->whereIn('code', $codes)->pluck('id', 'code');
        $this->assertCount(count($codes), $permissions);

        return $permissions->values()->map(static fn ($id): int => (int) $id)->all();
    }

    private function superAdmin(string $locale): User
    {
        $user = User::factory()->create(['locale' => $locale, 'is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }

    private function roleSummary(string $html, string $roleCode): string
    {
        $needle = 'data-v2-business-permission-summary="'.$roleCode.'"';
        $start = strpos($html, $needle);
        $this->assertNotFalse($start, 'Missing aggregate card for role '.$roleCode);
        $end = strpos($html, '</section>', (int) $start);
        $this->assertNotFalse($end, 'Missing aggregate card boundary for role '.$roleCode);

        return substr($html, (int) $start, (int) $end - (int) $start);
    }
}
