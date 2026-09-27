<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RetailStoreAdminSurfaceAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $store;

    private int $foreignStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);

        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $this->store = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => 'AUDIT-RETAIL',
            'name' => 'Audit Retail Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->foreignStore = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => 'AUDIT-FOREIGN',
            'name' => 'Foreign Retail Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->admin = User::query()->create([
            'name' => 'Retail Audit Admin',
            'email' => 'retail-audit@example.test',
            'password' => 'password',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        DB::table('user_store_roles')->insert([
            'user_id' => $this->admin->id,
            'store_id' => $this->store,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_every_retail_management_surface_opens_inside_assigned_store_scope(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin')
            ->assertRedirect(route('admin.b2c.dashboard'));

        foreach ([
            '/admin/b2c/dashboard',
            '/admin/b2c/products',
            '/admin/b2c/inventory',
            '/admin/b2c/orders',
            '/admin/b2c/customers',
            '/admin/b2c/promotions',
            '/admin/b2c/drivers',
            '/admin/b2c/storefront',
            '/admin/b2c/content',
            '/admin/b2c/reports',
            '/admin/b2c/settings',
            '/admin/catalog?tab=products&store_id='.$this->store,
            '/admin/catalog?tab=categories&store_id='.$this->store,
            '/admin/lookups?scope=store&store_id='.$this->store,
            '/admin/business?tab=inventory&store_id='.$this->store,
            '/admin/business?tab=customers&store_id='.$this->store,
            '/admin/business?tab=promotions&store_id='.$this->store,
            '/admin/business?tab=content&store_id='.$this->store,
            '/admin/business?tab=drivers&store_id='.$this->store,
            '/admin/notification-campaigns',
            '/admin/reports?report=orders&store_id='.$this->store.'&channel=b2c',
        ] as $uri) {
            $this->actingAs($this->admin)->get($uri)->assertOk();
        }
    }

    public function test_platform_and_wholesale_surfaces_are_not_available_to_retail_admin(): void
    {
        foreach ([
            '/admin/b2b/dashboard',
            '/admin/retail-stores',
            '/admin/security',
            '/admin/settings/mobile',
            '/admin/settings/app-versions',
            '/admin/settings/system-update',
            '/admin/settings/translations',
        ] as $uri) {
            $this->actingAs($this->admin)->get($uri)->assertForbidden();
        }

        $this->actingAs($this->admin)
            ->get('/admin/catalog?tab=stores&store_id='.$this->store)
            ->assertForbidden();
    }

    public function test_retail_store_context_cannot_be_switched_to_a_foreign_store(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/b2c/products?store_id='.$this->foreignStore)
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->get('/admin/catalog?tab=products&store_id='.$this->foreignStore)
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->get('/admin/business?tab=inventory&store_id='.$this->foreignStore)
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->get('/admin/reports?report=orders&store_id='.$this->foreignStore.'&channel=b2c')
            ->assertForbidden();
    }

    public function test_store_settings_are_editable_but_secret_and_foreign_settings_are_protected(): void
    {
        $this->actingAs($this->admin)->put('/admin/b2c/settings', [
            'store_id' => $this->store,
            'key' => 'storefront.layout',
            'value' => 'compact',
        ])->assertRedirect();

        $this->assertDatabaseHas('settings', [
            'store_id' => $this->store,
            'key' => 'storefront.layout',
            'is_secret' => false,
        ]);

        DB::table('settings')->insert([
            'store_id' => $this->store,
            'key' => 'payments.secret',
            'value' => json_encode('keep-hidden'),
            'is_secret' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)->put('/admin/b2c/settings', [
            'store_id' => $this->store,
            'key' => 'payments.secret',
            'value' => 'changed',
        ])->assertForbidden();

        $this->actingAs($this->admin)->put('/admin/b2c/settings', [
            'store_id' => $this->foreignStore,
            'key' => 'storefront.layout',
            'value' => 'foreign',
        ])->assertNotFound();
    }

    public function test_operations_center_preserves_selected_store_and_never_offers_b2b_driver_type(): void
    {
        $secondAssigned = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'AUDIT-SECOND',
            'name' => 'Second Assigned Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $roleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id');
        DB::table('user_store_roles')->insert([
            'user_id' => $this->admin->id,
            'store_id' => $secondAssigned,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get('/admin/business?tab=drivers&store_id='.$this->store)
            ->assertOk()
            ->assertSee('Audit Retail Store')
            ->assertDontSee('Second Assigned Store')
            ->assertDontSee('value="b2b"', false);
    }
}
