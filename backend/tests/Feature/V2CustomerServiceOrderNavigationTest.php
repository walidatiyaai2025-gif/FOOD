<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class V2CustomerServiceOrderNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        app(WholesalePrincipal::class)->storeId();
    }

    public function test_order_view_override_updates_customer_service_navigation_and_real_route_guard(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN');
        $manager = $this->userWithRole('CUSTOMER_SERVICE_MANAGER');
        $peer = $this->userWithRole('CUSTOMER_SERVICE_AGENT');
        $orderView = Permission::query()->where('code', 'orders.view')->firstOrFail();
        $managerRole = Role::query()->where('code', 'CUSTOMER_SERVICE_MANAGER')->firstOrFail();

        $this->assertTrue($manager->hasPermission('orders.view'));
        $this->assertTrue($manager->hasPermission('orders.manage'));
        $this->assertTrue($this->navHas($manager, 'order_operations'));
        $this->assertTrue($this->navHas($manager, 'erp_customer_service'));
        $this->actingAs($manager)
            ->get(route('admin.operations.orders.index', ['channel' => 'b2b']))
            ->assertOk();

        $selected = Permission::query()
            ->whereIn('code', $manager->inheritedPermissionCodes())
            ->where('code', '!=', 'orders.view')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $this->actingAs($admin)
            ->put(route('admin.security.users.permissions', $manager), [
                'scope_type' => 'global',
                'permission_ids' => $selected,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $manager->refresh();
        $this->assertFalse($manager->hasPermission('orders.view'));
        $this->assertTrue($manager->hasPermission('orders.manage'));
        $this->assertFalse($this->navHas($manager, 'order_operations'));
        $this->assertTrue($this->navHas($manager, 'erp_customer_service'));

        $this->actingAs($manager)
            ->get(route('admin.operations.orders.index', ['channel' => 'b2b']))
            ->assertForbidden();

        $this->assertTrue($peer->fresh()->hasPermission('orders.view'));
        $this->assertTrue($this->navHas($peer, 'order_operations'));
        $this->actingAs($peer)
            ->get(route('admin.operations.orders.index', ['channel' => 'b2b']))
            ->assertOk();

        $this->assertTrue($managerRole->permissions()->where('code', 'orders.view')->exists());
        $this->assertDatabaseHas('user_permission_overrides', [
            'user_id' => $manager->id,
            'permission_id' => $orderView->id,
            'scope_key' => 'global',
            'effect' => 'deny',
        ]);
        $this->assertDatabaseMissing('user_permission_overrides', [
            'user_id' => $peer->id,
            'permission_id' => $orderView->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.permissions_changed',
            'user_id' => $admin->id,
            'auditable_type' => User::class,
            'auditable_id' => $manager->id,
        ]);

        $restored = Permission::query()
            ->whereIn('code', $manager->inheritedPermissionCodes())
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $this->actingAs($admin)
            ->put(route('admin.security.users.permissions', $manager), [
                'scope_type' => 'global',
                'permission_ids' => $restored,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $manager->refresh();
        $this->assertTrue($manager->hasPermission('orders.view'));
        $this->assertTrue($this->navHas($manager, 'order_operations'));
        $this->assertDatabaseMissing('user_permission_overrides', [
            'user_id' => $manager->id,
            'permission_id' => $orderView->id,
            'effect' => 'deny',
        ]);
        $this->actingAs($manager)
            ->get(route('admin.operations.orders.index', ['channel' => 'b2b']))
            ->assertOk();
    }

    public function test_customer_service_agent_cannot_edit_other_staff_order_permissions(): void
    {
        $agent = $this->userWithRole('CUSTOMER_SERVICE_AGENT');
        $supervisor = $this->userWithRole('CUSTOMER_SERVICE_SUPERVISOR');
        $permission = Permission::query()->where('code', 'orders.view')->firstOrFail();

        $this->actingAs($agent)
            ->put(route('admin.security.users.permissions', $supervisor), [
                'scope_type' => 'global',
                'permission_ids' => [],
            ])
            ->assertForbidden();

        $this->assertTrue($supervisor->fresh()->hasPermission('orders.view'));
        $this->assertDatabaseMissing('user_permission_overrides', [
            'user_id' => $supervisor->id,
            'permission_id' => $permission->id,
        ]);
    }

    private function userWithRole(string $code): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'ar']);
        $user->roles()->attach(Role::query()->where('code', $code)->firstOrFail());

        return $user;
    }

    private function navHas(User $user, string $key): bool
    {
        app()->forgetInstance(AdminNavigation::class);

        return collect(app(AdminNavigation::class)->groupsFor($user))
            ->flatMap(static fn (array $group) => $group['children'])
            ->contains('key', $key);
    }
}
