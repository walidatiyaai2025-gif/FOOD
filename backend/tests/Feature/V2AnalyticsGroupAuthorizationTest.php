<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * V2 P311: Analytics is a business-domain group, not a second report
 * renderer or a permission grant. Every visible child must be authorized.
 */
final class V2AnalyticsGroupAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_roleless_actor_sees_no_empty_analytics_group_and_cannot_open_reports(): void
    {
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor = User::factory()->create(['locale' => $locale, 'is_active' => true]);

            $this->assertNull(
                collect(app(AdminNavigation::class)->groupsFor($actor))
                    ->firstWhere('key', 'analytics'),
            );

            $this->actingAs($actor)
                ->get(route('admin.reports.index', ['report' => 'orders'], false))
                ->assertForbidden();
        }
    }

    public function test_authorized_global_actor_has_one_localized_analytics_group_and_real_report_destination(): void
    {
        $actor = $this->superAdmin();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $groups = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()));
            $analytics = $groups->where('key', 'analytics')->values();
            $this->assertCount(1, $analytics);

            $group = $analytics->first();
            $this->assertSame('admin.nav_groups.analytics', $group['label']);
            $this->assertNotEmpty($group['children']);

            $children = collect($group['children']);
            $reports = $children->where('key', 'reports_center')->values();
            $this->assertCount(1, $reports);
            $this->assertSame('admin.reports.index', $reports->first()['route']);
            $this->assertSame('reports.view', $reports->first()['permission']);

            // BI is a separate lifecycle/entitlement-governed ERP module.
            // No uncertified BI route is promoted by the Analytics grouping.
            $this->assertNotContains('erp_bi', $children->pluck('key')->all());
            foreach ($children as $item) {
                $this->assertTrue(Route::has($item['route']), $item['route']);
            }

            $this->actingAs($actor->fresh())
                ->get(route('admin.reports.index', ['report' => 'orders'], false))
                ->assertOk()
                ->assertViewIs('admin.reports')
                ->assertViewHas('report', 'orders')
                ->assertSee('dir="'.$direction.'"', false);
        }
    }

    public function test_retail_analytics_child_is_store_scoped_and_wholesale_report_is_not_granted(): void
    {
        $ownStore = $this->retailStore('P311-OWN');
        $foreignStore = $this->retailStore('P311-FOREIGN');
        $actor = $this->retailAdmin($ownStore);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $group = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
                ->firstWhere('key', 'analytics');
            $this->assertNotNull($group);

            $keys = collect($group['children'])->pluck('key')->all();
            $this->assertContains('b2c_reports', $keys);
            $this->assertNotContains('reports_center', $keys);
            $this->assertNotContains('b2b_reports', $keys);
            $this->assertNotContains('erp_bi', $keys);

            $item = collect($group['children'])->firstWhere('key', 'b2c_reports');
            $this->assertSame('admin.b2c.module', $item['route']);
            $this->assertSame('reports.view', $item['permission']);
            $this->assertSame((string) $ownStore, $item['params']['store_id']);
            $this->assertSame('reports', $item['params']['module']);

            $this->actingAs($actor->fresh())
                ->get(route('admin.b2c.module', ['module' => 'reports', 'store_id' => $ownStore], false))
                ->assertOk();

            $this->actingAs($actor->fresh())
                ->get(route('admin.b2c.module', ['module' => 'reports', 'store_id' => $foreignStore], false))
                ->assertNotFound();

            $this->actingAs($actor->fresh())
                ->get(route('admin.reports.index', [
                    'report' => 'customers', 'store_id' => $ownStore, 'channel' => 'b2c',
                ], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            $this->actingAs($actor->fresh())
                ->get(route('admin.reports.index', [
                    'report' => 'customers', 'store_id' => $foreignStore, 'channel' => 'b2c',
                ], false))
                ->assertForbidden();

            $this->actingAs($actor->fresh())
                ->get(route('admin.reports.index', [
                    'report' => 'customers', 'store_id' => $ownStore, 'channel' => 'b2b',
                ], false))
                ->assertForbidden();
        }
    }

    public function test_explicit_store_deny_hides_reports_from_analytics_and_blocks_direct_access(): void
    {
        $storeId = $this->retailStore('P311-DENY');
        $actor = $this->retailAdmin($storeId);
        $permission = Permission::query()->where('code', 'reports.view')->firstOrFail();

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => $permission->id,
            'scope_key' => 'store:'.$storeId,
            'store_id' => $storeId,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        $analytics = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
            ->firstWhere('key', 'analytics');

        $this->assertTrue(
            $analytics === null || collect($analytics['children'])
                ->where('key', 'b2c_reports')->isEmpty(),
        );

        $this->actingAs($actor->fresh())
            ->get(route('admin.reports.index', [
                'report' => 'customers', 'store_id' => $storeId, 'channel' => 'b2c',
            ], false))
            ->assertForbidden();
    }

    private function superAdmin(): User
    {
        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $actor;
    }

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function retailAdmin(int $storeId): User
    {
        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);

        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor;
    }
}
