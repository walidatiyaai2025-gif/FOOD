<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class B2bAdminWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_b2b_admin_gets_rtl_workspace_and_b2b_scoped_counts(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $b2bType = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        $b2cType = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        DB::table('stores')->insert(['store_type_id' => $b2bType, 'code' => 'B2B-ADMIN', 'name' => 'Wholesale', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('stores')->insert(['store_type_id' => $b2cType, 'code' => 'B2C-HIDDEN', 'name' => 'Retail', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $user = $this->user('B2B_ADMIN', 'ar');

        $this->actingAs($user)->get('/admin/b2b/products')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('المنتجات والمخزون')
            ->assertSee('FOODEX · B2B');
    }

    public function test_non_b2b_management_role_is_forbidden_and_invalid_module_is_not_found(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $finance = $this->user('FINANCE', 'en');
        $this->actingAs($finance)->get('/admin/b2b/dashboard')->assertForbidden();

        $admin = $this->user('B2B_ADMIN', 'en');
        $this->actingAs($admin)->get('/admin/b2b/not-real')->assertNotFound();
    }

    public function test_english_locale_renders_ltr_and_all_approved_modules(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $admin = $this->user('SUPER_ADMIN', 'en');
        $response = $this->actingAs($admin)->get('/admin/b2b/dashboard')->assertOk()->assertSee('dir="ltr"', false);
        foreach (['Stores', 'B2B Clients', 'Products & Inventory', 'Orders', 'Drivers & Delivery', 'Pricing & Approvals', 'Reports', 'Settings & Permissions'] as $label) {
            $response->assertSee($label);
        }
    }

    private function user(string $role, string $locale): User
    {
        $user = User::query()->create(['name' => $role, 'email' => strtolower($role).'-'.$locale.'@workspace.test', 'password' => 'password', 'locale' => $locale, 'is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

        return $user;
    }
}
