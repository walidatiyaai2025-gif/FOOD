<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class V2ArabicSidebarNamingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_existing_sidebar_uses_canonical_arabic_names_without_changing_english_or_route_authority(): void
    {
        $arabicLabels = [
            'admin.customer_360' => 'العملاء 360',
            'admin.nav_groups.erp' => 'تخطيط موارد المؤسسة (ERP)',
            'admin.nav_groups.catalog' => 'المنتجات والكتالوج',
            'admin.nav_groups.advertising' => 'الإعلانات',
            'admin.nav_groups.analytics' => 'التقارير والتحليلات',
            'admin.catalog_management' => 'إدارة المنتجات والتصنيفات',
            'admin.retail_store_provisioning' => 'تهيئة متاجر التجزئة',
        ];

        foreach ($arabicLabels as $key => $expected) {
            $this->assertSame($expected, __($key, [], 'ar'), "Unexpected Arabic navigation label for {$key}.");
            $this->assertNotSame($key, __($key, [], 'en'), "Missing English navigation translation for {$key}.");
        }

        $admin = $this->user('B2B_ADMIN', 'v2-arabic-sidebar@example.test');

        app()->setLocale('ar');
        $arabicGroups = app(AdminNavigation::class)->groupsFor($admin);
        app()->setLocale('en');
        $englishGroups = app(AdminNavigation::class)->groupsFor($admin);

        $structure = static fn (array $groups): array => array_map(
            static fn (array $group): array => [
                'key' => $group['key'],
                'label_key' => $group['label'],
                'children' => array_map(
                    static fn (array $item): array => [
                        'key' => $item['key'],
                        'label_key' => $item['label'],
                        'route' => $item['route'],
                        'params' => $item['params'],
                        'permission' => $item['permission'],
                    ],
                    $group['children'],
                ),
            ],
            $groups,
        );

        $this->assertSame($structure($arabicGroups), $structure($englishGroups));

        $catalog = collect($arabicGroups)->firstWhere('key', 'catalog');
        $this->assertNotNull($catalog);
        $this->assertSame('admin.nav_groups.catalog', $catalog['label']);
        $catalogItem = collect($catalog['children'])->firstWhere('key', 'catalog_management');
        $this->assertNotNull($catalogItem);
        $this->assertSame('admin.catalog.index', $catalogItem['route']);
        $this->assertSame('admin.catalog_management', $catalogItem['label']);
    }

    public function test_renamed_catalog_link_remains_authorized_by_the_real_server_permission(): void
    {
        $admin = $this->user('B2B_ADMIN', 'v2-sidebar-catalog-allowed@example.test');
        $this->actingAs($admin)
            ->get(route('admin.catalog.index', [], false))
            ->assertOk();

        $unprivileged = User::query()->create([
            'name' => 'Unprivileged Sidebar Viewer',
            'email' => 'v2-sidebar-catalog-denied@example.test',
            'password' => 'password',
            'locale' => 'ar',
            'is_active' => true,
        ]);

        $this->actingAs($unprivileged)
            ->get(route('admin.catalog.index', [], false))
            ->assertForbidden();

        $this->assertFalse(
            collect(app(AdminNavigation::class)->groupsFor($unprivileged))
                ->flatMap(fn (array $group): array => $group['children'])
                ->contains('key', 'catalog_management'),
        );
    }

    private function user(string $roleCode, string $email): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => $email,
            'password' => 'password',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }
}
