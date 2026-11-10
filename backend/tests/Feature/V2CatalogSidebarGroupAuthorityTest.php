<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P219: The Catalog Sidebar group projects real route/tenant authorization.
 */
final class V2CatalogSidebarGroupAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_catalog_business_group_exposes_only_real_authorized_wholesale_destinations_in_both_locales(): void
    {
        $actor = $this->globalActor('B2B_ADMIN', 'v2-p219-wholesale@example.test');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $groups = app(AdminNavigation::class)->groupsFor($actor->fresh());
            $catalog = collect($groups)->firstWhere('key', 'catalog');
            $this->assertIsArray($catalog);
            $this->assertSame('admin.nav_groups.catalog', $catalog['label']);
            $this->assertNotEmpty($catalog['children']);
            $this->assertSame(__('admin.nav_groups.catalog', [], $locale), __('admin.nav_groups.catalog', [], $locale));

            foreach ($catalog['children'] as $item) {
                $this->assertTrue(Route::has($item['route']), 'An authorized Catalog link must have a registered route.');
            }

            $management = collect($catalog['children'])->firstWhere('key', 'catalog_management');
            $this->assertIsArray($management);
            $this->assertSame('catalog.view', $management['permission']);
            $this->assertSame('admin.catalog.index', $management['route']);

            $this->actingAs($actor->fresh())
                ->get(route($management['route'], $management['params'] ?? [], false))
                ->assertOk()
                ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'">', false)
                ->assertSee(__('admin.nav_groups.catalog', [], $locale));
        }
    }

    public function test_retail_catalog_group_uses_assigned_store_and_rejects_foreign_store_or_wholesale_context(): void
    {
        $own = $this->store('P219-OWN');
        $foreign = $this->store('P219-FOREIGN');
        $actor = User::query()->create([
            'name' => 'P219 Store Catalog Manager',
            'email' => 'v2-p219-retail@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);

        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $own,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $catalog = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
            ->firstWhere('key', 'catalog');
        $this->assertIsArray($catalog);
        $productEntry = collect($catalog['children'])->firstWhere('key', 'b2c_products');
        $this->assertIsArray($productEntry, 'Retail catalog must be discoverable through the Catalog business group.');

        $this->actingAs($actor)
            ->get(route($productEntry['route'], $productEntry['params'], false))
            ->assertOk();

        $this->actingAs($actor)
            ->get(route('admin.catalog.index', ['tab' => 'products', 'store_id' => $own], false))
            ->assertOk();

        $this->actingAs($actor)
            ->get(route('admin.catalog.index', ['tab' => 'products', 'store_id' => $foreign], false))
            ->assertForbidden();

        $this->actingAs($actor)
            ->get(route('admin.b2b.module', ['module' => 'products'], false))
            ->assertForbidden();
    }

    public function test_empty_catalog_group_is_removed_and_cosmetic_group_suppression_never_grants_http_permission(): void
    {
        $roleless = User::query()->create([
            'name' => 'P219 Roleless',
            'email' => 'v2-p219-roleless@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);

        $this->assertNull(collect(app(AdminNavigation::class)->groupsFor($roleless))->firstWhere('key', 'catalog'));
        $this->actingAs($roleless)->get(route('admin.catalog.index', [], false))->assertForbidden();

        $admin = $this->globalActor('B2B_ADMIN', 'v2-p219-enabled@example.test');
        $this->assertNotNull(collect(app(AdminNavigation::class)->groupsFor($admin))->firstWhere('key', 'catalog'));

        DB::table('admin_sidebar_groups')->insert([
            'group_key' => 'catalog',
            'label_ar' => null,
            'label_en' => null,
            'icon' => '▦',
            'sort_order' => 40,
            'is_enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNull(collect(app(AdminNavigation::class)->groupsFor($admin->fresh()))->firstWhere('key', 'catalog'));
        $this->assertTrue($admin->fresh()->hasPermission('catalog.view'));
        $this->actingAs($admin->fresh())
            ->get(route('admin.catalog.index', [], false))
            ->assertOk();

        $admin->forceFill(['is_active' => false])->save();
        $this->assertSame([], app(AdminNavigation::class)->groupsFor($admin->fresh()));
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

    private function store(string $code): int
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
