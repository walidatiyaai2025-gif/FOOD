<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DriverPreviewReadBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_preview_reads_only_selected_driver_assignments_through_production_controller(): void
    {
        $storeId = $this->retailStore('DRV-PREVIEW-A');
        $admin = $this->storeAdmin($storeId, 'driver-preview-admin@example.test');
        [$targetUser, $targetDriver] = $this->driver($storeId, 'driver-preview-target@example.test');
        [, $otherDriver] = $this->driver($storeId, 'driver-preview-other@example.test');

        $targetOrder = $this->order($storeId, 'DRV-PREVIEW-ORDER-A');
        $failedOrder = $this->order($storeId, 'DRV-PREVIEW-ORDER-FAILED');
        $otherOrder = $this->order($storeId, 'DRV-PREVIEW-ORDER-B');

        $targetAssignment = $this->assignment($targetDriver->id, $targetOrder->id, $storeId);
        $failedAssignment = $this->assignment($targetDriver->id, $failedOrder->id, $storeId);
        DB::table('driver_assignments')
            ->where('id', $failedAssignment)
            ->update([
                'status' => 'failed',
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
        $this->assignment($otherDriver->id, $otherOrder->id, $storeId);

        $token = $this->previewToken($admin, $targetUser, $storeId);
        $this->app['auth']->forgetGuards();

        $this->withHeader('X-Foodex-Preview-Token', $token)
            ->getJson('/api/v1/app-preview/driver/assignments?scope=active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $targetAssignment)
            ->assertJsonPath('data.0.order.number', $targetOrder->order_number);

        $this->withHeader('X-Foodex-Preview-Token', $token)
            ->getJson('/api/v1/app-preview/driver/assignments/'.$targetAssignment)
            ->assertOk()
            ->assertJsonPath('data.id', $targetAssignment);

        $this->withHeader('X-Foodex-Preview-Token', $token)
            ->getJson('/api/v1/app-preview/driver/assignments?scope=failed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $failedAssignment)
            ->assertJsonPath('data.0.status', 'failed')
            ->assertJsonPath('meta.scope', 'failed');
    }

    public function test_preview_failed_delivery_reasons_reuse_authoritative_lookup_contract(): void
    {
        $storeId = $this->retailStore('DRV-PREVIEW-LOOKUPS');
        $admin = $this->storeAdmin($storeId, 'driver-preview-lookups-admin@example.test');
        [$driverUser] = $this->driver($storeId, 'driver-preview-lookups@example.test');

        $expected = collect(
            $this->getJson('/api/v1/lookups/failed-delivery-reasons')
                ->assertOk()
                ->json('data'),
        )->map(fn (array $row): array => [
            'code' => $row['code'],
            'label_ar' => $row['label_ar'],
            'label_en' => $row['label_en'],
        ])->values()->all();

        $token = $this->previewToken($admin, $driverUser, $storeId);
        $this->app['auth']->forgetGuards();

        $response = $this->withHeader('X-Foodex-Preview-Token', $token)
            ->getJson('/api/v1/app-preview/driver/lookups/failed-delivery-reasons')
            ->assertOk();

        $actual = collect($response->json('data'))
            ->map(fn (array $row): array => [
                'code' => $row['code'],
                'label_ar' => $row['label_ar'],
                'label_en' => $row['label_en'],
            ])->values()->all();

        $this->assertSame($expected, $actual);
    }

    public function test_preview_credential_is_header_only_and_cannot_be_normal_bearer_or_mutate(): void
    {
        $storeId = $this->retailStore('DRV-PREVIEW-SAFE');
        $admin = $this->storeAdmin($storeId, 'driver-preview-safe-admin@example.test');
        [$driverUser] = $this->driver($storeId, 'driver-preview-safe@example.test');
        $token = $this->previewToken($admin, $driverUser, $storeId);
        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->getJson('/api/v1/driver/assignments')
            ->assertUnauthorized();

        $this->withToken('normal-bearer-is-not-allowed')
            ->withHeader('X-Foodex-Preview-Token', $token)
            ->getJson('/api/v1/app-preview/driver/assignments')
            ->assertUnauthorized();

        $this->postJson('/api/v1/app-preview/driver/assignments/1/status', [
            'status' => 'accepted',
        ])->assertNotFound();
    }

    public function test_driver_preview_revalidates_revocation_target_type_and_driver_scope(): void
    {
        $storeId = $this->retailStore('DRV-PREVIEW-RECHECK');
        $admin = $this->storeAdmin($storeId, 'driver-preview-recheck-admin@example.test');
        [$driverUser, $driver] = $this->driver($storeId, 'driver-preview-recheck@example.test');
        $token = $this->previewToken($admin, $driverUser, $storeId);

        DB::table('app_preview_sessions')->update(['revoked_at' => now()]);
        $this->app['auth']->forgetGuards();
        $this->withHeader('X-Foodex-Preview-Token', $token)
            ->getJson('/api/v1/app-preview/driver/assignments')
            ->assertUnauthorized();

        DB::table('app_preview_sessions')->delete();
        $token = $this->previewToken($admin, $driverUser, $storeId);
        $driver->update(['store_id' => $this->retailStore('DRV-PREVIEW-OTHER')]);
        $this->app['auth']->forgetGuards();

        $this->withHeader('X-Foodex-Preview-Token', $token)
            ->getJson('/api/v1/app-preview/driver/assignments')
            ->assertNotFound();
    }

    private function previewToken(User $admin, User $driverUser, int $storeId): string
    {
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/admin/app-preview/sessions', [
            'target_user_id' => $driverUser->id,
            'target_type' => 'driver',
            'channel' => 'b2c',
            'store_id' => $storeId,
        ])->assertCreated();

        return (string) $response->json('preview_token');
    }

    /** @return array{0:User,1:Driver} */
    private function driver(int $storeId, string $email): array
    {
        $user = $this->roleUser('B2C_DRIVER', $email);
        $driver = Driver::query()->create([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);

        return [$user, $driver];
    }

    private function assignment(int $driverId, int $orderId, int $storeId): int
    {
        return (int) DB::table('driver_assignments')->insertGetId([
            'driver_id' => $driverId,
            'order_id' => $orderId,
            'store_id' => $storeId,
            'assignment_type' => 'b2c',
            'status' => 'assigned',
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function order(int $storeId, string $number): Order
    {
        $customerUser = User::query()->create([
            'name' => 'Preview Customer',
            'email' => strtolower($number).'@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $customer = Customer::query()->create([
            'user_id' => $customerUser->id,
            'type' => 'b2c',
            'name' => 'Preview Customer',
            'email' => $customerUser->email,
        ]);

        return Order::query()->create([
            'store_id' => $storeId,
            'customer_id' => $customer->id,
            'order_number' => $number,
            'channel' => 'b2c',
            'status' => 'ready',
            'currency' => 'KWD',
            'subtotal' => 1,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 1,
        ]);
    }

    private function storeAdmin(int $storeId, string $email): User
    {
        $user = $this->roleUser('B2C_STORE_ADMIN', $email);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function roleUser(string $roleCode, string $email): User
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
