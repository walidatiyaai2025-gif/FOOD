<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AdminSidebarManager;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P266: the Field Operations Sidebar group is a projection of the existing
 * server-authorized routes. Never display an empty or denied group.
 */
final class V2FieldOperationsSidebarGroupAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_field_operations_reuses_the_registered_business_group_and_hides_it_without_any_child_grant(): void
    {
        $registry = app(AdminSidebarManager::class)->registry();
        $this->assertSame('admin.nav_groups.field_operations', $registry['groups']['field_operations']['label']);

        $actor = $this->operator(['P266_REPORTER' => ['reports.view']]);
        $this->assertTrue(app(AdminNavigation::class)->canUseDashboard($actor));

        $groups = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()));
        $this->assertNull($groups->firstWhere('key', 'field_operations'));
        $this->assertSame('Fleet & Field Operations', __('admin.nav_groups.field_operations', [], 'en'));
        $this->assertSame('الأسطول والعمليات الميدانية', __('admin.nav_groups.field_operations', [], 'ar'));

        foreach (['overview', 'vans', 'visits', 'routing', 'finance'] as $route) {
            $this->actingAs($actor->fresh())
                ->get(route('admin.field-operations.'.$route))
                ->assertForbidden();
        }
    }

    public function test_multi_role_wholesale_reader_sees_only_the_five_authorized_children(): void
    {
        $actor = $this->operator([
            'P266_VAN_READER' => ['drivers.b2b.view'],
            'P266_CUSTOMER_READER' => ['customers.view'],
        ]);

        $this->assertEqualsCanonicalizing([
            'field_ops_overview',
            'field_ops_vans',
            'field_ops_assignments',
            'field_ops_customers',
            'field_ops_visits',
        ], $this->children($actor));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $this->actingAs($actor->fresh())
                ->get(route('admin.field-operations.visits'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-nav-group="field_operations"', false)
                ->assertSee('data-nav-item="field_ops_visits"', false);

            foreach (['fleet', 'routing', 'finance'] as $deniedRoute) {
                $this->actingAs($actor->fresh())
                    ->get(route('admin.field-operations.'.$deniedRoute))
                    ->assertForbidden();
            }
        }
    }

    public function test_explicit_denies_override_two_inherited_role_grants_and_remove_the_entire_group(): void
    {
        $actor = $this->operator([
            'P266_VANS' => ['drivers.b2b.view'],
            'P266_TRACKING' => ['drivers.tracking.view'],
        ]);
        $this->assertContains('field_ops_vans', $this->children($actor));
        $this->assertContains('field_ops_fleet', $this->children($actor));

        foreach (['drivers.b2b.view', 'drivers.tracking.view'] as $code) {
            DB::table('user_permission_overrides')->insert([
                'user_id' => $actor->id,
                'permission_id' => Permission::query()->where('code', $code)->firstOrFail()->id,
                'scope_key' => 'global',
                'store_id' => null,
                'effect' => 'deny',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $actor->forceFill(['has_permission_overrides' => true])->save();

        $this->assertFalse($actor->fresh()->hasPermission('drivers.b2b.view'));
        $this->assertFalse($actor->fresh()->hasPermission('drivers.tracking.view'));
        $this->assertSame([], $this->children($actor->fresh()));
        $this->assertNull(collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
            ->firstWhere('key', 'field_operations'));

        foreach (['overview', 'fleet', 'vans', 'assignments'] as $route) {
            $this->actingAs($actor->fresh())
                ->get(route('admin.field-operations.'.$route))
                ->assertForbidden();
        }
    }

    /** @return list<string> */
    private function children(User $user): array
    {
        $group = collect(app(AdminNavigation::class)->groupsFor($user->fresh()))
            ->firstWhere('key', 'field_operations');

        return collect($group['children'] ?? [])->pluck('key')->all();
    }

    /** @param array<string, list<string>> $roleDefinitions */
    private function operator(array $roleDefinitions): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        foreach ($roleDefinitions as $code => $permissions) {
            $role = Role::query()->create([
                'code' => $code,
                'name' => $code,
                'scope' => 'global',
                'is_system' => false,
                'is_active' => true,
            ]);
            $role->permissions()->attach(
                Permission::query()->whereIn('code', $permissions)->pluck('id'),
            );
            $user->roles()->attach($role);
        }

        return $user;
    }
}
