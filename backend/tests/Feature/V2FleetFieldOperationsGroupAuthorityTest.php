<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreType;
use App\Models\User;
use App\Services\AdminSidebarManager;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P259: The Fleet & Field Operations group is a projection of server-authorized
 * destinations, not a separate capability grant or a second navigation tree.
 */
final class V2FleetFieldOperationsGroupAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private const ROUTES = [
        'field_ops_overview' => 'admin.field-operations.overview',
        'field_ops_fleet' => 'admin.field-operations.fleet',
        'field_ops_vans' => 'admin.field-operations.vans',
        'field_ops_assignments' => 'admin.field-operations.assignments',
        'field_ops_customers' => 'admin.field-operations.customers',
        'field_ops_visits' => 'admin.field-operations.visits',
        'field_ops_territories' => 'admin.field-operations.territories',
        'field_ops_routing' => 'admin.field-operations.routing',
        'field_ops_finance' => 'admin.field-operations.finance',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_p259_has_one_reused_fleet_group_and_nine_existing_registered_routes(): void
    {
        $registry = app(AdminSidebarManager::class)->registry();
        $this->assertArrayHasKey('field_operations', $registry['groups']);
        $this->assertSame('admin.nav_groups.field_operations', $registry['groups']['field_operations']['label']);

        $registered = collect($registry['items'])->filter(
            static fn (array $item): bool => $item['group'] === 'field_operations',
        );
        $this->assertEqualsCanonicalizing(array_keys(self::ROUTES), $registered->keys()->all());

        foreach (self::ROUTES as $key => $routeName) {
            $this->assertSame('field_operations', $registered[$key]['group']);
            $this->assertTrue(Route::has($routeName), "P259 contains an unresolved URL: {$routeName}");
            $this->assertSame('global', $registered[$key]['scope']);
        }

        $this->assertSame('Fleet & Field Operations', __('admin.nav_groups.field_operations', [], 'en'));
        $this->assertSame('الأسطول والعمليات الميدانية', __('admin.nav_groups.field_operations', [], 'ar'));
    }

    public function test_authorized_owner_gets_one_navigable_group_and_real_http_pages_in_both_locales(): void
    {
        $owner = $this->superAdmin();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $owner->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $groups = collect(app(AdminNavigation::class)->groupsFor($owner->fresh()))
                ->where('key', 'field_operations')
                ->values();

            $this->assertCount(1, $groups);
            $children = collect($groups->first()['children'])->keyBy('key');
            $this->assertEqualsCanonicalizing(array_keys(self::ROUTES), $children->keys()->all());

            foreach (self::ROUTES as $key => $route) {
                $this->assertSame($route, $children[$key]['route']);
            }

            $this->actingAs($owner->fresh())
                ->get(route('admin.field-operations.routing'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-nav-group="field_operations"', false)
                ->assertSee('data-nav-item="field_ops_routing"', false)
                ->assertSee(__('admin.nav_groups.field_operations', [], $locale))
                ->assertSee('data-foodex-nav', false);
        }
    }

    public function test_multi_role_operator_only_sees_and_opens_routes_granted_by_effective_permissions(): void
    {
        $actor = $this->operatorWithRoles([
            'P259_FLEET_TRACKING' => ['drivers.tracking.view'],
            'P259_ROUTING' => ['field_ops.manage'],
        ]);

        $keys = $this->children($actor)->pluck('key')->all();
        $this->assertEqualsCanonicalizing([
            'field_ops_overview',
            'field_ops_fleet',
            'field_ops_territories',
            'field_ops_routing',
        ], $keys);

        $this->actingAs($actor)->get(route('admin.field-operations.routing'))
            ->assertOk()
            ->assertSee('data-nav-item="field_ops_routing"', false);
        $this->actingAs($actor)->get(route('admin.field-operations.fleet'))->assertOk();
        $this->actingAs($actor)->get(route('admin.field-operations.vans'))->assertForbidden();
        $this->actingAs($actor)->get(route('admin.field-operations.finance'))->assertForbidden();
        $this->actingAs($actor)->get(route('admin.field-operations.visits'))->assertForbidden();
    }

    public function test_roleless_inactive_and_retail_store_actor_cannot_use_wholesale_territory_and_van_routes(): void
    {
        $roleless = User::factory()->create(['is_active' => true]);
        $inactive = $this->operatorWithRoles(['P259_INACTIVE' => ['field_ops.manage', 'drivers.b2b.view']]);
        $inactive->forceFill(['is_active' => false])->save();

        $retail = User::factory()->create(['is_active' => true]);
        $retailStore = $this->retailStore();
        DB::table('user_store_roles')->insert([
            'user_id' => $retail->id,
            'store_id' => $retailStore->id,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([$roleless, $inactive, $retail] as $actor) {
            $keys = $this->children($actor)->pluck('key')->all();
            $this->assertNotContains('field_ops_territories', $keys);
            $this->assertNotContains('field_ops_routing', $keys);
            $this->assertNotContains('field_ops_vans', $keys);

            $this->actingAs($actor)->get(route('admin.field-operations.territories'))->assertForbidden();
            $this->actingAs($actor)->get(route('admin.field-operations.routing'))->assertForbidden();
            $this->actingAs($actor)->get(route('admin.field-operations.vans'))->assertForbidden();
        }

        $this->assertSame([], $this->children($roleless)->all());
        $this->assertSame([], $this->children($inactive)->all());
    }

    public function test_reused_sidebar_shell_preserves_keyboard_and_mobile_discovery_contract(): void
    {
        $shell = file_get_contents(resource_path('views/admin/_sidebar.blade.php'));

        $this->assertIsString($shell);
        $this->assertStringContainsString('data-nav-group="{{ $group[\'key\'] }}"', $shell);
        $this->assertStringContainsString('<summary>', $shell);
        $this->assertStringContainsString('summary:focus-visible', $shell);
        $this->assertStringContainsString('data-foodex-sidebar-toggle', $shell);
        $this->assertStringContainsString('data-foodex-nav-search-empty', $shell);
        $this->assertStringContainsString('aria-label=', $shell);
        $this->assertStringContainsString('@media(max-width:440px)', $shell);
    }

    private function children(User $user): Collection
    {
        $group = collect(app(AdminNavigation::class)->groupsFor($user->fresh()))
            ->firstWhere('key', 'field_operations');

        return collect($group['children'] ?? []);
    }

    /** @param array<string, list<string>> $roleDefinitions */
    private function operatorWithRoles(array $roleDefinitions): User
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach ($roleDefinitions as $roleCode => $permissions) {
            $role = Role::query()->create([
                'code' => $roleCode,
                'name' => $roleCode,
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

    private function superAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }

    private function retailStore(): Store
    {
        $type = StoreType::query()->firstOrCreate(['code' => 'B2C'], ['name' => 'Retail']);

        return Store::query()->create([
            'store_type_id' => $type->id,
            'business_profile' => 'RETAIL_STORE',
            'code' => 'P259-RETAIL',
            'name' => 'P259 Retail',
            'is_active' => true,
        ]);
    }
}
