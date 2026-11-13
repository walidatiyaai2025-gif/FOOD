<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 T028R02: global Sidebar layout is presentation, never a B2C inventory
 * access grant or revocation. Complements P040's wholesale/catalog coverage.
 */
final class V2RetailInventoryLayoutHttpBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_hidden_retail_inventory_item_preserves_authorized_store_http_but_not_foreign_store_or_wholesale(): void
    {
        $ownStore = $this->retailStore('V2-T028R02-OWN');
        $foreignStore = $this->retailStore('V2-T028R02-FOREIGN');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $user = $this->retailInventoryUser($ownStore, $locale);

            $this->assertTrue($user->hasPermission('inventory.view', $ownStore));
            $this->assertFalse($user->hasPermission('inventory.view', $foreignStore));
            $this->assertContains('b2c_inventory', $this->visibleKeys($user));

            $this->setInventoryItemEnabled(false);
            $this->assertNotContains('b2c_inventory', $this->visibleKeys($user));

            $this->actingAs($user)
                ->get(route('admin.b2c.module', [
                    'module' => 'inventory',
                    'store_id' => $ownStore,
                ], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            $foreign = $this->actingAs($user)->get(route('admin.b2c.module', [
                'module' => 'inventory',
                'store_id' => $foreignStore,
            ], false));
            $this->assertContains($foreign->getStatusCode(), [403, 404]);

            $this->actingAs($user)
                ->get(route('admin.b2b.module', ['module' => 'inventory'], false))
                ->assertForbidden();

            $this->assertDatabaseHas('user_store_roles', [
                'user_id' => $user->getKey(),
                'store_id' => $ownStore,
            ]);
            $this->assertTrue($user->fresh()->hasPermission('inventory.view', $ownStore));

            $this->setInventoryItemEnabled(true);
            $this->assertContains('b2c_inventory', $this->visibleKeys($user));
        }
    }

    public function test_catalog_hiding_does_not_hide_inventory_but_inventory_group_hiding_preserves_http(): void
    {
        $ownStore = $this->retailStore('V2-T028R02-GROUP');
        $user = $this->retailInventoryUser($ownStore, 'en');

        $this->assertContains('b2c_inventory', $this->visibleKeys($user));
        // P246 moved inventory into its independent business group. Hiding
        // Catalog must not revoke or conceal the authorized Inventory route.
        $this->setGroupEnabled('catalog', false);
        $this->assertContains('b2c_inventory', $this->visibleKeys($user));
        $this->setGroupEnabled('inventory', false);
        $this->assertNotContains('b2c_inventory', $this->visibleKeys($user));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $user->forceFill(['locale' => $locale])->save();

            $this->actingAs($user)
                ->get(route('admin.b2c.module', [
                    'module' => 'inventory',
                    'store_id' => $ownStore,
                ], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            $this->assertTrue($user->fresh()->hasPermission('inventory.view', $ownStore));
            $this->assertNotContains('b2c_inventory', $this->visibleKeys($user));
        }

        $this->setGroupEnabled('inventory', true);
        $this->assertContains('b2c_inventory', $this->visibleKeys($user));
        $this->setGroupEnabled('catalog', true);
        $this->assertContains('b2c_inventory', $this->visibleKeys($user));
    }

    private function retailInventoryUser(int $storeId, string $locale): User
    {
        $user = User::factory()->create(['locale' => $locale, 'is_active' => true]);
        $role = Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail();

        DB::table('user_store_roles')->insert([
            'user_id' => $user->getKey(),
            'store_id' => $storeId,
            'role_id' => $role->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user->fresh();
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

    private function setInventoryItemEnabled(bool $enabled): void
    {
        DB::table('admin_sidebar_items')->updateOrInsert(
            ['item_key' => 'b2c_inventory'],
            [
                'group_key' => 'inventory',
                'parent_key' => null,
                'label_ar' => null,
                'label_en' => null,
                'sort_order' => 50,
                'is_enabled' => $enabled,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function setGroupEnabled(string $group, bool $enabled): void
    {
        DB::table('admin_sidebar_groups')->updateOrInsert(
            ['group_key' => $group],
            [
                'label_ar' => null,
                'label_en' => null,
                'icon' => $group,
                'sort_order' => $group === 'inventory' ? 44 : 40,
                'is_enabled' => $enabled,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    /** @return list<string> */
    private function visibleKeys(User $user): array
    {
        $keys = [];
        foreach (app(AdminNavigation::class)->groupsFor($user) as $group) {
            foreach ($group['children'] as $item) {
                $keys[] = $item['key'];
                foreach ((array) ($item['children'] ?? []) as $child) {
                    $keys[] = $child['key'];
                }
            }
        }

        return $keys;
    }
}
