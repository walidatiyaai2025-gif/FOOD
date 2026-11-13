<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Role;
use App\Models\User;
use App\Services\VanRegistryService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class V2CrossAppTokenScopeMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_customer_registration_token_remains_customer_only_after_legitimate_driver_and_van_assignment(): void
    {
        $registered = $this->postJson('/api/v1/auth/register', [
            'name' => 'V2 Multi App Customer',
            'email' => 'v2-mob-all01-register@example.test',
            'phone' => '+96555509140',
            'password' => 'correct-password',
            'password_confirmation' => 'correct-password',
            'locale' => 'en',
        ])->assertCreated()
            ->assertJsonPath('platform_customer', true)
            ->assertJsonPath('token_type', 'Bearer');

        $customerToken = (string) $registered->json('token');
        $this->assertSame(['app:customer'], PersonalAccessToken::findToken($customerToken)?->abilities);

        $actor = User::query()->findOrFail((int) $registered->json('user.id'));
        $this->grantDualAppRuntime($actor);

        // Both operational roles and a valid Van assignment exist. A previously
        // minted Customer token must still not become an execution credential.
        $this->withToken($customerToken)
            ->getJson('/api/v1/driver/wallet')
            ->assertForbidden();

        $this->app['auth']->forgetGuards();

        $this->withToken($customerToken)
            ->getJson('/api/v1/van/no-order-reasons')
            ->assertForbidden();

        $this->app['auth']->forgetGuards();

        $this->withToken($customerToken)
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('email', $actor->email);

        // A credential login for the explicitly selected app still works:
        // the token boundary must not revoke legitimate multi-app identity.
        $driverLogin = $this->postJson('/api/v1/auth/login', [
            'email' => $actor->email,
            'password' => 'correct-password',
            'app' => 'driver',
        ])->assertOk();
        $this->assertSame(
            ['app:driver'],
            PersonalAccessToken::findToken((string) $driverLogin->json('token'))?->abilities,
        );

        $vanLogin = $this->postJson('/api/v1/auth/login', [
            'email' => $actor->email,
            'password' => 'correct-password',
            'app' => 'van',
        ])->assertOk();
        $this->assertSame(
            ['app:van'],
            PersonalAccessToken::findToken((string) $vanLogin->json('token'))?->abilities,
        );
    }

    public function test_trial_customer_and_driver_credentials_are_app_scoped_even_for_dual_role_actor(): void
    {
        config()->set('foodex.mobile_trial_username_login', true);

        $actor = User::factory()->create([
            'name' => 'V2 Trial Dual Operator',
            'username' => 'v2.trial.multi.app',
            'email' => 'v2-mob-all01-trial@example.test',
            'password' => Hash::make('correct-password'),
            'is_active' => true,
            'locale' => 'ar',
        ]);
        $this->grantDualAppRuntime($actor);

        DB::table('b2c_customers')->insert([
            'user_id' => $actor->getKey(),
            'store_id' => $this->retailStoreId,
            'name' => $actor->name,
            'email' => $actor->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customerTrial = $this->postJson('/api/v1/auth/mobile-trial', [
            'username' => 'v2.trial.multi.app',
            'app' => 'customer',
        ])->assertOk()->assertJsonPath('trial_username_login', true);

        $customerToken = (string) $customerTrial->json('token');
        $this->assertSame(['app:customer'], PersonalAccessToken::findToken($customerToken)?->abilities);
        $this->withToken($customerToken)
            ->getJson('/api/v1/driver/wallet')
            ->assertForbidden();

        $this->app['auth']->forgetGuards();

        $driverTrial = $this->postJson('/api/v1/auth/mobile-trial', [
            'username' => 'v2.trial.multi.app',
            'app' => 'driver',
        ])->assertOk()->assertJsonPath('trial_username_login', true);

        $driverToken = (string) $driverTrial->json('token');
        $this->assertSame(['app:driver'], PersonalAccessToken::findToken($driverToken)?->abilities);
        $this->withToken($driverToken)
            ->getJson('/api/v1/van/no-order-reasons')
            ->assertForbidden();

        $this->app['auth']->forgetGuards();

        $this->withToken($driverToken)
            ->getJson('/api/v1/driver/wallet')
            ->assertOk();

        $this->assertSame(0, DB::table('remittances')->count());
        $this->assertSame(0, DB::table('orders')->count());
    }

    private int $retailStoreId;

    private function grantDualAppRuntime(User $user): void
    {
        $this->retailStoreId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'V2-MOB-ALL01-'.(int) $user->getKey(),
            'name' => 'Cross app token Retail Store',
            'business_profile' => 'RETAIL_STORE',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user->roles()->attach([
            Role::query()->where('code', 'B2C_DRIVER')->firstOrFail()->id,
            Role::query()->where('code', 'VAN_OPERATOR')->firstOrFail()->id,
        ]);

        $driver = Driver::query()->create([
            'user_id' => $user->getKey(),
            'store_id' => $this->retailStoreId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);

        $registry = app(VanRegistryService::class);
        $van = $registry->createVan(['code' => 'V2-MOB-ALL01-VAN-'.(int) $user->getKey()]);
        $registry->assign($user, $van, [
            'driver_id' => $driver->getKey(),
            'assignment_type' => 'primary',
            'effective_from' => now()->subMinute(),
        ]);
    }
}
