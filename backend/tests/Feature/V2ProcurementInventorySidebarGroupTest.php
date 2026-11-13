<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\AdminSidebarManager;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P237: preserve the authorization boundaries of the canonical Procurement,
 * Inventory and Warehouses destinations while the target IA parent is reviewed.
 */
final class V2ProcurementInventorySidebarGroupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_p237_existing_business_destinations_have_unique_scoped_owners_and_bilingual_labels(): void
    {
        $registry = app(AdminSidebarManager::class)->registry();
        $groups = $registry['groups'];
        $items = $registry['items'];

        $expected = [
            'procurement' => ['erp_procurement' => 'procurement.suppliers.view'],
            'inventory' => [
                'b2b_inventory' => 'inventory.view',
                'b2c_inventory' => 'inventory.view',
            ],
            'warehouses' => ['erp_wms' => 'inventory.view'],
        ];

        foreach ($expected as $groupKey => $children) {
            $this->assertArrayHasKey($groupKey, $groups);
            $label = $groups[$groupKey]['label'];
            $this->assertTrue(Lang::hasForLocale($label, 'ar'), $groupKey.' Arabic label');
            $this->assertTrue(Lang::hasForLocale($label, 'en'), $groupKey.' English label');
            $this->assertNotSame(__($label, [], 'ar'), __($label, [], 'en'));

            foreach ($children as $key => $permission) {
                $this->assertArrayHasKey($key, $items);
                $this->assertSame($groupKey, $items[$key]['group']);
                $this->assertContains($permission, $items[$key]['permissions']);
            }
        }

        $this->assertLessThan($groups['inventory']['order'], $groups['procurement']['order']);
        $this->assertLessThan($groups['warehouses']['order'], $groups['inventory']['order']);
        $this->assertSame('procurement', $items['erp_procurement']['module']);
        $this->assertSame('inventory_wms', $items['erp_wms']['module']);
        $this->assertSame('b2b', $items['b2b_inventory']['channel']);
        $this->assertSame('b2c', $items['b2c_inventory']['channel']);
    }

    public function test_p237_roleless_and_inactive_actors_cannot_inherit_any_of_the_three_groups(): void
    {
        $roleless = User::factory()->create(['is_active' => true]);
        $inactive = User::factory()->create(['is_active' => false]);
        $inactive->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());

        foreach ([$roleless, $inactive->fresh()] as $actor) {
            $visible = array_column(app(AdminNavigation::class)->groupsFor($actor), 'key');

            foreach (['procurement', 'inventory', 'warehouses'] as $key) {
                $this->assertNotContains($key, $visible);
            }
        }

        $this->assertTrue(Route::has('admin.erp.procurement.index'));
        $this->assertTrue(Route::has('admin.erp.wms.index'));

        $this->actingAs($roleless)
            ->get(route('admin.erp.procurement.index'))
            ->assertForbidden();
        $this->actingAs($roleless)
            ->get(route('admin.erp.wms.index'))
            ->assertForbidden();
    }

    public function test_p237_business_domain_projection_does_not_grant_wholesale_inventory_to_regular_admin(): void
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $groups = app(AdminNavigation::class)->groupsFor($actor->fresh());
            $this->assertNotContains('inventory', array_column($groups, 'key'));

            $allKeys = collect($groups)
                ->flatMap(static fn (array $group): array => array_column($group['children'], 'key'))
                ->all();

            $this->assertNotContains('b2c_inventory', $allKeys);
            $this->assertNotContains('b2b_inventory', $allKeys);
            $this->assertNotContains('erp_procurement', $allKeys);
            $this->assertNotContains('erp_wms', $allKeys);
        }
    }
}
