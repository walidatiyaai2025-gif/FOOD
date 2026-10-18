<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\BuiltInJobRoleCatalog;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class B2cProfileAwareJobRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_catalog_rejects_cross_profile_store_roles_and_keeps_shared_owner_role(): void
    {
        $type = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $retail = Store::query()->create([
            'store_type_id' => $type,
            'business_profile' => Store::BUSINESS_PROFILE_RETAIL_STORE,
            'code' => 'ROLE-RETAIL',
            'name' => 'Role Retail',
            'is_active' => true,
        ]);
        $restaurant = Store::query()->create([
            'store_type_id' => $type,
            'business_profile' => Store::BUSINESS_PROFILE_RESTAURANT,
            'code' => 'ROLE-RESTAURANT',
            'name' => 'Role Restaurant',
            'is_active' => true,
        ]);

        $catalog = app(BuiltInJobRoleCatalog::class);

        $this->assertTrue($catalog->isAvailable('RETAIL_OPERATIONS', (int) $retail->id));
        $this->assertFalse($catalog->isAvailable('RETAIL_OPERATIONS', (int) $restaurant->id));
        $this->assertFalse($catalog->isAvailable('RESTAURANT_DIGITAL_MANAGER', (int) $retail->id));
        $this->assertTrue($catalog->isAvailable('B2C_STORE_ADMIN', (int) $retail->id));
        $this->assertTrue($catalog->isAvailable('B2C_STORE_ADMIN', (int) $restaurant->id));
    }

    public function test_profile_invalid_role_assignment_is_rejected_server_side(): void
    {
        $type = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $retail = Store::query()->create([
            'store_type_id' => $type,
            'business_profile' => Store::BUSINESS_PROFILE_RETAIL_STORE,
            'code' => 'ASSIGN-RETAIL',
            'name' => 'Assign Retail',
            'is_active' => true,
        ]);
        $restaurant = Store::query()->create([
            'store_type_id' => $type,
            'business_profile' => Store::BUSINESS_PROFILE_RESTAURANT,
            'code' => 'ASSIGN-RESTAURANT',
            'name' => 'Assign Restaurant',
            'is_active' => true,
        ]);

        $admin = User::query()->create([
            'name' => 'Role Admin',
            'email' => 'role-admin@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());
        $target = User::query()->create([
            'name' => 'Role Target',
            'email' => 'role-target@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);

        $retailRole = Role::query()->where('code', 'RETAIL_OPERATIONS')->firstOrFail();
        $restaurantRole = Role::query()->where('code', 'RESTAURANT_DIGITAL_MANAGER')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.retail-stores.roles.assign', $restaurant), [
            'user_id' => $target->id,
            'role_id' => $retailRole->id,
        ])->assertStatus(422);

        $this->actingAs($admin)->post(route('admin.retail-stores.roles.assign', $retail), [
            'user_id' => $target->id,
            'role_id' => $restaurantRole->id,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('user_store_roles', ['user_id' => $target->id, 'store_id' => $restaurant->id, 'role_id' => $retailRole->id]);
        $this->assertDatabaseMissing('user_store_roles', ['user_id' => $target->id, 'store_id' => $retail->id, 'role_id' => $restaurantRole->id]);
    }
}
