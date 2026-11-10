<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P221: Products must use the existing channel-specific Dashboard routes.
 *
 * The Sidebar is a discoverability projection, not an authorization grant.
 * Every permitted destination must work over HTTP, while channel/store and
 * role boundaries remain enforced by the respective workspace controllers.
 */
final class V2ProductsAuthorizedSidebarHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_wholesale_products_are_discoverable_and_open_through_the_authorized_b2b_workspace_in_ar_and_en(): void
    {
        $actor = $this->globalActor('B2B_ADMIN', 'v2-p221-wholesale@example.test');
        $this->assertTrue($actor->hasPermission('catalog.view'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $group = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
                ->firstWhere('key', 'catalog');
            $this->assertIsArray($group);

            $item = collect($group['children'])->firstWhere('key', 'b2b_products');
            $this->assertIsArray($item, 'Products must be discoverable from the Catalog business group.');
            $this->assertSame('admin.b2b.module', $item['route']);
            $this->assertSame('catalog.view', $item['permission']);
            $this->assertSame('admin.b2b_sidebar.product_management', $item['label']);
            $this->assertSame('products', $item['params']['module']);

            $this->actingAs($actor->fresh())
                ->get(route($item['route'], $item['params'], false))
                ->assertOk()
                ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'">', false);
        }
    }

    public function test_retail_products_link_uses_the_authorized_store_and_denies_another_store_in_both_locales(): void
    {
        $ownStore = $this->retailStore('P221-OWN');
        $foreignStore = $this->retailStore('P221-FOREIGN');
        $actor = $this->storeActor('v2-p221-retail@example.test', $ownStore);

        $before = DB::table('products')->count();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $group = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
                ->firstWhere('key', 'catalog');
            $this->assertIsArray($group);

            $item = collect($group['children'])->firstWhere('key', 'b2c_products');
            $this->assertIsArray($item, 'Retail Products must be accessible without guessing a store URL.');
            $this->assertSame('admin.b2c.module', $item['route']);
            $this->assertSame('catalog.view', $item['permission']);
            $this->assertSame('admin.b2c_workspace.modules.products', $item['label']);
            $this->assertSame('products', $item['params']['module']);
            $this->assertSame((string) $ownStore, $item['params']['store_id']);

            $this->actingAs($actor->fresh())
                ->get(route($item['route'], $item['params'], false))
                ->assertOk()
                ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'">', false);

            $this->actingAs($actor->fresh())
                ->get(route('admin.b2c.module', ['module' => 'products', 'store_id' => $foreignStore], false))
                ->assertNotFound();
        }

        $this->assertSame($before, DB::table('products')->count(), 'Read-only navigation must not change product data.');
    }

    public function test_roleless_inactive_and_wrong_channel_actors_cannot_use_product_destinations(): void
    {
        $ownStore = $this->retailStore('P221-DENY');
        $roleless = User::query()->create([
            'name' => 'No Products Role',
            'email' => 'v2-p221-roleless@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $inactive = $this->globalActor('B2B_ADMIN', 'v2-p221-inactive@example.test');
        $inactive->forceFill(['is_active' => false])->save();

        foreach ([$roleless, $inactive] as $actor) {
            $group = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
                ->firstWhere('key', 'catalog');
            $children = collect($group['children'] ?? []);

            $this->assertFalse($children->contains('key', 'b2b_products'));
            $this->assertFalse($children->contains('key', 'b2c_products'));

            $this->actingAs($actor->fresh())
                ->get(route('admin.b2b.module', ['module' => 'products'], false))
                ->assertForbidden();

            $this->actingAs($actor->fresh())
                ->get(route('admin.b2c.module', ['module' => 'products', 'store_id' => $ownStore], false))
                ->assertForbidden();
        }

        $retail = $this->storeActor('v2-p221-cross-channel@example.test', $ownStore);
        $retailGroup = collect(app(AdminNavigation::class)->groupsFor($retail->fresh()))
            ->firstWhere('key', 'catalog');
        $this->assertFalse(collect($retailGroup['children'] ?? [])->contains('key', 'b2b_products'));

        $this->actingAs($retail)
            ->get(route('admin.b2b.module', ['module' => 'products'], false))
            ->assertForbidden();
    }

    public function test_unsupported_product_workspace_module_is_not_a_discoverable_or_executable_page(): void
    {
        $wholesale = $this->globalActor('B2B_ADMIN', 'v2-p221-unknown@example.test');

        $this->actingAs($wholesale)
            ->get(route('admin.b2b.module', ['module' => 'products-unavailable'], false))
            ->assertNotFound();

        $retailStore = $this->retailStore('P221-UNKNOWN');
        $retail = $this->storeActor('v2-p221-retail-unknown@example.test', $retailStore);

        $this->actingAs($retail)
            ->get(route('admin.b2c.module', ['module' => 'products-unavailable', 'store_id' => $retailStore], false))
            ->assertNotFound();
    }

    private function globalActor(string $roleCode, string $email): User
    {
        $actor = User::query()->create([
            'name' => $roleCode,
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $actor->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $actor;
    }

    private function storeActor(string $email, int $storeId): User
    {
        $actor = User::query()->create([
            'name' => 'Retail Catalog Actor',
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor;
    }

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => 'RETAIL_STORE',
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
