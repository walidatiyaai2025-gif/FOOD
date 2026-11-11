<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\AdminSidebarManager;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2InactiveArchivedSidebarRoleMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_active_role_keeps_its_canonical_navigation_eligibility(): void
    {
        $role = $this->wholesaleRole();
        $this->assertTrue($role->is_active);
        $this->assertNull($role->archived_at);

        $item = $this->customerGroupMatrixItem($role);

        $this->assertTrue($item['base_allowed']);
        $this->assertTrue($item['visible_for_role']);
    }

    public function test_disabled_role_does_not_reanimate_historical_grants_in_matrix_or_preferences(): void
    {
        $role = $this->wholesaleRole();
        $historicGrantCount = DB::table('permission_role')->where('role_id', $role->id)->count();
        $this->assertGreaterThan(0, $historicGrantCount);

        $role->forceFill(['is_active' => false])->save();

        $item = $this->customerGroupMatrixItem($role);
        $this->assertFalse($item['base_allowed']);
        $this->assertFalse($item['visible_for_role']);

        app(AdminSidebarManager::class)->saveRoleVisibility($role, ['customer_groups']);
        $this->assertDatabaseMissing('admin_sidebar_role_visibility', [
            'role_id' => $role->id,
            'item_key' => 'customer_groups',
        ]);
        $this->assertSame($historicGrantCount, DB::table('permission_role')->where('role_id', $role->id)->count());
    }

    public function test_archived_role_keeps_its_history_without_editor_eligibility(): void
    {
        $role = $this->wholesaleRole();
        $historicGrantCount = DB::table('permission_role')->where('role_id', $role->id)->count();
        $this->assertGreaterThan(0, $historicGrantCount);

        $role->forceFill(['archived_at' => now()])->save();

        $item = $this->customerGroupMatrixItem($role);
        $this->assertFalse($item['base_allowed']);
        $this->assertFalse($item['visible_for_role']);

        app(AdminSidebarManager::class)->saveRoleVisibility($role, ['customer_groups']);
        $this->assertDatabaseMissing('admin_sidebar_role_visibility', [
            'role_id' => $role->id,
            'item_key' => 'customer_groups',
        ]);
        $this->assertSame($historicGrantCount, DB::table('permission_role')->where('role_id', $role->id)->count());
    }

    public function test_authorized_role_editor_get_projects_disabled_role_as_ineligible(): void
    {
        $role = $this->wholesaleRole();
        $role->forceFill(['is_active' => false])->save();

        $administrator = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $administrator->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $response = $this->actingAs($administrator)
            ->get(route('admin.sidebar-access.roles', ['role_id' => $role->id], false));

        $response->assertOk();
        $matrix = $response->viewData('matrix');
        $this->assertIsArray($matrix);
        $this->assertFalse($this->matrixItem($matrix, 'customer_groups')['base_allowed']);
    }

    private function wholesaleRole(): Role
    {
        return Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
    }

    /** @return array<string,mixed> */
    private function customerGroupMatrixItem(Role $role): array
    {
        return $this->matrixItem(app(AdminSidebarManager::class)->roleMatrix($role), 'customer_groups');
    }

    /**
     * @param  array{groups:list<array<string,mixed>>}  $matrix
     * @return array<string,mixed>
     */
    private function matrixItem(array $matrix, string $key): array
    {
        $item = collect($matrix['groups'])
            ->flatMap(static fn (array $group): array => $group['items'])
            ->firstWhere('key', $key);

        $this->assertIsArray($item, "Missing Sidebar registry item [{$key}].");

        return $item;
    }
}
