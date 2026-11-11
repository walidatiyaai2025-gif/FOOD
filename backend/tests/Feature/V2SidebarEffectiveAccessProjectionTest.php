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
 * P006: Sidebar is the output of canonical RBAC, tenant, route and module
 * decisions. Persisted visual preferences never grant HTTP authority.
 */
final class V2SidebarEffectiveAccessProjectionTest extends TestCase
{
    use RefreshDatabase;

    private int $retailA;

    private int $retailB;

    private int $wholesale;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);

        $this->retailA = $this->store('B2C', 'P006-RETAIL-A');
        $this->retailB = $this->store('B2C', 'P006-RETAIL-B');
        $this->wholesale = $this->store('B2B', 'P006-WHOLESALE');
    }

    public function test_saved_show_never_projects_unauthorized_wholesale_or_governance_routes_for_retail_role(): void
    {
        $actor = $this->retailActor();
        $roleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id;

        foreach (['b2b_inventory', 'erp_finance', 'sidebar_role_access'] as $key) {
            DB::table('admin_sidebar_role_visibility')->insert([
                'role_id' => $roleId,
                'item_key' => $key,
                'is_visible' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            $keys = $this->visibleKeys($actor->fresh());

            foreach (['b2b_inventory', 'erp_finance', 'sidebar_role_access'] as $forbidden) {
                $this->assertNotContains($forbidden, $keys, $locale.' must deny '.$forbidden);
            }

            $this->actingAs($actor->fresh())
                ->get(route('admin.b2c.module', ['module' => 'inventory', 'store_id' => $this->retailA], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            $this->actingAs($actor->fresh())
                ->get(route('admin.b2c.module', ['module' => 'inventory', 'store_id' => $this->retailB], false))
                ->assertNotFound();

            $this->actingAs($actor->fresh())
                ->get(route('admin.b2c.module', ['module' => 'inventory', 'store_id' => $this->wholesale], false))
                ->assertNotFound();

            $this->actingAs($actor->fresh())
                ->get(route('admin.b2b.module', ['module' => 'inventory', 'store_id' => $this->wholesale], false))
                ->assertForbidden();

            $this->actingAs($actor->fresh())
                ->get(route('admin.sidebar-access.roles', [], false))
                ->assertForbidden();
        }
    }

    public function test_cosmetic_hide_never_revokes_wholesale_authorized_http_permission(): void
    {
        $actor = $this->wholesaleActor();
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();

        $this->assertTrue($actor->hasPermission('catalog.view'));
        $this->assertContains('catalog_management', $this->visibleKeys($actor));

        DB::table('admin_sidebar_role_visibility')->insert([
            'role_id' => $role->id,
            'item_key' => 'catalog_management',
            'is_visible' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();

            $this->assertNotContains('catalog_management', $this->visibleKeys($actor->fresh()));
            $this->actingAs($actor->fresh())
                ->get(route('admin.catalog.index', [], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);
            $this->assertTrue($actor->fresh()->hasPermission('catalog.view'));
        }

        $this->assertDatabaseHas('admin_sidebar_role_visibility', [
            'role_id' => $role->id,
            'item_key' => 'catalog_management',
            'is_visible' => false,
        ]);
    }

    public function test_revoked_role_stops_authorizing_routes_even_when_sidebar_display_is_forced_visible(): void
    {
        $actor = $this->wholesaleActor();
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();

        DB::table('admin_sidebar_role_visibility')->insert([
            'role_id' => $role->id,
            'item_key' => 'catalog_management',
            'is_visible' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertContains('catalog_management', $this->visibleKeys($actor));
        $this->actingAs($actor)->get(route('admin.catalog.index', [], false))->assertOk();

        $role->forceFill(['is_active' => false])->save();

        foreach (['ar', 'en'] as $locale) {
            $actor->forceFill(['locale' => $locale])->save();

            $this->assertFalse($actor->fresh()->hasPermission('catalog.view'));
            $this->assertNotContains('catalog_management', $this->visibleKeys($actor->fresh()));
            $this->actingAs($actor->fresh())
                ->get(route('admin.catalog.index', [], false))
                ->assertForbidden();
            $this->actingAs($actor->fresh())
                ->get(route('admin.b2b.dashboard', [], false))
                ->assertForbidden();
        }
    }

    public function test_inactive_and_roleless_users_cannot_gain_dashboard_authority_from_saved_display_rows(): void
    {
        $activeWithoutRole = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $inactive = $this->wholesaleActor();
        $inactive->forceFill(['is_active' => false])->save();

        $this->assertNotContains('catalog_management', $this->visibleKeys($activeWithoutRole));
        $this->actingAs($activeWithoutRole)
            ->get(route('admin.catalog.index', [], false))
            ->assertForbidden();

        $this->assertSame([], app(AdminNavigation::class)->groupsFor($inactive->fresh()));
        $this->actingAs($inactive->fresh())
            ->get(route('admin.catalog.index', [], false))
            ->assertForbidden();
    }

    /** @return list<string> */
    private function visibleKeys(User $user): array
    {
        $keys = [];
        foreach (app(AdminNavigation::class)->groupsFor($user) as $group) {
            foreach ($group['children'] as $item) {
                $keys[] = (string) $item['key'];
                foreach ($item['children'] ?? [] as $child) {
                    $keys[] = (string) $child['key'];
                }
            }
        }

        return $keys;
    }

    private function wholesaleActor(): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());

        return $actor;
    }

    private function retailActor(): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'store_id' => $this->retailA,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor;
    }

    private function store(string $channel, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $channel)->value('id'),
            'business_profile' => $channel === 'B2C' ? 'RETAIL_STORE' : null,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
