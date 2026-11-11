<?php

namespace Tests\Feature;

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
 * P304: Reports & BI navigation must project effective server authority,
 * locale, tenant/store/channel boundaries and operational module readiness.
 */
final class V2AnalyticsSidebarGroupAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_wholesale_analytics_reuses_authorized_report_routes_in_both_locales(): void
    {
        $this->store('B2B', 'P304-WHOLESALE');
        $actor = $this->globalActor('B2B_ADMIN', 'p304-wholesale@example.test');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $group = $this->analyticsFor($actor->fresh());
            $this->assertIsArray($group);
            $this->assertSame('admin.nav_groups.analytics', $group['label']);
            $this->assertNotSame('admin.nav_groups.analytics', __('admin.nav_groups.analytics'));

            $children = collect($group['children'])->keyBy('key');
            $this->assertTrue($children->has('reports_center'));
            $this->assertFalse($children->has('b2c_reports'));

            foreach ($children as $item) {
                $this->assertTrue(Route::has($item['route']), 'Unknown P304 route: '.$item['route']);
                $response = $this->actingAs($actor->fresh())
                    ->get(route($item['route'], $item['params'], false));
                $this->assertTrue(
                    $response->getStatusCode() >= 200 && $response->getStatusCode() < 400,
                    "P304 visible route {$item['key']} denied HTTP {$response->getStatusCode()} ({$locale}).",
                );
            }

            $this->actingAs($actor->fresh())
                ->get(route('admin.reports.index', [], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);
        }
    }

    public function test_retail_analytics_never_links_to_foreign_store_or_wholesale_reports(): void
    {
        $own = $this->store('B2C', 'P304-OWN');
        $foreign = $this->store('B2C', 'P304-FOREIGN');
        $this->store('B2B', 'P304-WHOLESALE-OTHER');
        $actor = $this->retailActor($own, 'B2C_STORE_ADMIN');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $group = $this->analyticsFor($actor->fresh());

            $this->assertIsArray($group);
            $items = collect($group['children'])->keyBy('key');
            $this->assertTrue($items->has('b2c_reports'));
            $this->assertSame('admin.b2c.module', $items->get('b2c_reports')['route']);
            $this->assertSame((string) $own, $items->get('b2c_reports')['params']['store_id']);
            $this->assertFalse($items->has('b2b_reports'));
            $this->assertFalse($items->has('b2b_finance'));
            $this->assertFalse($items->has('erp_bi'));

            $this->actingAs($actor->fresh())
                ->get(route('admin.b2c.module', ['module' => 'reports', 'store_id' => $own], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);
            $this->actingAs($actor->fresh())
                ->get(route('admin.b2c.module', ['module' => 'reports', 'store_id' => $foreign], false))
                ->assertNotFound();
            $this->actingAs($actor->fresh())
                ->get(route('admin.b2b.module', ['module' => 'reports'], false))
                ->assertForbidden();
            $this->actingAs($actor->fresh())
                ->get(route('admin.erp.bi', [], false))
                ->assertForbidden();
        }
    }

    public function test_global_report_deny_blocks_navigation_and_direct_http_without_mutating_role(): void
    {
        $this->store('B2B', 'P304-DENY');
        $actor = $this->globalActor('B2B_ADMIN', 'p304-deny@example.test');
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $permission = Permission::query()->where('code', 'reports.view')->firstOrFail();

        DB::table('admin_sidebar_role_visibility')->insert([
            'role_id' => $role->id,
            'item_key' => 'reports_center',
            'is_visible' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $this->assertFalse($actor->fresh()->hasPermission('reports.view'));

            $children = collect($this->analyticsFor($actor->fresh())['children'] ?? [])
                ->pluck('key');
            $this->assertFalse($children->contains('reports_center'));
            $this->assertFalse($children->contains('b2b_reports'));
            $this->assertFalse($children->contains('erp_bi'));

            $this->actingAs($actor->fresh())
                ->get(route('admin.reports.index', [], false))
                ->assertForbidden();
        }

        $this->assertTrue($role->fresh()->permissions()->where('code', 'reports.view')->exists());
        $this->assertDatabaseHas('user_permission_overrides', [
            'user_id' => $actor->id,
            'permission_id' => $permission->id,
            'effect' => 'deny',
        ]);
    }

    public function test_uncertified_enterprise_bi_is_not_an_operational_sidebar_link(): void
    {
        $this->store('B2B', 'P304-BI-UNREADY');
        $actor = $this->globalActor('B2B_ADMIN', 'p304-unready@example.test');
        config()->set('enterprise_module_readiness.certified_versions', []);

        $children = collect($this->analyticsFor($actor)['children'] ?? [])->pluck('key');

        $this->assertTrue($children->contains('reports_center'));
        $this->assertFalse($children->contains('erp_bi'));
        $this->actingAs($actor)
            ->get(route('admin.erp.bi', [], false))
            ->assertForbidden();
    }

    public function test_roleless_or_non_reporting_store_role_cannot_open_analytics_reports(): void
    {
        $own = $this->store('B2C', 'P304-NO-REPORTS');
        $actors = [
            User::factory()->create(['is_active' => true]),
            $this->retailActor($own, 'STORE_STAFF'),
        ];

        foreach ($actors as $actor) {
            $children = collect($this->analyticsFor($actor)['children'] ?? [])->pluck('key');
            $this->assertFalse($children->contains('reports_center'));
            $this->assertFalse($children->contains('b2c_reports'));
            $this->assertFalse($children->contains('erp_bi'));

            $this->actingAs($actor)
                ->get(route('admin.reports.index', [], false))
                ->assertForbidden();
        }
    }

    private function analyticsFor(User $user): ?array
    {
        return collect(app(AdminNavigation::class)->groupsFor($user))
            ->firstWhere('key', 'analytics');
    }

    private function globalActor(string $roleCode, string $email): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user->fresh();
    }

    private function retailActor(int $storeId, string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $role = Role::query()->where('code', $roleCode)->firstOrFail();

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user->fresh();
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
