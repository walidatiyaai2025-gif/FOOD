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

    public function test_administration_sidebar_is_one_hub_entry_instead_of_a_long_submenu(): void
    {
        $user = $this->superAdmin();

        $administration = collect(app(AdminNavigation::class)->groupsFor($user))
            ->firstWhere('key', 'administration');

        $this->assertIsArray($administration);
        $this->assertCount(1, $administration['children']);
        $this->assertSame('administration_hub', $administration['children'][0]['key']);
        $this->assertSame('admin.administration.index', $administration['children'][0]['route']);
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
            ->assertSee('Customer App')
            ->assertSee('Driver App')
            ->assertSee('Van App')
            ->assertSee('data-admin-card="users-permissions"', false)
            ->assertSee('data-admin-card="system-settings"', false)
            ->assertSee('data-admin-card="notifications"', false)
            ->assertSee('data-admin-card="publishing"', false)
            ->assertSee('data-admin-card="integrations"', false)
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

    public function test_admin_hub_links_each_first_class_app_to_its_settings(): void
    {
        $view = file_get_contents(resource_path('views/admin/administration-hub.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString("route('admin.mobile-settings.index', ['app'=>\$app, 'environment'=>'production'])", $view);
        $this->assertStringContainsString("@foreach(['customer','driver','van'] as $app)", $view);
    }
}
