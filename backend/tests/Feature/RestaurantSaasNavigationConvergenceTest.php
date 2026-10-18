<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RestaurantSaasNavigationConvergenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_restaurant_saas_has_one_sidebar_domain_entry_without_qr_or_analytics_duplicates(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $storeId = $this->retailStore();
        $user = $this->retailAdmin($storeId);

        $items = collect(app(AdminNavigation::class)->groupsFor($user))
            ->flatMap(fn (array $group) => $group['children'])
            ->values();

        $restaurant = $items->where('key', 'smart_ordering')->values();

        $this->assertCount(1, $restaurant);
        $this->assertSame('admin.smart-ordering.index', $restaurant->first()['route']);
        $this->assertFalse($items->contains('key', 'smart_ordering_qr'));
        $this->assertFalse($items->contains('key', 'smart_ordering_analytics'));
    }

    public function test_wholesale_only_admin_does_not_receive_restaurant_saas_navigation(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $user = User::query()->create([
            'name' => 'Wholesale Navigation Admin',
            'email' => 'wholesale-navigation@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());

        $items = collect(app(AdminNavigation::class)->groupsFor($user))
            ->flatMap(fn (array $group) => $group['children'])
            ->values();

        $this->assertFalse($items->contains('key', 'smart_ordering'));
    }

    public function test_shared_restaurant_workspace_exposes_canonical_surfaces_and_permission_gates(): void
    {
        $source = file_get_contents(resource_path('views/admin/_smart-ordering-workspace-nav.blade.php'));

        foreach ([
            'admin.smart-ordering.index',
            'admin.smart-ordering.operations.index',
            'admin.smart-ordering.menu.index',
            'admin.smart-ordering.appearance.index',
            'admin.smart-ordering.qr.index',
            'admin.smart-ordering.analytics.index',
            'admin.smart-ordering.supply.index',
            'admin.smart-ordering.integrations.index',
            'admin.smart-ordering.growth.index',
        ] as $route) {
            $this->assertStringContainsString($route, $source);
        }

        foreach ([
            'smart_ordering.view',
            'smart_ordering.manage',
            'orders.view',
            'smart_ordering.qr.view',
            'smart_ordering.analytics.view',
        ] as $permission) {
            $this->assertStringContainsString($permission, $source);
        }

        $this->assertStringContainsString('data-restaurant-saas-workspace-nav', $source);
        $this->assertStringContainsString('data-restaurant-saas-context-nav', $source);
        $this->assertStringContainsString("'support_access' => \$restaurantSupport ? 1 : null", $source);
        $this->assertStringNotContainsString('customers.show', $source);
        $this->assertStringNotContainsString('service-recovery.index', $source);
    }

    public function test_restaurant_management_launcher_covers_authorized_capabilities_and_declares_real_gaps(): void
    {
        $workspace = file_get_contents(resource_path('views/admin/_smart-ordering-workspace-nav.blade.php'));
        $launcher = file_get_contents(resource_path('views/admin/_restaurant-management-launcher.blade.php'));
        $english = file_get_contents(lang_path('en/smart_ordering.php'));
        $arabic = file_get_contents(lang_path('ar/smart_ordering.php'));
        $audit = json_decode(
            (string) file_get_contents(base_path('../docs/execution/RESTAURANT_WEB_WORKSPACE_AUDIT.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertStringContainsString('admin._restaurant-management-launcher', $workspace);
        foreach (['inventory.view', 'customers.view', 'promotions.view', 'finance.view', 'reports.view'] as $permission) {
            $this->assertStringContainsString($permission, $workspace);
        }

        foreach ([
            'admin.smart-ordering.operations.index',
            'admin.smart-ordering.menu.index',
            'admin.smart-ordering.appearance.index',
            'admin.smart-ordering.qr.index',
            'admin.smart-ordering.analytics.index',
            'admin.smart-ordering.supply.index',
            'admin.smart-ordering.integrations.index',
            'admin.smart-ordering.growth.index',
            'admin.b2c.module',
            'admin.reports.index',
        ] as $route) {
            $this->assertStringContainsString($route, $launcher);
        }

        $this->assertStringContainsString('restaurant-kds-live', $launcher);
        $this->assertStringContainsString('restaurant-kitchen-config', $launcher);
        $this->assertStringContainsString("'pos'", $launcher);
        $this->assertStringContainsString("'partial'", $launcher);
        $this->assertStringNotContainsString('admin.erp.', $launcher);
        $this->assertStringContainsString("'Restaurant Management workspace'", $english);
        $this->assertStringContainsString("'مساحة إدارة المطعم'", $arabic);

        $rows = collect($audit['capabilities'])->keyBy('id');
        foreach ([
            'overview', 'orders', 'kds', 'kitchen_config', 'floor', 'reservations', 'waitlist',
            'pos', 'dispatch', 'menu', 'qr', 'customers_crm', 'offers_loyalty', 'staff_shifts',
            'inventory', 'recipes_food_cost', 'purchasing', 'payments', 'finance', 'analytics',
            'reports', 'integrations', 'settings',
        ] as $capability) {
            $this->assertTrue($rows->has($capability), 'Missing restaurant workspace capability '.$capability);
        }

        $this->assertSame('PARTIAL_GAP', $rows['pos']['state']);
        $this->assertSame('PARTIAL_GAP', $rows['dispatch']['state']);
        $this->assertSame('PARTIAL_GAP', $rows['purchasing']['state']);
        $this->assertFalse($audit['exit_contract']['platform_erp_linked_from_restaurant_workspace']);
    }

    public function test_all_canonical_restaurant_operator_pages_mount_the_shared_workspace_navigation(): void
    {
        foreach ([
            'smart-ordering.blade.php',
            'smart-ordering-operations.blade.php',
            'smart-ordering-menu.blade.php',
            'smart-ordering-qr.blade.php',
            'smart-ordering-analytics.blade.php',
            'smart-ordering/appearance.blade.php',
            'smart-ordering-supply.blade.php',
            'smart-ordering-integrations.blade.php',
            'smart-ordering-growth.blade.php',
        ] as $view) {
            $source = file_get_contents(resource_path('views/admin/'.$view));
            $this->assertStringContainsString(
                "@include('admin._smart-ordering-workspace-nav')",
                $source,
                'Missing Restaurant SaaS workspace navigation in '.$view,
            );
        }
    }

    public function test_restaurant_settings_page_uses_focused_tabbed_information_architecture(): void
    {
        $view = file_get_contents(resource_path('views/admin/smart-ordering.blade.php'));
        $launcher = file_get_contents(resource_path('views/admin/_restaurant-management-launcher.blade.php'));
        $onboarding = file_get_contents(resource_path('views/admin/_restaurant-onboarding.blade.php'));
        $content = file_get_contents(resource_path('views/admin/_smart-ordering-content-editor.blade.php'));

        $this->assertIsString($view);
        foreach (['overview', 'launch', 'site', 'content', 'modules'] as $tab) {
            $this->assertStringContainsString('data-so-settings-tab="'.$tab.'"', $view);
            $this->assertStringContainsString('data-so-settings-panel="'.$tab.'"', $view);
        }
        $this->assertStringContainsString('data-so-settings-summary', $view);
        $this->assertStringContainsString('restaurantManagementCompact = true', $view);
        $this->assertStringContainsString('so-quick-links', $view);
        $this->assertStringContainsString('sessionStorage.setItem(storageKey, name)', $view);
        $this->assertStringContainsString("foodex:restaurant-settings-tab:", $view);
        $this->assertStringContainsString('$analyticsQuery = array_filter([', $view);
        $this->assertStringContainsString('$operationsQuery = array_filter([', $view);
        $this->assertStringContainsString("\$ovTrend = is_array(\$ov['trend'] ?? null)", $view);
        $this->assertStringContainsString("\$ovOrderStatus = is_array(\$ov['order_status'] ?? null)", $view);
        $this->assertStringContainsString("\$ovBranches = \$ov['branches'] ?? collect()", $view);
        $this->assertStringNotContainsString('@php\n            $ov = $restaurantOverview;', $view);
        $analyticsDefinition = strpos($view, '$analyticsQuery = array_filter([');
        $analyticsUsage = strpos($view, "route('admin.smart-ordering.analytics.export',$analyticsQuery)");
        $this->assertNotFalse($analyticsDefinition);
        $this->assertNotFalse($analyticsUsage);
        $this->assertLessThan($analyticsUsage, $analyticsDefinition);
        $this->assertStringContainsString("['restaurantOnboardingCompact'=>true]", $view);
        $this->assertStringContainsString("['restaurantContentCompact'=>true]", $view);

        $this->assertStringContainsString('restaurant-primary-tasks.is-compact', $launcher);
        $this->assertStringContainsString('so-onboarding-gates-disclosure', $onboarding);
        $this->assertStringNotContainsString('@forelse', $view);
        $this->assertStringNotContainsString('@endforelse', $view);
        $this->assertStringNotContainsString('@forelse', $onboarding);
        $this->assertStringNotContainsString('@endforelse', $onboarding);
        $this->assertStringContainsString('data-so-content-tabs', $content);
        $this->assertStringContainsString('data-so-content-panel="story"', $content);
    }

    public function test_qr_and_analytics_keep_restaurant_saas_sidebar_active(): void
    {
        foreach ([
            app_path('Http/Controllers/Admin/SmartOrderingQrController.php'),
            app_path('Http/Controllers/Admin/SmartOrderingAnalyticsController.php'),
        ] as $path) {
            $source = file_get_contents($path);
            $this->assertStringContainsString("'navContext' => 'smart_ordering'", $source);
            $this->assertStringNotContainsString("'navContext' => 'smart_ordering_qr'", $source);
            $this->assertStringNotContainsString("'navContext' => 'smart_ordering_analytics'", $source);
        }
    }

    private function retailAdmin(int $storeId): User
    {
        $user = User::query()->create([
            'name' => 'Restaurant Navigation Admin',
            'email' => 'restaurant-navigation@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function retailStore(): int
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => 'RESTAURANT-NAV',
            'name' => 'Restaurant Navigation',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->enableRestaurantSaasForTestStore($storeId);

        return $storeId;
    }
}
