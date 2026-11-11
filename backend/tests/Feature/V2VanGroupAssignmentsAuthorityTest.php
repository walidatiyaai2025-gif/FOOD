<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreType;
use App\Models\User;
use App\Models\VanAssignment;
use App\Services\AdminSidebarManager;
use App\Services\VanRegistryService;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P273: Van management is the existing authorized Field Operations
 * business group. Reading assignments cannot confer mutation authority.
 */
final class V2VanGroupAssignmentsAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_van_group_reuses_registered_van_and_assignment_pages_in_both_locales(): void
    {
        $van = app(VanRegistryService::class)->createVan([
            'code' => 'P2603-VAN-GROUP-ONE',
            'plate_number' => 'P2603-PLATE',
        ]);
        $viewer = $this->actorWithPermissions('P2603_VAN_VIEWER', ['drivers.b2b.view']);
        $registry = app(AdminSidebarManager::class)->registry()['items'];

        foreach (['field_ops_vans', 'field_ops_assignments'] as $itemKey) {
            $this->assertSame('field_operations', $registry[$itemKey]['group']);
            $this->assertSame(['drivers.b2b.view'], $registry[$itemKey]['permissions']);
        }

        foreach ([
            'admin.field-operations.vans',
            'admin.field-operations.vans.show',
            'admin.field-operations.assignments',
            'admin.field-operations.assignments.store',
            'admin.field-operations.assignments.update',
            'admin.field-operations.assignments.destroy',
        ] as $routeName) {
            $this->assertTrue(Route::has($routeName), $routeName);
        }

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $viewer->forceFill(['locale' => $locale])->save();

            $groups = collect(app(AdminNavigation::class)->groupsFor($viewer->fresh()))
                ->where('key', 'field_operations')
                ->values();
            $this->assertCount(1, $groups);

            $items = collect($groups->first()['children']);
            foreach ([
                'field_ops_vans' => 'admin.field-operations.vans',
                'field_ops_assignments' => 'admin.field-operations.assignments',
            ] as $key => $route) {
                $matches = $items->where('key', $key)->values();
                $this->assertCount(1, $matches);
                $this->assertSame($route, $matches->first()['route']);
            }
            $this->assertNotContains('field_ops_fleet', $items->pluck('key')->all());
            $this->assertNotContains('field_ops_visits', $items->pluck('key')->all());

            $this->actingAs($viewer->fresh())
                ->get(route('admin.field-operations.vans'))
                ->assertOk()
                ->assertViewIs('admin.field-operations')
                ->assertViewHas('section', 'vans')
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('P2603-VAN-GROUP-ONE');

            $this->actingAs($viewer->fresh())
                ->get(route('admin.field-operations.assignments'))
                ->assertOk()
                ->assertViewIs('admin.field-operations')
                ->assertViewHas('section', 'assignments')
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-nav-item="field_ops_assignments"', false)
                ->assertViewHas('vans', static function ($vans) use ($van): bool {
                    return $vans->contains('id', $van->getKey());
                });
        }
    }

    public function test_readonly_van_actor_cannot_create_update_or_delete_an_assignment(): void
    {
        $viewer = $this->actorWithPermissions('P2603_READ_ONLY', ['drivers.b2b.view']);
        $operator = $this->actorWithPermissions('P2603_MANAGER_FOR_FIXTURE', [
            'drivers.b2b.view',
            'drivers.b2b.manage',
        ]);
        $van = app(VanRegistryService::class)->createVan(['code' => 'P2603-PROTECTED']);
        $assignment = app(VanRegistryService::class)->assign($operator, $van, [
            'assignment_type' => 'primary',
            'effective_from' => now()->addHour()->toDateTimeString(),
        ]);

        $this->actingAs($viewer)
            ->get(route('admin.field-operations.assignments'))
            ->assertOk();

        $this->actingAs($viewer)
            ->post(route('admin.field-operations.assignments.store', ['van' => $van]), [
                'assignment_type' => 'backup',
                'effective_from' => now()->addHours(2)->toDateTimeString(),
            ])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->patch(route('admin.field-operations.assignments.update', ['assignment' => $assignment]), [
                'van_id' => $van->getKey(),
                'assignment_type' => 'primary',
                'status' => 'ended',
                'effective_from' => now()->addHour()->toDateTimeString(),
            ])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->delete(route('admin.field-operations.assignments.destroy', ['assignment' => $assignment]), [
                'confirm_purge' => '1',
            ])
            ->assertForbidden();

        $this->assertSame(1, VanAssignment::query()->where('van_id', $van->getKey())->count());
        $this->assertSame('active', $assignment->fresh()->status);
    }

    public function test_authorized_manager_can_assign_van_but_overlapping_primary_is_rejected(): void
    {
        $manager = $this->actorWithPermissions('P2603_ASSIGNMENT_MANAGER', [
            'drivers.b2b.view',
            'drivers.b2b.manage',
        ]);
        $van = app(VanRegistryService::class)->createVan(['code' => 'P2603-WORKFLOW']);
        $from = now()->addHour()->toDateTimeString();
        $url = route('admin.field-operations.assignments.store', ['van' => $van]);

        $this->actingAs($manager)
            ->from(route('admin.field-operations.assignments'))
            ->post($url, [
                'assignment_type' => 'primary',
                'effective_from' => $from,
                'loaded_work_count' => 12,
            ])
            ->assertRedirect(route('admin.field-operations.assignments'))
            ->assertSessionHasNoErrors();

        $assignment = VanAssignment::query()->where('van_id', $van->getKey())->firstOrFail();
        $this->assertSame('active', $assignment->status);
        $this->assertSame('primary', $assignment->assignment_type);
        $this->assertSame(12, (int) $assignment->loaded_work_count);
        $this->assertSame((int) $manager->getKey(), (int) $assignment->created_by);

        $this->actingAs($manager)
            ->from(route('admin.field-operations.assignments'))
            ->post($url, [
                'assignment_type' => 'primary',
                'effective_from' => now()->addHours(2)->toDateTimeString(),
            ])
            ->assertRedirect(route('admin.field-operations.assignments'))
            ->assertSessionHasErrors('assignment');

        $this->assertSame(1, VanAssignment::query()->where('van_id', $van->getKey())->count());

        $this->actingAs($manager)
            ->get(route('admin.field-operations.assignments'))
            ->assertOk()
            ->assertSee('P2603-WORKFLOW');
    }

    public function test_empty_group_and_direct_van_routes_fail_closed_for_retail_roleless_inactive(): void
    {
        $retailStore = $this->retailStore('P2603-RETAIL-STORE');
        $retail = User::factory()->create(['is_active' => true]);
        DB::table('user_store_roles')->insert([
            'user_id' => $retail->getKey(),
            'store_id' => $retailStore->getKey(),
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $roleless = User::factory()->create(['is_active' => true]);
        $inactive = $this->actorWithPermissions('P2603_INACTIVE_MANAGER', [
            'drivers.b2b.view', 'drivers.b2b.manage',
        ]);
        $inactive->forceFill(['is_active' => false])->save();
        $van = app(VanRegistryService::class)->createVan(['code' => 'P2603-NO-MUTATION']);

        foreach ([$retail, $roleless, $inactive] as $actor) {
            foreach (['ar', 'en'] as $locale) {
                app()->setLocale($locale);
                $actor->forceFill(['locale' => $locale])->save();

                $children = $this->vanGroupChildren($actor->fresh())->pluck('key')->all();
                $this->assertNotContains('field_ops_vans', $children);
                $this->assertNotContains('field_ops_assignments', $children);

                $this->actingAs($actor->fresh())
                    ->get(route('admin.field-operations.vans'))
                    ->assertForbidden();
                $this->actingAs($actor->fresh())
                    ->get(route('admin.field-operations.assignments'))
                    ->assertForbidden();
                $this->actingAs($actor->fresh())
                    ->post(route('admin.field-operations.assignments.store', ['van' => $van]), [
                        'assignment_type' => 'primary',
                        'effective_from' => now()->addHour()->toDateTimeString(),
                    ])
                    ->assertForbidden();
            }
        }

        $this->assertDatabaseMissing('van_assignments', ['van_id' => $van->getKey()]);
    }

    public function test_explicit_global_view_deny_removes_van_and_assignment_entries_and_http_access(): void
    {
        $viewer = $this->actorWithPermissions('P2603_EXPLICIT_DENY', ['drivers.b2b.view']);
        $permission = Permission::query()->where('code', 'drivers.b2b.view')->firstOrFail();

        DB::table('user_permission_overrides')->insert([
            'user_id' => $viewer->getKey(),
            'permission_id' => $permission->getKey(),
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $viewer->forceFill(['has_permission_overrides' => true])->save();

        $items = $this->vanGroupChildren($viewer->fresh())->pluck('key')->all();
        $this->assertNotContains('field_ops_vans', $items);
        $this->assertNotContains('field_ops_assignments', $items);

        $this->actingAs($viewer->fresh())
            ->get(route('admin.field-operations.vans'))
            ->assertForbidden();
        $this->actingAs($viewer->fresh())
            ->get(route('admin.field-operations.assignments'))
            ->assertForbidden();
    }

    public function test_multi_role_retail_and_finance_does_not_grant_van_assignment_management(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'FINANCE')->firstOrFail());

        $this->assertNotContains('field_ops_assignments', $this->vanGroupChildren($actor)->pluck('key')->all());
        $this->actingAs($actor)
            ->get(route('admin.field-operations.assignments'))
            ->assertForbidden();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function vanGroupChildren(User $user): Collection
    {
        return collect(collect(app(AdminNavigation::class)->groupsFor($user))
            ->firstWhere('key', 'field_operations')['children'] ?? []);
    }

    /** @param list<string> $codes */
    private function actorWithPermissions(string $code, array $codes): User
    {
        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $role = Role::query()->create([
            'code' => $code,
            'name' => $code,
            'scope' => 'global',
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->attach(Permission::query()->whereIn('code', $codes)->pluck('id'));
        $actor->roles()->attach($role);

        return $actor;
    }

    private function retailStore(string $code): Store
    {
        return Store::query()->create([
            'store_type_id' => StoreType::query()->where('code', 'B2C')->firstOrFail()->getKey(),
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
    }
}
