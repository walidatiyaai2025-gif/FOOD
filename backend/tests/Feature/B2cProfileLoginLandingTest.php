<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class B2cProfileLoginLandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_single_scope_retail_and_restaurant_users_land_in_their_profile_workspace(): void
    {
        $retail = $this->store('LOGIN-RETAIL', StoreBusinessProfile::RETAIL_STORE);
        $restaurant = $this->store('LOGIN-RESTAURANT', StoreBusinessProfile::RESTAURANT);

        $retailUser = $this->user('profile-retail@example.test');
        $restaurantUser = $this->user('profile-restaurant@example.test');
        $this->assign($retailUser, $retail, 'B2C_STORE_ADMIN');
        $this->assign($restaurantUser, $restaurant, 'RESTAURANT_DIGITAL_MANAGER');

        $this->post('/admin/b2c/login', [
            'email' => $retailUser->email,
            'password' => 'correct-password',
            'locale' => 'en',
        ])->assertRedirect(route('admin.b2c.dashboard'));

        $this->post('/admin/logout')->assertRedirect(route('admin.b2c.login'));

        $this->post('/admin/b2c/login', [
            'email' => $restaurantUser->email,
            'password' => 'correct-password',
            'locale' => 'en',
        ])->assertRedirect(route('admin.smart-ordering.index', ['store_id' => $restaurant]));
    }

    public function test_profile_selection_preserves_role_aware_workspace_inside_the_selected_store(): void
    {
        $retail = $this->store('LOGIN-ROLE-RETAIL', StoreBusinessProfile::RETAIL_STORE);
        $restaurant = $this->store('LOGIN-ROLE-RESTAURANT', StoreBusinessProfile::RESTAURANT);
        $user = $this->user('profile-role-aware@example.test');

        $this->assign($user, $retail, 'RETAIL_INVENTORY');
        $this->assign($user, $restaurant, 'B2C_STORE_ADMIN');

        $this->post('/admin/b2c/login', [
            'email' => $user->email,
            'password' => 'correct-password',
            'locale' => 'en',
        ])
            ->assertRedirect(route('admin.b2c.module', [
                'module' => 'inventory',
                'store_id' => $retail,
            ]))
            ->assertSessionHas('b2c_last_store_id', $retail);

        $this->post('/admin/logout')->assertRedirect(route('admin.b2c.login'));

        $user = User::query()->findOrFail($user->id);
        $this->withSession(['b2c_last_store_id' => $restaurant])
            ->post('/admin/b2c/login', [
                'email' => $user->email,
                'password' => 'correct-password',
                'locale' => 'en',
            ])
            ->assertRedirect(route('admin.smart-ordering.index', ['store_id' => $restaurant]));
    }

    public function test_stale_last_scope_is_ignored_and_primary_scope_is_deterministic(): void
    {
        $retail = $this->store('LOGIN-STALE-RETAIL', StoreBusinessProfile::RETAIL_STORE);
        $restaurant = $this->store('LOGIN-STALE-RESTAURANT', StoreBusinessProfile::RESTAURANT);
        $user = $this->user('profile-stale@example.test');

        $this->assign($user, $retail, 'B2C_STORE_ADMIN');
        $this->assign($user, $restaurant, 'B2C_STORE_ADMIN');

        $this->withSession(['b2c_last_store_id' => 999999])
            ->post('/admin/b2c/login', [
                'email' => $user->email,
                'password' => 'correct-password',
                'locale' => 'en',
            ])
            ->assertRedirect(route('admin.b2c.dashboard', ['store_id' => $retail]))
            ->assertSessionHas('b2c_last_store_id', $retail);
    }

    public function test_authorized_profile_intended_url_wins_and_cross_profile_intended_url_fails_closed(): void
    {
        $retail = $this->store('LOGIN-INTENDED-RETAIL', StoreBusinessProfile::RETAIL_STORE);
        $restaurant = $this->store('LOGIN-INTENDED-RESTAURANT', StoreBusinessProfile::RESTAURANT);

        $validUser = $this->user('profile-intended-valid@example.test');
        $this->assign($validUser, $retail, 'B2C_STORE_ADMIN');
        $this->assign($validUser, $restaurant, 'B2C_STORE_ADMIN');
        $restaurantIntended = route('admin.smart-ordering.index', ['store_id' => $restaurant]);

        $this->withSession(['url.intended' => $restaurantIntended])
            ->post('/admin/b2c/login', [
                'email' => $validUser->email,
                'password' => 'correct-password',
                'locale' => 'en',
            ])
            ->assertRedirect($restaurantIntended);

        $this->post('/admin/logout')->assertRedirect(route('admin.b2c.login'));

        $wrongRetailUser = $this->user('profile-cross-retail@example.test');
        $this->assign($wrongRetailUser, $retail, 'B2C_STORE_ADMIN');
        $this->assign($wrongRetailUser, $restaurant, 'B2C_STORE_ADMIN');

        $this->withSession([
            'url.intended' => route('admin.b2c.dashboard', ['store_id' => $restaurant]),
        ])->post('/admin/b2c/login', [
            'email' => $wrongRetailUser->email,
            'password' => 'correct-password',
            'locale' => 'en',
        ])->assertRedirect(route('admin.b2c.dashboard', ['store_id' => $retail]));

        $this->post('/admin/logout')->assertRedirect(route('admin.b2c.login'));

        $wrongRestaurantUser = $this->user('profile-cross-restaurant@example.test');
        $this->assign($wrongRestaurantUser, $retail, 'B2C_STORE_ADMIN');
        $this->assign($wrongRestaurantUser, $restaurant, 'B2C_STORE_ADMIN');

        $this->withSession([
            'url.intended' => route('admin.smart-ordering.index', ['store_id' => $retail]),
        ])->post('/admin/b2c/login', [
            'email' => $wrongRestaurantUser->email,
            'password' => 'correct-password',
            'locale' => 'en',
        ])->assertRedirect(route('admin.b2c.dashboard', ['store_id' => $retail]));
    }

    public function test_mfa_and_whatsapp_completion_share_the_profile_aware_default_target(): void
    {
        config()->set('security.mfa.enforce_privileged', true);

        $restaurant = $this->store('LOGIN-MFA-RESTAURANT', StoreBusinessProfile::RESTAURANT);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        $permissionId = (int) DB::table('permissions')->where('code', 'platform.manage')->value('id');
        DB::table('permission_role')->insertOrIgnore([
            'permission_id' => $permissionId,
            'role_id' => $role->id,
        ]);

        $user = $this->user('profile-mfa@example.test');
        $this->assign($user, $restaurant, 'B2C_STORE_ADMIN');

        $this->post('/admin/b2c/login', [
            'email' => $user->email,
            'password' => 'correct-password',
            'locale' => 'en',
        ])
            ->assertRedirect(route('admin.mfa.enroll'))
            ->assertSessionHas(
                'mfa_intended',
                route('admin.smart-ordering.index', ['store_id' => $restaurant]),
            );

        $source = file_get_contents(app_path('Http/Controllers/Admin/AdminLoginController.php'));
        $this->assertStringContainsString(
            "return \$this->completeLogin(\$request, \$user, \$channel, \$locale, 'whatsapp_otp');",
            $source,
        );
        $this->assertStringContainsString(
            '\$defaultTarget = \$this->defaultWorkspaceUrl(\$user, \$target, \$request);',
            $source,
        );
    }

    private function store(string $code, StoreBusinessProfile $profile): int
    {
        $type = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $type,
            'business_profile' => $profile->value,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'name' => 'Profile Login User',
            'email' => $email,
            'password' => 'correct-password',
            'locale' => 'en',
            'is_active' => true,
        ]);
    }

    private function assign(User $user, int $storeId, string $roleCode): void
    {
        $roleId = (int) Role::query()->where('code', $roleCode)->value('id');
        $this->assertGreaterThan(0, $roleId, "Missing role {$roleCode}");

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
