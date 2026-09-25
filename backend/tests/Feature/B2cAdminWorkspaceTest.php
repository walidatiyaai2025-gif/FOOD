<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class B2cAdminWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_b2c_admin_sees_only_assigned_store_and_rtl_workspace(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $type = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $mine = (int) DB::table('stores')->insertGetId(['store_type_id' => $type, 'code' => 'MINE', 'name' => 'Mine', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $other = (int) DB::table('stores')->insertGetId(['store_type_id' => $type, 'code' => 'OTHER', 'name' => 'Other', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $user = User::query()->create(['name' => 'Store Admin', 'email' => 'store-admin@example.test', 'password' => 'password', 'locale' => 'ar', 'is_active' => true]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        $user->roles()->attach($role);
        DB::table('user_store_roles')->insert(['user_id' => $user->id, 'store_id' => $mine, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($user)->get('/admin/b2c/products')->assertOk()->assertSee('dir="rtl"', false)->assertSee('نطاق المتاجر المصرح: 1')->assertDontSee('Other');
    }

    public function test_b2c_admin_without_assigned_store_is_forbidden_and_invalid_module_is_not_found(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $user = User::query()->create(['name' => 'Unscoped', 'email' => 'unscoped@example.test', 'password' => 'password', 'locale' => 'en', 'is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail());

        $this->actingAs($user)->get('/admin/b2c/dashboard')->assertForbidden();

        $scoped = (int) DB::table('stores')->insertGetId(['store_type_id'=>(int) DB::table('store_types')->where('code','B2C')->value('id'),'code'=>'SCOPED','name'=>'Scoped','is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        DB::table('user_store_roles')->insert(['user_id' => $user->id, 'store_id' => $scoped, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user)->get('/admin/b2c/not-real')->assertNotFound();
    }
}
