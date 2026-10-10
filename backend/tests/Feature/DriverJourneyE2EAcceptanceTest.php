<?php

namespace Tests\Feature;

use App\Jobs\DispatchPushNotification;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DriverJourneyE2EAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
        Queue::fake();
        Storage::fake('public');
    }

    public function test_dashboard_assignment_to_driver_proof_is_authoritative_notified_and_idempotent(): void
    {
        [$storeId, $order, $customerUser] = $this->order('b2c', 'HAPPY');
        $order->forceFill(['status' => 'ready'])->save();

        $admin = $this->admin('B2C_STORE_ADMIN', $storeId, 'journey-admin@example.test');
        [$driverUser, $driver] = $this->driver('b2c', $storeId, 'journey-driver@example.test');

        Sanctum::actingAs($admin);
        $assignmentId = $this->postJson('/api/v1/admin/deliveries/assign', [
            'driver_id' => $driver->id,
            'order_id' => $order->id,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'assigned')
            ->json('data.id');

        $this->assertNotification($driverUser->id, 'driver', 'delivery.assigned');
        $this->assertNotification($customerUser->id, 'customer', 'delivery.assigned');
        $this->assertNotification($admin->id, 'dashboard', 'delivery.assigned');

        Sanctum::actingAs($driverUser, ['app:driver']);
        $this->getJson("/api/v1/driver/assignments/{$assignmentId}")
            ->assertOk()
            ->assertJsonPath('data.id', $assignmentId)
            ->assertJsonPath('data.status', 'assigned')
            ->assertJsonPath('data.order.status', 'ready');

        $this->transition($assignmentId, 'accepted', 'journey-accept-1')
            ->assertJsonPath('data.status', 'accepted');
        $acceptedProofs = DB::table('delivery_proofs')
            ->where('driver_assignment_id', $assignmentId)
            ->count();
        $acceptedNotifications = DB::table('notifications')->count();

        $this->transition($assignmentId, 'accepted', 'journey-accept-1')
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');
        $this->assertSame(
            $acceptedProofs,
            DB::table('delivery_proofs')->where('driver_assignment_id', $assignmentId)->count(),
        );
        $this->assertSame($acceptedNotifications, DB::table('notifications')->count());

        $this->transition($assignmentId, 'picked_up', 'journey-pickup-1')
            ->assertJsonPath('data.status', 'picked_up');

        $this->postJson(
            "/api/v1/driver/assignments/{$assignmentId}/status",
            [
                'status' => 'out_for_delivery',
                'note' => 'Leaving store now',
            ],
            ['Idempotency-Key' => 'journey-start-1'],
        )->assertOk()
            ->assertJsonPath('data.status', 'out_for_delivery')
            ->assertJsonPath('data.order.status', 'out_for_delivery');

        $this->post(
            "/api/v1/driver/assignments/{$assignmentId}/status",
            [
                'status' => 'delivered',
                'note' => 'Delivered to customer',
                'proof_image' => UploadedFile::fake()->image('journey-proof.jpg', 640, 480),
            ],
            [
                'Accept' => 'application/json',
                'Idempotency-Key' => 'journey-delivered-1',
            ],
        )->assertOk()
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.order.status', 'delivered')
            ->assertJsonCount(0, 'data.available_statuses');

        $proof = DB::table('delivery_proofs')
            ->where('driver_assignment_id', $assignmentId)
            ->where('idempotency_key', 'journey-delivered-1')
            ->where('proof_type', 'delivery_image')
            ->first();

        $this->assertNotNull($proof);
        $this->assertNotNull($proof->file_path);
        Storage::disk('public')->assertExists($proof->file_path);

        $evidence = $this->actingAs($admin)
            ->getJson(route('admin.driver-live-tracking.evidence', $assignmentId))
            ->assertOk()
            ->assertJsonPath('data.id', $assignmentId)
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonCount(4, 'data.timeline')
            ->assertJsonPath('data.timeline.3.to_status', 'delivered')
            ->assertJsonPath('data.timeline.3.proof.available', true);

        $evidence->assertJsonMissingPath('data.timeline.3.file_path');
        $this->assertStringNotContainsString(
            (string) $proof->file_path,
            $evidence->getContent(),
        );

        $proofUrl = $evidence->json('data.timeline.3.proof.url');
        $this->assertIsString($proofUrl);
        $proofResponse = $this->actingAs($admin)
            ->get($proofUrl)
            ->assertOk();
        $cacheControl = (string) $proofResponse->headers->get('cache-control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);

        $this->assertDatabaseHas('driver_assignments', [
            'id' => $assignmentId,
            'status' => 'delivered',
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'delivered',
        ]);
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'from_status' => 'ready',
            'to_status' => 'out_for_delivery',
        ]);
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'from_status' => 'out_for_delivery',
            'to_status' => 'delivered',
        ]);

        foreach (['accepted', 'picked_up'] as $deliveryStatus) {
            $this->assertNotificationData(
                $customerUser->id,
                'customer',
                'delivery.status_changed',
                'delivery_status',
                $deliveryStatus,
            );
            $this->assertNotificationData(
                $admin->id,
                'dashboard',
                'delivery.status_changed',
                'delivery_status',
                $deliveryStatus,
            );
        }

        foreach (['out_for_delivery', 'delivered'] as $orderStatus) {
            $this->assertNotificationData(
                $customerUser->id,
                'customer',
                'order.status_changed',
                'to_status',
                $orderStatus,
            );
            $this->assertNotificationData(
                $admin->id,
                'dashboard',
                'order.status_changed',
                'to_status',
                $orderStatus,
            );
        }

        Queue::assertPushed(DispatchPushNotification::class);
    }

    public function test_receive_and_fail_actions_are_available_before_ready_for_b2c_driver(): void
    {
        foreach ([
            ['channel' => 'b2c', 'role' => 'B2C_STORE_ADMIN', 'suffix' => 'PARITY-B2C'],
        ] as $case) {
            [$storeId, $order] = $this->order($case['channel'], $case['suffix']);
            $order->forceFill(['status' => 'confirmed'])->save();

            $admin = $this->admin(
                $case['role'],
                $storeId,
                strtolower($case['suffix']).'-admin@example.test',
            );
            [$driverUser, $driver] = $this->driver(
                $case['channel'],
                $storeId,
                strtolower($case['suffix']).'-driver@example.test',
            );

            Sanctum::actingAs($admin);
            $assignmentId = $this->postJson('/api/v1/admin/deliveries/assign', [
                'driver_id' => $driver->id,
                'order_id' => $order->id,
            ])->assertCreated()
                ->assertJsonPath('data.assignment_type', $case['channel'])
                ->json('data.id');

            Sanctum::actingAs($driverUser, ['app:driver']);
            $this->transition(
                $assignmentId,
                'accepted',
                strtolower($case['suffix']).'-accept',
            )->assertJsonPath('data.status', 'accepted')
                ->assertJsonPath('data.order.status', 'confirmed')
                ->assertJsonPath('data.available_statuses.0', 'picked_up')
                ->assertJsonPath('data.available_statuses.1', 'failed');

            $this->transition(
                $assignmentId,
                'picked_up',
                strtolower($case['suffix']).'-pickup',
            )->assertJsonPath('data.status', 'picked_up')
                ->assertJsonPath('data.order.status', 'confirmed')
                ->assertJsonPath('data.available_statuses.0', 'out_for_delivery')
                ->assertJsonPath('data.available_statuses.1', 'failed');

            $this->transition(
                $assignmentId,
                'out_for_delivery',
                strtolower($case['suffix']).'-start',
            )->assertJsonPath('data.status', 'out_for_delivery')
                ->assertJsonPath('data.order.status', 'out_for_delivery')
                ->assertJsonPath('data.available_statuses.0', 'delivered')
                ->assertJsonPath('data.available_statuses.1', 'failed');

            $this->assertDatabaseHas('order_status_history', [
                'order_id' => $order->id,
                'from_status' => 'confirmed',
                'to_status' => 'out_for_delivery',
            ]);
        }
    }

    public function test_reassignment_revocation_failure_and_b2b_contract_share_the_authoritative_path(): void
    {
        [$storeId, $order] = $this->order('b2c', 'REASSIGN');
        $order->forceFill(['status' => 'ready'])->save();

        $admin = $this->admin('B2C_STORE_ADMIN', $storeId, 'reassign-admin@example.test');
        [$oldUser, $oldDriver] = $this->driver('b2c', $storeId, 'old-journey-driver@example.test');
        [$newUser, $newDriver] = $this->driver('b2c', $storeId, 'new-journey-driver@example.test');

        Sanctum::actingAs($admin);
        $oldAssignmentId = $this->postJson('/api/v1/admin/deliveries/assign', [
            'driver_id' => $oldDriver->id,
            'order_id' => $order->id,
        ])->assertCreated()->json('data.id');

        $newAssignmentId = $this->postJson('/api/v1/admin/deliveries/assign', [
            'driver_id' => $newDriver->id,
            'order_id' => $order->id,
            'replace_existing' => true,
        ])->assertCreated()->json('data.id');

        $this->assertNotificationData(
            $oldUser->id,
            'driver',
            'delivery.reassigned_away',
            'access_revoked',
            true,
        );

        Sanctum::actingAs($oldUser, ['app:driver']);
        $this->getJson("/api/v1/driver/assignments/{$oldAssignmentId}")->assertNotFound();
        $this->postJson("/api/v1/driver/assignments/{$oldAssignmentId}/status", [
            'status' => 'accepted',
        ])->assertNotFound();

        Sanctum::actingAs($newUser, ['app:driver']);
        $this->transition($newAssignmentId, 'accepted', 'reassign-accept-1')->assertOk();
        $this->postJson(
            "/api/v1/driver/assignments/{$newAssignmentId}/status",
            [
                'status' => 'failed',
                'failure_reason' => 'customer_no_answer',
                'note' => 'Called twice',
            ],
            ['Idempotency-Key' => 'reassign-failed-1'],
        )->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.order.status', 'failed');

        $this->assertDatabaseHas('delivery_proofs', [
            'driver_assignment_id' => $newAssignmentId,
            'proof_type' => 'failure_note',
            'reason_code' => 'customer_no_answer',
            'note' => 'Called twice',
        ]);

        [$b2bStoreId, $b2bOrder] = $this->order('b2b', 'WHOLESALE');
        $b2bOrder->forceFill(['status' => 'ready'])->save();
        $b2bAdmin = $this->admin('B2B_ADMIN', $b2bStoreId, 'b2b-journey-admin@example.test');
        [$b2bDriverUser, $b2bDriver] = $this->driver(
            'b2b',
            $b2bStoreId,
            'b2b-journey-driver@example.test',
        );

        Sanctum::actingAs($b2bAdmin);
        $this->postJson('/api/v1/admin/deliveries/assign', [
            'driver_id' => $b2bDriver->id,
            'order_id' => $b2bOrder->id,
        ])->assertConflict()
            ->assertSee('B2B orders are fulfilled by Van runtime.');

        $this->assertDatabaseMissing('driver_assignments', [
            'driver_id' => $b2bDriver->id,
            'order_id' => $b2bOrder->id,
        ]);

        Sanctum::actingAs($b2bDriverUser, ['app:driver']);
        $this->getJson('/api/v1/driver/assignments')
            ->assertForbidden();
    }

    private function transition(int $assignmentId, string $status, string $key)
    {
        return $this->postJson(
            "/api/v1/driver/assignments/{$assignmentId}/status",
            ['status' => $status],
            ['Idempotency-Key' => $key],
        )->assertOk();
    }

    /** @return array{0:int,1:Order,2:User} */
    private function order(string $channel, string $suffix): array
    {
        $typeId = (int) DB::table('store_types')
            ->where('code', strtoupper($channel))
            ->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => 'JOURNEY-'.strtoupper($channel).'-'.$suffix,
            'name' => 'Journey '.strtoupper($channel).' '.$suffix,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customerUser = User::query()->create([
            'name' => 'Journey Customer '.$suffix,
            'email' => strtolower($channel.'-'.$suffix).'@journey.example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $customer = Customer::query()->create([
            'user_id' => $customerUser->id,
            'type' => $channel,
            'name' => $customerUser->name,
            'email' => $customerUser->email,
        ]);
        $order = Order::query()->create([
            'store_id' => $storeId,
            'customer_id' => $customer->id,
            'order_number' => 'JOURNEY-'.strtoupper($channel).'-'.$suffix,
            'channel' => $channel,
            'status' => 'pending',
            'currency' => 'KWD',
            'subtotal' => 10,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 10,
        ]);

        return [$storeId, $order, $customerUser];
    }

    private function admin(string $roleCode, int $storeId, string $email): User
    {
        $user = $this->roleUser($roleCode, $email);
        $role = Role::query()->where('code', $roleCode)->firstOrFail();

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    /** @return array{0:User,1:Driver} */
    private function driver(string $channel, int $storeId, string $email): array
    {
        $roleCode = $channel === 'b2b' ? 'B2B_DRIVER' : 'B2C_DRIVER';
        $user = $this->roleUser($roleCode, $email);
        $driver = Driver::query()->create([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'driver_type' => $channel,
            'is_available' => true,
            'is_active' => true,
        ]);

        return [$user, $driver];
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

    private function assertNotification(int $userId, string $app, string $type): void
    {
        $this->assertDatabaseHas('notifications', [
            'user_id' => $userId,
            'app' => $app,
            'type' => $type,
        ]);
    }

    private function assertNotificationData(
        int $userId,
        string $app,
        string $type,
        string $key,
        mixed $expected,
    ): void {
        $matches = Notification::query()
            ->where('user_id', $userId)
            ->where('app', $app)
            ->where('type', $type)
            ->get()
            ->contains(
                fn (Notification $notification): bool => (($notification->data ?? [])[$key] ?? null) === $expected,
            );

        $this->assertTrue(
            $matches,
            "Expected {$app} {$type} notification for user {$userId} with {$key}.",
        );
    }
}
