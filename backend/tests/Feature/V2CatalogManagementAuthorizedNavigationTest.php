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
 * P220: Catalog Management must be discovered and opened through the same
 * effective authorization used by the real protected Laravel route.
 */
final class V2CatalogManagementAuthorizedNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_wholesale_catalog_destination_is_authorized_discoverable_and_localized_in_both_directions(): void
    {
        $actor = $this->globalActor('B2B_ADMIN', 'v2-p220-b2b@example.test');
        $this->assertTrue($actor->hasPermission('catalog.view'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $groups = app(AdminNavigation::class)->groupsFor($actor->fresh());
            $catalogGroup = collect($groups)->firstWhere('key', 'catalog');
            $this->assertIsArray($catalogGroup, 'Authorized catalog navigation must have a business-domain group.');

            $item = collect($catalogGroup['children'])->firstWhere('key', 'catalog_management');
            $this->assertIsArray($item, 'Catalog Management must be discoverable without direct URL knowledge.');
            $this->assertSame('admin.catalog.index', $item['route']);
            $this->assertSame('catalog.view', $item['permission']);
            $this->assertSame('admin.catalog_management', $item['label']);
            $this->assertSame(__('admin.catalog_management', [], $locale), $item['label_text']);

            $this->actingAs($actor->fresh())
                ->get(route($item['route'], $item['params'] ?? [], false))
                ->assertOk()
                ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'">', false)
                ->assertSee(__('admin.catalog_management', [], $locale));
        }
    }

    public function test_roleless_and_inactive_actors_have_no_catalog_destination_or_http_access(): void
    {
        $roleless = User::query()->create([
            'name' => 'No Catalog Role',
            'email' => 'v2-p220-roleless@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $inactive = $this->globalActor('B2B_ADMIN', 'v2-p220-inactive@example.test');
        $inactive->forceFill(['is_active' => false])->save();

        foreach ([$roleless, $inactive] as $actor) {
            $catalogKeys = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
                ->firstWhere('key', 'catalog');

            $this->assertFalse(
                collect($catalogKeys['children'] ?? [])->contains('key', 'catalog_management'),
                'Presentation must never invent an authorized route for an ineligible actor.',
            );

            $this->actingAs($actor->fresh())
                ->get(route('admin.catalog.index', [], false))
                ->assertForbidden();
        }
    }

    public function test_retail_store_catalog_is_readable_only_inside_its_assigned_store(): void
    {
        $ownStore = $this->retailStore('P220-OWN');
        $foreignStore = $this->retailStore('P220-FOREIGN');
        $actor = User::query()->create([
            'name' => 'Retail Catalog Manager',
            'email' => 'v2-p220-retail@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $ownStore,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $group = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
            ->firstWhere('key', 'catalog');
        $this->assertIsArray($group);
        $this->assertTrue(
            collect($group['children'])->contains('key', 'b2c_products'),
            'The retail channel must discover its own catalog via the existing products destination.',
        );

        $before = DB::table('products')->count();
        $this->actingAs($actor)
            ->get(route('admin.catalog.index', ['tab' => 'products', 'store_id' => $ownStore], false))
            ->assertOk();

        $this->actingAs($actor)
            ->get(route('admin.catalog.index', ['tab' => 'products', 'store_id' => $foreignStore], false))
            ->assertForbidden();

        $this->assertSame($before, DB::table('products')->count());
    }

    private function globalActor(string $roleCode, string $email): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
