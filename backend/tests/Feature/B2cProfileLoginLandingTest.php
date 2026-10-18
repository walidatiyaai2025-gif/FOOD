<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\B2cWorkspaceLandingResolver;
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

    public function test_single_store_login_landing_is_resolved_by_authoritative_profile(): void
    {
        $retail = $this->store('LOGIN-RETAIL', Store::BUSINESS_PROFILE_RETAIL_STORE);
        $restaurant = $this->store('LOGIN-RESTAURANT', Store::BUSINESS_PROFILE_RESTAURANT);
        $this->enableRestaurantSaasForTestStore($restaurant);

        $retailUser = $this->userForStore('login-retail@example.test', $retail);
        $restaurantUser = $this->userForStore('login-restaurant@example.test', $restaurant);
        $resolver = app(B2cWorkspaceLandingResolver::class);

        $this->assertSame(
            route('admin.b2c.dashboard', ['store_id' => $retail]),
            $resolver->urlFor($retailUser),
        );
        $this->assertSame(
            route('admin.smart-ordering.index', ['store_id' => $restaurant]),
            $resolver->urlFor($restaurantUser),
        );

        $this->actingAs($retailUser)
            ->get(route('admin.index'))
            ->assertRedirect(route('admin.b2c.dashboard', ['store_id' => $retail]));

        $this->actingAs($restaurantUser)
            ->get(route('admin.index'))
            ->assertRedirect(route('admin.smart-ordering.index', ['store_id' => $restaurant]));
    }

    public function test_multi_store_user_is_not_silently_routed_to_an_arbitrary_store(): void
    {
        $first = $this->store('LOGIN-MULTI-A', Store::BUSINESS_PROFILE_RETAIL_STORE);
        $second = $this->store('LOGIN-MULTI-B', Store::BUSINESS_PROFILE_RESTAURANT);
        $this->enableRestaurantSaasForTestStore($second);
        $user = $this->userForStore('login-multi@example.test', $first);
        $roleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id');
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $second,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNull(app(B2cWorkspaceLandingResolver::class)->urlFor($user));
        $this->actingAs($user)->get(route('admin.index'))->assertOk();
    }

    public function test_all_management_auth_entrypoints_use_the_profile_resolver(): void
    {
        $login = file_get_contents(app_path('Http/Controllers/Admin/AdminLoginController.php'));
        $sso = file_get_contents(app_path('Http/Controllers/Admin/AdminSsoController.php'));
        $shell = file_get_contents(app_path('Http/Controllers/Admin/AdminShellController.php'));

        foreach ([$login, $sso, $shell] as $source) {
            $this->assertStringContainsString('B2cWorkspaceLandingResolver', $source);
            $this->assertStringContainsString('workspaceLanding', $source);
        }

        $this->assertStringContainsString("session()->put('mfa_intended', \$defaultTarget)", $sso);
        $this->assertStringContainsString("session()->put('mfa_intended', \$intended ?? \$defaultTarget)", $login);
    }

    private function store(string $code, string $profile): int
    {
        $type = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        return (int) Store::query()->create([
            'store_type_id' => $type,
            'business_profile' => $profile,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ])->getKey();
    }

    private function userForStore(string $email, int $storeId): User
    {
        $user = User::query()->create([
            'name' => 'Landing User',
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }
}
