<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * V2 P344: Modules & Settings is the existing Settings Center platform group.
 * A restricted tile must never become an independent grant to its HTTP route.
 */
final class V2ModulesSettingsGroupAuthorizedContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_platform_settings_group_has_real_authorized_destinations_in_arabic_and_english(): void
    {
        $actor = $this->actor(['SUPER_ADMIN']);

        $routes = [
            'enterprise_modules' => 'admin.enterprise-modules.index',
            'foodex_reset' => 'admin.foodex-reset.index',
            'production_dataset' => 'admin.production-dataset-switch.index',
            'production_launch' => 'admin.production-launch.index',
            'system_update' => 'admin.system-update.index',
        ];

        foreach ($routes as $routeName) {
            $this->assertTrue(Route::has($routeName), 'Missing platform settings route '.$routeName);
        }

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();

            $response = $this->actingAs($actor->fresh())
                ->get(route('admin.settings.index'))
                ->assertOk()
                ->assertSee('data-settings-center="true"', false)
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('id="settings-platform"', false)
                ->assertSee('data-settings-group="platform"', false);

            foreach ($routes as $key => $routeName) {
                $response
                    ->assertSee('data-setting="'.$key.'"', false)
                    ->assertSee('class="settings-card__action" href="'.route($routeName).'"', false);
            }

            $this->actingAs($actor->fresh())
                ->get(route('admin.enterprise-modules.index'))
                ->assertOk()
                ->assertSee('data-enterprise-module-control-plane="true"', false);

            $this->actingAs($actor->fresh())
                ->get(route('admin.system-update.index'))
                ->assertOk();
        }
    }

    public function test_unprivileged_roles_see_noninteractive_group_and_all_direct_destinations_deny(): void
    {
        $moduleRows = DB::table('enterprise_modules')->count();
        $auditRows = DB::table('audit_logs')->count();

        foreach ([['FINANCE'], ['B2B_ADMIN'], []] as $roles) {
            $actor = $this->actor($roles);

            $this->actingAs($actor)
                ->get(route('admin.settings.index'))
                ->assertOk()
                ->assertSee('data-settings-group="platform"', false)
                ->assertSee('data-setting="enterprise_modules"', false)
                ->assertDontSee('class="settings-card__action" href="'.route('admin.enterprise-modules.index').'"', false)
                ->assertDontSee('class="settings-card__action" href="'.route('admin.system-update.index').'"', false);

            foreach ($this->platformDestinations() as $routeName) {
                $this->actingAs($actor)
                    ->get(route($routeName))
                    ->assertForbidden();
            }
        }

        $this->assertSame($moduleRows, DB::table('enterprise_modules')->count());
        $this->assertSame($auditRows, DB::table('audit_logs')->count());
    }

    public function test_store_scoped_retail_role_cannot_inherit_platform_module_management(): void
    {
        $store = Store::query()->create([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'P344-RETAIL',
            'name' => 'P344 Retail',
            'is_active' => true,
        ]);

        $actor = $this->actor([]);
        $retailRole = Role::query()
            ->where('code', 'B2C_STORE_ADMIN')
            ->where('scope', 'store')
            ->firstOrFail();

        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => $retailRole->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($actor->fresh())
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('data-settings-group="platform"', false)
            ->assertDontSee('class="settings-card__action" href="'.route('admin.enterprise-modules.index').'"', false);

        $this->actingAs($actor->fresh())
            ->get(route('admin.enterprise-modules.index'))
            ->assertForbidden();
    }

    public function test_explicit_global_deny_removes_delegated_platform_module_tile_and_http_access(): void
    {
        $role = Role::query()->where('code', 'FINANCE')->firstOrFail();
        $permission = Permission::query()->where('code', 'platform.manage')->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$permission->id]);

        $actor = $this->actor(['FINANCE']);
        $this->assertTrue($actor->hasPermission('platform.manage'));

        $this->actingAs($actor)
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('class="settings-card__action" href="'.route('admin.enterprise-modules.index').'"', false);

        $this->actingAs($actor)
            ->get(route('admin.enterprise-modules.index'))
            ->assertOk();

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => $permission->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
        $actor = $actor->fresh();

        $this->assertFalse($actor->hasPermission('platform.manage'));

        $this->actingAs($actor)
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertDontSee('class="settings-card__action" href="'.route('admin.enterprise-modules.index').'"', false);

        $this->actingAs($actor)
            ->get(route('admin.enterprise-modules.index'))
            ->assertForbidden();
    }

    /** @return list<string> */
    private function platformDestinations(): array
    {
        return [
            'admin.enterprise-modules.index',
            'admin.foodex-reset.index',
            'admin.production-dataset-switch.index',
            'admin.production-launch.index',
            'admin.system-update.index',
        ];
    }

    /** @param list<string> $roleCodes */
    private function actor(array $roleCodes): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        foreach ($roleCodes as $roleCode) {
            $actor->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());
        }

        return $actor;
    }
}
