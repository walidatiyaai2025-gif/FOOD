<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T353R1 / #2710: the Wholesale & Sales Manager persona must use live
 * server-authorized business destinations, not a role-name-driven menu.
 */
final class V2WholesaleSalesManagerPersonaAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_wholesale_sales_manager_reaches_six_business_capabilities_through_real_authorized_routes_in_both_locales(): void
    {
        $wholesaleStoreId = app(WholesalePrincipal::class)->storeId();
        $actor = $this->wholesaleManager();

        // The Sales function is a single authorized child of Catalog & Pricing,
        // not a second decorative Sidebar section.
        $required = [
            'overview' => 'enterprise_action_center',
            'accounts' => 'customer_groups',
            'catalog' => 'commercial_sales_control',
            'operations' => 'order_operations',
            'analytics' => 'reports_center',
        ];

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);
            app()->forgetInstance(AdminNavigation::class);

            $groups = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
                ->keyBy('key');
            $this->assertNotEmpty($groups->all());

            foreach ($required as $groupKey => $itemKey) {
                $group = $groups->get($groupKey);
                $this->assertIsArray($group, $locale.' expected authorized '.$groupKey.' group');
                $this->assertNotEmpty($group['children']);
                $item = collect($group['children'])->firstWhere('key', $itemKey);
                $this->assertIsArray($item, $locale.' expected authorized '.$itemKey.' destination');

                $this->assertSame(
                    1,
                    collect($groups->values())->flatMap(
                        static fn (array $entry): array => $entry['children'],
                    )->where('key', $itemKey)->count(),
                    $locale.' must not duplicate '.$itemKey,
                );
            }

            $keys = $groups->values()
                ->flatMap(static fn (array $group): array => $group['children'])
                ->pluck('key')->all();

            $this->assertContains('customer_360', $keys);
            $this->assertContains('catalog_management', $keys);
            $this->assertNotContains('b2c_dashboard', $keys);
            $this->assertNotContains('b2c_customers', $keys);
            $this->assertNotContains('b2c_orders', $keys);
            $this->assertNotContains('b2c_reports', $keys);

            foreach ([
                route('admin.action-center.index', [], false),
                route('admin.customer-360.index', [], false),
                route('admin.b2b.customer-groups.index', [], false),
                route('admin.catalog.index', [], false),
                route('admin.commercial.sales-control', ['store_id' => $wholesaleStoreId], false),
                route('admin.operations.orders.index', ['channel' => 'b2b'], false),
                route('admin.reports.index', [], false),
            ] as $url) {
                $response = $this->actingAs($actor->fresh())->get($url);
                $this->assertGreaterThanOrEqual(200, $response->getStatusCode(), $url);
                $this->assertLessThan(400, $response->getStatusCode(), $url.' must not be denied or missing');
            }

            $this->actingAs($actor->fresh())
                ->get(route('admin.operations.orders.index', ['channel' => 'b2b'], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);
        }
    }

    public function test_wholesale_sales_manager_cannot_use_retail_or_all_channel_order_routes_even_by_direct_http(): void
    {
        $actor = $this->wholesaleManager();

        foreach (['b2c', 'all'] as $channel) {
            $this->actingAs($actor)
                ->get(route('admin.operations.orders.index', ['channel' => $channel], false))
                ->assertForbidden();
        }

        $this->actingAs($actor)
            ->get(route('admin.b2c.module', ['module' => 'products'], false))
            ->assertForbidden();
        $this->actingAs($actor)
            ->get(route('admin.b2c.module', ['module' => 'customers'], false))
            ->assertForbidden();
    }

    public function test_explicit_reporting_deny_removes_reports_only_without_disabling_wholesale_customer_or_order_authority(): void
    {
        $actor = $this->wholesaleManager();
        $permissionId = Permission::query()->where('code', 'reports.view')->firstOrFail()->id;
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => $permissionId,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        $keys = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->pluck('key')->all();

        $this->assertNotContains('reports_center', $keys);
        $this->assertContains('customer_groups', $keys);
        $this->assertContains('order_operations', $keys);

        $this->actingAs($actor->fresh())
            ->get(route('admin.reports.index', [], false))
            ->assertForbidden();

        $this->actingAs($actor->fresh())
            ->get(route('admin.operations.orders.index', ['channel' => 'b2b'], false))
            ->assertOk();
    }

    public function test_roleless_and_inactive_identities_have_no_wholesale_sales_manager_authority(): void
    {
        $roleless = User::factory()->create(['is_active' => true]);
        $inactive = $this->wholesaleManager();
        $inactive->forceFill(['is_active' => false])->save();

        foreach ([$roleless, $inactive->fresh()] as $actor) {
            $this->assertSame([], app(AdminNavigation::class)->groupsFor($actor));

            $this->actingAs($actor)
                ->get(route('admin.operations.orders.index', ['channel' => 'b2b'], false))
                ->assertForbidden();
            $this->actingAs($actor)
                ->get(route('admin.reports.index', [], false))
                ->assertForbidden();
        }
    }

    private function wholesaleManager(): User
    {
        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());

        return $actor;
    }
}
