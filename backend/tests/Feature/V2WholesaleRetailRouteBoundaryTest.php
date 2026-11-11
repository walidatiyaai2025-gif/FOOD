<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P031: HTTP authorization must isolate Wholesale, Retail and individual stores.
 *
 * The Sidebar is a projection, never a substitute for the route guard.
 */
final class V2WholesaleRetailRouteBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private int $wholesaleStore;

    private int $retailStore;

    private int $otherRetailStore;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);

        $this->wholesaleStore = $this->store('B2B', 'P031-WHOLESALE');
        $this->retailStore = $this->store('B2C', 'P031-RETAIL-A');
        $this->otherRetailStore = $this->store('B2C', 'P031-RETAIL-B');
    }

    public function test_wholesale_role_cannot_open_any_retail_workspace_even_with_a_forged_store_parameter(): void
    {
        $actor = $this->globalActor('B2B_ADMIN');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();

            $this->actingAs($actor->fresh())
                ->get(route('admin.b2b.dashboard', [], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            foreach (['dashboard', 'orders', 'inventory'] as $module) {
                $route = $module === 'dashboard' ? 'admin.b2c.dashboard' : 'admin.b2c.module';
                $parameters = $module === 'dashboard' ? [] : ['module' => $module];

                $this->actingAs($actor->fresh())
                    ->get(route($route, $parameters + ['store_id' => $this->retailStore], false))
                    ->assertForbidden();
            }
        }
    }

    public function test_retail_store_role_cannot_open_wholesale_dashboard_or_module_with_forged_store_id(): void
    {
        $actor = $this->retailActor();

        foreach (['ar', 'en'] as $locale) {
            $actor->forceFill(['locale' => $locale])->save();

            $this->actingAs($actor->fresh())
                ->get(route('admin.b2b.dashboard', ['store_id' => $this->wholesaleStore], false))
                ->assertForbidden();

            foreach (['products', 'inventory', 'orders'] as $module) {
                $this->actingAs($actor->fresh())
                    ->get(route('admin.b2b.module', [
                        'module' => $module,
                        'store_id' => $this->wholesaleStore,
                    ], false))
                    ->assertForbidden();
            }
        }
    }

    public function test_retail_store_idor_and_channel_mismatch_are_rejected_by_real_http(): void
    {
        $actor = $this->retailActor();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();

            $this->actingAs($actor->fresh())
                ->get(route('admin.b2c.module', [
                    'module' => 'orders',
                    'store_id' => $this->retailStore,
                ], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            foreach ([$this->otherRetailStore, $this->wholesaleStore] as $forbiddenId) {
                $this->actingAs($actor->fresh())
                    ->get(route('admin.b2c.module', [
                        'module' => 'orders',
                        'store_id' => $forbiddenId,
                    ], false))
                    ->assertNotFound();
            }
        }
    }

    public function test_platform_support_must_explicitly_opt_into_retail_context_and_is_audited(): void
    {
        $actor = $this->globalActor('SUPER_ADMIN');

        $this->actingAs($actor)
            ->get(route('admin.b2c.module', [
                'module' => 'orders',
                'store_id' => $this->retailStore,
            ], false))
            ->assertForbidden();

        $this->assertDatabaseMissing('audit_logs', [
            'user_id' => $actor->id,
            'event' => 'tenant.support_access.entered',
        ]);

        $this->actingAs($actor)
            ->get(route('admin.b2c.module', [
                'module' => 'orders',
                'store_id' => $this->retailStore,
                'support_access' => 1,
            ], false))
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $actor->id,
            'event' => 'tenant.support_access.entered',
        ]);
    }

    public function test_roleless_actor_cannot_open_either_channel_and_both_routes_remain_registered(): void
    {
        $this->assertTrue(Route::has('admin.b2b.dashboard'));
        $this->assertTrue(Route::has('admin.b2c.dashboard'));
        $this->assertTrue(Route::has('admin.b2b.module'));
        $this->assertTrue(Route::has('admin.b2c.module'));

        $roleless = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        $this->actingAs($roleless)
            ->get(route('admin.b2b.dashboard', [], false))
            ->assertForbidden();

        $this->actingAs($roleless)
            ->get(route('admin.b2c.dashboard', ['store_id' => $this->retailStore], false))
            ->assertForbidden();
    }

    private function globalActor(string $roleCode): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $actor;
    }

    private function retailActor(): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();

        DB::table('user_store_roles')->insert([
            'user_id' => $actor->getKey(),
            'store_id' => $this->retailStore,
            'role_id' => $role->getKey(),
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
