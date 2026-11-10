<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use App\Support\V2SidebarMetadata;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class V2SidebarMetadataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_metadata_has_exactly_the_already_authorized_navigation_keys(): void
    {
        $user = $this->userWithRole('B2B_ADMIN', 'v2-metadata-b2b@example.test');

        $expected = $this->navigationKeys(app(AdminNavigation::class)->groupsFor($user));
        $actual = $this->metadataKeys(app(V2SidebarMetadata::class)->forUser($user));

        $this->assertNotEmpty($actual);
        $this->assertEqualsCanonicalizing($expected, $actual);
        $this->assertCount(count(array_unique($actual)), $actual);
        $this->assertNotContains('b2c_customers', $actual);
        $this->assertNotContains('b2c_orders', $actual);
    }

    public function test_unprivileged_user_never_gets_privileged_metadata_or_http_access(): void
    {
        $user = User::query()->create([
            'name' => 'V2 Restricted Metadata',
            'email' => 'v2-metadata-restricted@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $metadata = app(V2SidebarMetadata::class)->forUser($user);

        $this->assertEqualsCanonicalizing(
            $this->navigationKeys(app(AdminNavigation::class)->groupsFor($user)),
            $this->metadataKeys($metadata),
        );
        $this->assertNotContains('sidebar_layout', $this->metadataKeys($metadata));
        $this->actingAs($user)
            ->get(route('admin.sidebar-layout.index', [], false))
            ->assertForbidden();
    }

    public function test_role_visibility_denial_removes_metadata_without_rewriting_role_permissions(): void
    {
        $user = $this->userWithRole('B2B_ADMIN', 'v2-metadata-hidden@example.test');
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();

        $this->assertContains(
            'catalog_management',
            $this->metadataKeys(app(V2SidebarMetadata::class)->forUser($user)),
        );

        DB::table('admin_sidebar_role_visibility')->insert([
            'role_id' => $role->id,
            'item_key' => 'catalog_management',
            'is_visible' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNotContains(
            'catalog_management',
            $this->metadataKeys(app(V2SidebarMetadata::class)->forUser($user)),
        );
        $this->assertTrue($user->fresh()->hasPermission('catalog.view'));
    }

    public function test_metadata_uses_both_authoritative_localization_catalogs(): void
    {
        $user = $this->userWithRole('B2B_ADMIN', 'v2-metadata-l10n@example.test');
        $catalog = collect(app(V2SidebarMetadata::class)->forUser($user))
            ->firstWhere('group_key', 'catalog');

        $this->assertIsArray($catalog);
        $this->assertSame(__('admin.nav_groups.catalog', [], 'ar'), $catalog['label_ar']);
        $this->assertSame(__('admin.nav_groups.catalog', [], 'en'), $catalog['label_en']);
        $this->assertNotSame($catalog['label_ar'], $catalog['label_en']);
    }

    public function test_module_annotations_do_not_enable_modules_or_certify_readiness(): void
    {
        $expected = [
            'erp_procurement' => 'procurement',
            'erp_wms' => 'inventory_wms',
            'erp_finance' => 'finance',
            'erp_treasury' => 'treasury',
            'erp_bi' => 'enterprise_bi',
        ];

        foreach ($expected as $itemKey => $moduleCode) {
            $this->assertSame($moduleCode, config("admin_sidebar.items.{$itemKey}.module"));
        }

        $user = $this->userWithRole('B2B_ADMIN', 'v2-metadata-modules@example.test');
        foreach (app(V2SidebarMetadata::class)->forUser($user) as $group) {
            foreach ($group['items'] as $item) {
                $this->assertContains($item['readiness'], ['diagnostic_only', 'route_guarded']);
            }
        }
    }

    /** @return list<string> */
    private function navigationKeys(array $groups): array
    {
        $keys = [];
        foreach ($groups as $group) {
            foreach ((array) ($group['children'] ?? []) as $item) {
                $keys[] = $item['key'];
                foreach ((array) ($item['children'] ?? []) as $child) {
                    $keys[] = $child['key'];
                }
            }
        }

        return $keys;
    }

    /** @return list<string> */
    private function metadataKeys(array $groups): array
    {
        $keys = [];
        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                $keys[] = $item['item_key'];
                foreach ($item['children'] as $child) {
                    $keys[] = $child['item_key'];
                }
            }
        }

        return $keys;
    }

    private function userWithRole(string $roleCode, string $email): User
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
