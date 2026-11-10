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
 * P222: Categories is an authorized tab of the existing Catalog screen,
 * not a parallel Sidebar route or an independent permission grant.
 */
final class V2CategoryTabAuthorizedScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_wholesale_catalog_sidebar_leads_to_real_categories_tab_in_both_locales(): void
    {
        $actor = $this->globalActor('B2B_ADMIN', 'v2-p222-wholesale@example.test');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $catalog = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
                ->firstWhere('key', 'catalog');
            $this->assertIsArray($catalog);

            $item = collect($catalog['children'])->firstWhere('key', 'catalog_management');
            $this->assertIsArray($item, 'The categories tab needs a discoverable Catalog domain entry.');
            $this->assertSame('admin.catalog.index', $item['route']);
            $this->assertSame('catalog.view', $item['permission']);

            $this->actingAs($actor->fresh())
                ->get(route($item['route'], $item['params'] ?? [], false))
                ->assertOk()
                ->assertSee('tab=categories', false);

            $this->actingAs($actor->fresh())
                ->get(route('admin.catalog.index', ['tab' => 'categories'], false))
                ->assertOk()
                ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'">', false)
                ->assertSee(__('catalog_completion.tabs.categories', [], $locale))
                ->assertSee('data-catalog-category-filters', false)
                ->assertSee('action="'.route('admin.catalog.categories.store').'"', false);
        }

        // Historical deep links are redirects, never a second category renderer.
        $this->actingAs($actor->fresh())
            ->get(route('admin.catalog.categories.index', [], false))
            ->assertRedirect(route('admin.catalog.index', ['tab' => 'categories']));
    }

    public function test_retail_category_tab_and_write_are_scoped_to_the_assigned_store(): void
    {
        $own = $this->retailStore('V2-P222-OWN');
        $foreign = $this->retailStore('V2-P222-OTHER');
        $manager = User::query()->create([
            'name' => 'P222 Retail Manager',
            'email' => 'v2-p222-store@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        DB::table('user_store_roles')->insert([
            'user_id' => $manager->id,
            'store_id' => $own,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $catalog = collect(app(AdminNavigation::class)->groupsFor($manager->fresh()))
            ->firstWhere('key', 'catalog');
        $this->assertIsArray($catalog);
        $this->assertTrue(collect($catalog['children'])->contains('key', 'b2c_products'));

        $this->actingAs($manager)
            ->get(route('admin.b2c.module', ['module' => 'products', 'store_id' => $own], false))
            ->assertOk()
            ->assertSee('Manage Categories');

        $this->actingAs($manager)
            ->get(route('admin.catalog.index', ['tab' => 'categories', 'store_id' => $own], false))
            ->assertOk()
            ->assertSee('data-catalog-category-filters', false);

        $this->actingAs($manager)
            ->post(route('admin.catalog.categories.store', [], false), [
                'store_id' => $own,
                'name' => 'Authorized Store Category',
                'slug' => 'v2-p222-owned-category',
                'is_active' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('categories', [
            'slug' => 'v2-p222-owned-category',
            'name' => 'Authorized Store Category',
        ]);

        $this->actingAs($manager)
            ->get(route('admin.catalog.index', ['tab' => 'categories', 'store_id' => $foreign], false))
            ->assertForbidden();

        $this->actingAs($manager)
            ->post(route('admin.catalog.categories.store', [], false), [
                'store_id' => $foreign,
                'name' => 'Forbidden Other Store Category',
                'slug' => 'v2-p222-foreign-category',
                'is_active' => 1,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('categories', ['slug' => 'v2-p222-foreign-category']);
    }

    public function test_ineligible_actors_cannot_discover_or_open_categories_and_wholesale_cannot_inspect_retail(): void
    {
        $roleless = User::query()->create([
            'name' => 'P222 Roleless',
            'email' => 'v2-p222-roleless@example.test',
            'password' => 'password',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $inactive = $this->globalActor('B2B_ADMIN', 'v2-p222-inactive@example.test');
        $inactive->forceFill(['is_active' => false])->save();

        foreach ([$roleless, $inactive] as $actor) {
            $catalog = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
                ->firstWhere('key', 'catalog');
            $this->assertFalse(
                collect($catalog['children'] ?? [])->contains('key', 'catalog_management'),
                'An ineligible actor must not gain the catalog destination through appearance.',
            );

            $this->actingAs($actor->fresh())
                ->get(route('admin.catalog.index', ['tab' => 'categories'], false))
                ->assertForbidden();
        }

        $otherRetail = $this->retailStore('V2-P222-DENIED');
        $wholesale = $this->globalActor('B2B_ADMIN', 'v2-p222-other-channel@example.test');
        $this->actingAs($wholesale)
            ->get(route('admin.catalog.index', [
                'tab' => 'categories',
                'store_id' => $otherRetail,
            ], false))
            ->assertNotFound();
    }

    private function globalActor(string $code, string $email): User
    {
        $actor = User::query()->create([
            'name' => $code,
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $actor->roles()->attach(Role::query()->where('code', $code)->firstOrFail());

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
