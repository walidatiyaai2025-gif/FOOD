<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\CatalogOwnership;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RetailAdminCatalogAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_retail_admin_can_open_and_manage_only_its_scoped_catalog(): void
    {
        $storeA = $this->store('B2C', 'SHOP-A');
        $storeB = $this->store('B2C', 'SHOP-B');
        $manager = $this->storeManager('retail-a@example.test', $storeA);
        $unit = $this->globalUnit();

        $response = $this->actingAs($manager)->get(route('admin.catalog.index', [
            'tab' => 'products',
            'store_id' => $storeA,
        ]));

        $response
            ->assertOk()
            ->assertSee('Catalog Management')
            ->assertDontSee('Manage Stores')
            ->assertDontSee('tab=stores', false);

        $this->actingAs($manager)->post(route('admin.catalog.products.store'), [
            'store_id' => $storeA,
            'sku' => 'A-001',
            'name' => 'Store A Product',
            'unit_id' => $unit->id,
            'price' => 1.250,
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $catalogA = app(CatalogOwnership::class)->defaultCatalogForStore($storeA);
        $this->assertDatabaseHas('products', [
            'catalog_id' => $catalogA->id,
            'sku' => 'A-001',
            'name' => 'Store A Product',
        ]);

        $this->actingAs($manager)
            ->get(route('admin.catalog.index', [
                'tab' => 'products',
                'store_id' => $storeA,
            ]))
            ->assertOk()
            ->assertSee('Store A Product')
            ->assertSee('name="description"', false)
            ->assertSee('Catalog & Categories Management');

        $this->actingAs($manager)
            ->get(route('admin.catalog.index', ['tab' => 'products', 'store_id' => $storeB]))
            ->assertForbidden();
    }

    public function test_retail_workspace_does_not_offer_store_management(): void
    {
        $store = $this->store('B2C', 'SHOP-LOCAL');
        $manager = $this->storeManager('retail-local@example.test', $store);

        $this->actingAs($manager)
            ->get(route('admin.b2c.module', ['module' => 'products', 'store_id' => $store]))
            ->assertOk()
            ->assertSee('Add / Edit Products')
            ->assertSee('Manage Categories')
            ->assertDontSee('Manage Stores');
    }

    public function test_b2b_admin_cannot_enter_or_mutate_b2c_catalog(): void
    {
        $retailStore = $this->store('B2C', 'RETAIL');
        $b2bStore = $this->store('B2B', 'WHOLESALE');
        $b2bAdmin = $this->globalUser('B2B_ADMIN', 'b2b-admin@example.test');
        $unit = $this->globalUnit();

        $retailCatalog = app(CatalogOwnership::class)->defaultCatalogForStore($retailStore);
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $retailCatalog->id,
            'sku' => 'RETAIL-001',
            'name' => 'Retail Product',
            'description' => null,
            'category_id' => null,
            'brand_id' => null,
            'unit_id' => $unit->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(CatalogOwnership::class)->defaultCatalogForStore($b2bStore);

        $this->actingAs($b2bAdmin)
            ->get(route('admin.catalog.index', ['tab' => 'products', 'store_id' => $retailStore]))
            ->assertNotFound();

        $this->actingAs($b2bAdmin)
            ->patch(route('admin.catalog.products.update', $productId), [
                'sku' => 'RETAIL-001',
                'name' => 'Forbidden Rename',
                'unit_id' => $unit->id,
                'is_active' => 1,
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'name' => 'Retail Product',
        ]);
    }

    public function test_store_management_is_platform_owner_only(): void
    {
        $retailStore = $this->store('B2C', 'LOCKED');
        $retailAdmin = $this->storeManager('retail-lock@example.test', $retailStore);
        $b2bAdmin = $this->globalUser('B2B_ADMIN', 'b2b-lock@example.test');
        $owner = $this->globalUser('SUPER_ADMIN', 'owner@example.test');

        $this->actingAs($retailAdmin)
            ->get(route('admin.catalog.index', ['tab' => 'stores', 'store_id' => $retailStore]))
            ->assertForbidden();

        $this->actingAs($b2bAdmin)
            ->get(route('admin.catalog.index', ['tab' => 'stores']))
            ->assertForbidden();

        $this->actingAs($retailAdmin)
            ->post(route('admin.retail-stores.store'), [
                'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
                'code' => 'NOPE-RETAIL',
                'name' => 'Nope Retail',
            ])
            ->assertForbidden();

        $this->actingAs($b2bAdmin)
            ->post(route('admin.retail-stores.store'), [
                'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
                'code' => 'NOPE-B2B',
                'name' => 'Nope B2B',
            ])
            ->assertForbidden();

        $this->actingAs($owner)
            ->get(route('admin.catalog.index', ['tab' => 'stores']))
            ->assertRedirect(route('admin.retail-stores.index'));
    }

    private function storeManager(string $email, int $storeId): User
    {
        $user = $this->user($email);
        $roleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id');

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function globalUser(string $role, string $email): User
    {
        $user = $this->user($email);
        $user->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

        return $user;
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'name' => $email,
            'email' => $email,
            'password' => 'password123',
            'locale' => 'en',
            'is_active' => true,
        ]);
    }

    private function store(string $type, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $type)->value('id'),
            'code' => 'AUTH-'.$code,
            'name' => 'Auth '.$code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function globalUnit(): Unit
    {
        return Unit::query()->firstOrCreate(
            ['scope_key' => 'global', 'code' => 'EA'],
            [
                'store_id' => null,
                'scope' => 'global',
                'name' => 'Each',
                'name_ar' => 'قطعة',
                'name_en' => 'Each',
                'decimal_places' => 0,
                'is_active' => true,
            ],
        );
    }
}
