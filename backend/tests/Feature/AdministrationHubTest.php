<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdministrationHubTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_administration_sidebar_keeps_hub_and_navigation_governance_entries_concise(): void
    {
        $user = $this->superAdmin();

        $administration = collect(app(AdminNavigation::class)->groupsFor($user))
            ->firstWhere('key', 'administration');

        $this->assertIsArray($administration);
        $this->assertCount(3, $administration['children']);
        $this->assertSame(
            ['administration_hub', 'sidebar_role_access', 'sidebar_layout'],
            collect($administration['children'])->pluck('key')->values()->all(),
        );
        $this->assertSame('admin.administration.index', $administration['children'][0]['route']);
        $this->assertSame('admin.sidebar-access.roles', $administration['children'][1]['route']);
        $this->assertSame('admin.sidebar-layout.index', $administration['children'][2]['route']);
    }

    public function test_administration_hub_exposes_customer_driver_and_van_as_first_class_apps(): void
    {
        $user = $this->superAdmin('en');

        $this->actingAs($user)
            ->get('/admin/administration')
            ->assertOk()
            ->assertSee('data-admin-hub="true"', false)
            ->assertSee('data-admin-app="customer"', false)
            ->assertSee('data-admin-app="driver"', false)
            ->assertSee('data-admin-app="van"', false)
            ->assertSee('data-apk-card="customer"', false)
            ->assertSee('data-apk-card="driver"', false)
            ->assertSee('data-apk-card="van"', false)
            ->assertSee('data-apk-open="customer"', false)
            ->assertSee('id="apk-download-modal"', false)
            ->assertSee('Customer App')
            ->assertSee('Driver App')
            ->assertSee('Van App')
            ->assertSee('data-admin-card="settings-center"', false)
            ->assertSee('data-admin-card="users-permissions"', false)
            ->assertSee('data-admin-card="system-inspector"', false)
            ->assertSee('data-admin-card="van-finance-support"', false)
            ->assertSee('data-admin-card="profile"', false)
            ->assertDontSee('data-admin-card="system-settings"', false)
            ->assertDontSee('data-admin-card="notifications"', false)
            ->assertDontSee('data-admin-card="publishing"', false)
            ->assertSee('data-nav-group="administration"', false)
            ->assertSee('aria-current="page"', false);
    }

    public function test_administration_hub_has_arabic_copy_and_rtl_runtime(): void
    {
        $user = $this->superAdmin('ar');

        $this->actingAs($user)
            ->get('/admin/administration')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('مركز الإدارة')
            ->assertSee('تطبيق العميل')
            ->assertSee('تطبيق السائق')
            ->assertSee('تطبيق الفان');
    }

    private function superAdmin(string $locale = 'en'): User
    {
        $user = User::query()->create([
            'name' => 'Administration Hub Owner',
            'email' => 'administration-hub@example.test',
            'password' => 'password',
            'locale' => $locale,
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }

    public function test_sidebar_groups_follow_business_domain_order_and_keep_administration_last(): void
    {
        $user = $this->superAdmin();

        $groups = collect(app(AdminNavigation::class)->groupsFor($user))
            ->pluck('key')
            ->values();

        $expectedOrder = [
            'overview',
            'stores',
            'catalog',
            'accounts',
            'operations',
            'field_operations',
            'marketing',
            'advertising',
            'finance',
            'analytics',
            'applications',
            'administration',
        ];

        $positions = collect($expectedOrder)
            ->filter(fn (string $key): bool => $groups->contains($key))
            ->map(fn (string $key): int => (int) $groups->search($key, true))
            ->values()
            ->all();
        $sortedPositions = $positions;
        sort($sortedPositions);

        $this->assertSame($sortedPositions, $positions);
        $this->assertSame('administration', $groups->last());
    }

    public function test_admin_hub_links_each_first_class_app_to_the_settings_center(): void
    {
        $view = file_get_contents(resource_path('views/admin/administration-hub.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString("route('admin.settings.index') }}#setting-mobile", $view);
        $this->assertStringContainsString("@foreach(['customer','driver','van'] as \$app)", $view);
    }
}
