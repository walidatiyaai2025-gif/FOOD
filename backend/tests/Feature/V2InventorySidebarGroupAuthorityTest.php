<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\UserStoreRole;
use App\Services\AdminSidebarManager;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2InventorySidebarGroupAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_p246_inventory_group_reuses_registered_authorized_channel_destinations(): void
    {
        $registry = app(AdminSidebarManager::class)->registry();
        $this->assertSame('admin.nav_groups.inventory', $registry['groups']['inventory']['label']);
        $this->assertSame('inventory', $registry['items']['b2b_inventory']['group']);
        $this->assertSame('inventory', $registry['items']['b2c_inventory']['group']);

        $this->store('B2B', 'P246-WHOLESALE');
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'CUSTOMER_SERVICE_MANAGER')->firstOrFail());

        $groups = collect(app(AdminNavigation::class)->groupsFor($actor));
        $inventory = $groups->firstWhere('key', 'inventory');
        $this->assertNotNull($inventory);
        $items = collect($inventory['children'])->keyBy('key');
        $this->assertTrue($items->has('b2b_inventory'));
        $this->assertSame('admin.b2b.module', $items['b2b_inventory']['route']);
        $this->assertSame('inventory', $items['b2b_inventory']['params']['module']);
        $this->assertFalse($items->has('b2c_inventory'));

        // P246 is a placement change, not a new B2B Admin privilege/visibility grant.
        $ordinary = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $ordinary->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());
        $this->assertFalse(collect(app(AdminNavigation::class)->groupsFor($ordinary))
            ->contains('key', 'inventory'));
        $this->assertFalse(collect($groups->firstWhere('key', 'catalog')['children'] ?? [])
            ->contains('key', 'b2b_inventory'));

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $this->actingAs($actor->fresh())
                ->get(route('admin.b2b.module', ['module' => 'inventory']))
                ->assertOk()
                ->assertSee(__('admin.nav_groups.inventory'))
                ->assertSee('dir="'.$direction.'"', false);
        }
    }

    public function test_retail_inventory_is_store_scoped_and_not_a_wholesale_grant(): void
    {
        $storeId = $this->store('B2C', 'P246-RETAIL-OWN');
        $foreignStoreId = $this->store('B2C', 'P246-RETAIL-FOREIGN');
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        UserStoreRole::query()->create([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail()->id,
        ]);

        $groups = collect(app(AdminNavigation::class)->groupsFor($actor));
        $items = collect($groups->firstWhere('key', 'inventory')['children'] ?? [])->keyBy('key');
        $this->assertTrue($items->has('b2c_inventory'));
        $this->assertSame('admin.b2c.module', $items['b2c_inventory']['route']);
        $this->assertFalse($items->has('b2b_inventory'));

        $this->actingAs($actor)->get(route('admin.b2c.module', [
            'module' => 'inventory',
            'store_id' => $storeId,
        ]))->assertOk();

        $foreign = $this->actingAs($actor)->get(route('admin.b2c.module', [
            'module' => 'inventory',
            'store_id' => $foreignStoreId,
        ]));
        $this->assertContains($foreign->getStatusCode(), [403, 404]);
        $this->actingAs($actor)->get(route('admin.b2b.module', ['module' => 'inventory']))
            ->assertForbidden();
    }

    public function test_roleless_user_sees_no_inventory_group_and_cannot_open_the_workspaces(): void
    {
        $storeId = $this->store('B2C', 'P246-ROLELESS');
        $actor = User::factory()->create(['is_active' => true]);
        $this->assertFalse(collect(app(AdminNavigation::class)->groupsFor($actor))
            ->contains('key', 'inventory'));

        $this->actingAs($actor)->get(route('admin.b2c.module', [
            'module' => 'inventory',
            'store_id' => $storeId,
        ]))->assertForbidden();
        $this->actingAs($actor)->get(route('admin.b2b.module', ['module' => 'inventory']))
            ->assertForbidden();
    }

    private function store(string $type, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $type)->value('id'),
            'business_profile' => $type === 'B2C' ? 'RETAIL_STORE' : null,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
