<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\PlatformCustomer;
use App\Models\Role;
use App\Models\User;
use App\Services\DashboardOperationalNotifier;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlatformCustomerOperationalNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
        config(['foodex.platform_wholesale_store_code' => 'WHOLESALE-MAIN']);
    }

    public function test_retail_registration_notifies_only_authorized_store_audience_and_is_deduplicated(): void
    {
        $storeA = $this->store('B2C', 'REG-RETAIL-A');
        $storeB = $this->store('B2C', 'REG-RETAIL-B');
        $adminA = $this->retailAdmin($storeA, 'registration-a@example.test');
        $adminB = $this->retailAdmin($storeB, 'registration-b@example.test');
        $superAdmin = $this->globalAdmin('SUPER_ADMIN', 'registration-super@example.test');

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Retail Registration Customer',
            'email' => 'new-retail-customer@example.test',
            'phone' => '+96550000001',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'locale' => 'en',
            'store_id' => $storeA,
        ])->assertCreated();

        $platform = PlatformCustomer::query()
            ->where('email', 'new-retail-customer@example.test')
            ->firstOrFail();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $adminA->id,
            'app' => 'dashboard',
            'type' => 'customer.registered',
            'target_channel' => 'b2c',
            'store_id' => $storeA,
            'status' => 'published',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $superAdmin->id,
            'app' => 'dashboard',
            'type' => 'customer.registered',
            'store_id' => $storeA,
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $adminB->id,
            'type' => 'customer.registered',
        ]);

        $notification = Notification::query()
            ->where('user_id', $adminA->id)
            ->where('type', 'customer.registered')
            ->firstOrFail();

        $this->assertSame((int) $platform->id, (int) $notification->data['platform_customer_id']);
        $this->assertSame($storeA, (int) $notification->data['store_id']);
        $this->assertSame('b2c', $notification->data['channel']);
        $this->assertSame(
            route('admin.customer-360.show', ['platformCustomer' => $platform->id], false),
            $notification->data['deep_link'],
        );

        app(DashboardOperationalNotifier::class)->platformCustomerRegistered($platform);
        app(DashboardOperationalNotifier::class)->platformCustomerRegistered($platform);

        $this->assertSame(
            1,
            Notification::query()
                ->where('user_id', $adminA->id)
                ->where('type', 'customer.registered')
                ->count(),
        );

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Duplicate Retail Registration Customer',
            'email' => 'new-retail-customer@example.test',
            'phone' => '+96550000002',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'store_id' => $storeA,
        ])->assertUnprocessable();

        $this->assertSame(
            1,
            Notification::query()
                ->where('user_id', $adminA->id)
                ->where('type', 'customer.registered')
                ->count(),
        );
    }

    public function test_wholesale_registration_notifies_b2b_audience_and_login_never_reemits_registration(): void
    {
        $wholesaleStore = $this->store('B2B', 'WHOLESALE-MAIN');
        $retailStore = $this->store('B2C', 'REG-RETAIL-ONLY');
        $b2bAdmin = $this->globalAdmin('B2B_ADMIN', 'registration-b2b@example.test');
        $retailAdmin = $this->retailAdmin($retailStore, 'registration-retail@example.test');

        $credentials = [
            'name' => 'Wholesale Registration Customer',
            'email' => 'new-wholesale-customer@example.test',
            'phone' => '+96550000003',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'locale' => 'ar',
            'store_id' => $wholesaleStore,
        ];

        $this->postJson('/api/v1/auth/register', $credentials)
            ->assertCreated()
            ->assertJsonPath('platform_customer', true);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $b2bAdmin->id,
            'app' => 'dashboard',
            'type' => 'customer.registered',
            'target_channel' => 'b2b',
            'store_id' => $wholesaleStore,
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $retailAdmin->id,
            'type' => 'customer.registered',
        ]);

        $beforeLogin = Notification::query()
            ->where('user_id', $b2bAdmin->id)
            ->where('type', 'customer.registered')
            ->count();

        $this->postJson('/api/v1/auth/login', [
            'email' => $credentials['email'],
            'password' => $credentials['password'],
        ])->assertOk();

        $this->assertSame(
            $beforeLogin,
            Notification::query()
                ->where('user_id', $b2bAdmin->id)
                ->where('type', 'customer.registered')
                ->count(),
        );
    }

    private function store(string $typeCode, string $code): int
    {
        $typeId = (int) DB::table('store_types')
            ->where('code', $typeCode)
            ->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => str_replace('-', ' ', $code),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function retailAdmin(int $storeId, string $email): User
    {
        $user = User::query()->create([
            'name' => $email,
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

    private function globalAdmin(string $roleCode, string $email): User
    {
        $user = User::query()->create([
            'name' => $email,
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(
            Role::query()->where('code', $roleCode)->firstOrFail(),
        );

        return $user;
    }
}
