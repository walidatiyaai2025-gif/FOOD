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
 * P023: legacy Sidebar visibility/layout rows are presentation data only.
 * Actual HTTP access comes from role, channel, store and controller policy.
 */
final class V2SidebarAppearanceNonAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_hiding_unprotected_navigation_cannot_revoke_real_report_permission(): void
    {
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'ar']);
        $actor->roles()->attach($role->getKey());

        $this->assertTrue($actor->hasPermission('reports.view'));
        $this->assertContains('reports_center', $this->visibleKeys($actor));

        DB::table('admin_sidebar_groups')->insert([
            'group_key' => 'analytics',
            'is_enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('admin_sidebar_items')->insert([
            'item_key' => 'reports_center',
            'group_key' => 'analytics',
            'is_enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('admin_sidebar_role_visibility')->insert([
            'role_id' => $role->getKey(),
            'item_key' => 'reports_center',
            'is_visible' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $this->assertNotContains('reports_center', $this->visibleKeys($actor->fresh()));

            $this->actingAs($actor->fresh())
                ->get(route('admin.reports.index', [], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);
        }

        // Presentation changes must not create a new permission or change role grants.
        $this->assertTrue($actor->fresh()->hasPermission('reports.view'));
        $this->assertDatabaseHas('admin_sidebar_role_visibility', [
            'role_id' => $role->getKey(),
            'item_key' => 'reports_center',
            'is_visible' => false,
        ]);
    }

    public function test_enabling_visual_items_does_not_grant_store_actor_platform_access(): void
    {
        $owned = $this->store('B2C', 'P2774-OWNED');
        $foreign = $this->store('B2C', 'P2774-FOREIGN');
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        DB::table('user_store_roles')->insert([
            'user_id' => $actor->getKey(),
            'role_id' => $role->getKey(),
            'store_id' => $owned,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['erp_center', 'sidebar_role_access', 'treasury_master'] as $key) {
            DB::table('admin_sidebar_role_visibility')->insert([
                'role_id' => $role->getKey(),
                'item_key' => $key,
                'is_visible' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $keys = $this->visibleKeys($actor->fresh());

            foreach (['erp_center', 'sidebar_role_access', 'treasury_master', 'b2b_dashboard'] as $forbiddenKey) {
                $this->assertNotContains($forbiddenKey, $keys);
            }

            $this->actingAs($actor->fresh())->get(route('admin.erp.index', [], false))->assertForbidden();
            $this->actingAs($actor->fresh())->get(route('admin.sidebar-access.roles', [], false))->assertForbidden();
            $this->actingAs($actor->fresh())->get(route('admin.b2b.dashboard', [], false))->assertForbidden();

            $this->actingAs($actor->fresh())
                ->get(route('admin.b2c.module', [
                    'module' => 'dashboard',
                    'store_id' => $foreign,
                ], false))
                ->assertNotFound();

            $this->actingAs($actor->fresh())
                ->get(route('admin.b2c.module', [
                    'module' => 'dashboard',
                    'store_id' => $owned,
                ], false))
                ->assertOk();
        }

        $roleless = User::factory()->create(['is_active' => true]);
        $this->assertNotContains('sidebar_role_access', $this->visibleKeys($roleless));
        $this->actingAs($roleless)->get(route('admin.sidebar-access.roles', [], false))->assertForbidden();
    }

    private function visibleKeys(User $actor): array
    {
        return collect(app(AdminNavigation::class)->groupsFor($actor))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->pluck('key')
            ->all();
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
