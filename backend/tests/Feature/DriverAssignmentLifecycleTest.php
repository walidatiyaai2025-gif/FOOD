<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Services\OrderDeliveryAddressSnapshotService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DriverAssignmentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_assigns_matching_driver_and_driver_completes_strict_delivery_lifecycle_with_audit(): void
    {
        Storage::fake('public');
        $this->seed(CoreReferenceSeeder::class);
        [$storeId, $order] = $this->order('b2c');
        $order->update(['status' => 'ready']);

        $admin = $this->roleUser('B2C_STORE_ADMIN', 'delivery-admin@example.test');
        $roleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id');
        DB::table('user_store_roles')->insert([
            'user_id' => $admin->id,
            'store_id' => $storeId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $driverUser = $this->roleUser('B2C_DRIVER', 'delivery-driver@example.test');
        $driver = Driver::query()->create([
            'user_id' => $driverUser->id,
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);
        $created = $this->postJson('/api/v1/admin/deliveries/assign', [
            'driver_id' => $driver->id,
            'order_id' => $order->id,
        ])->assertCreated()->assertJsonPath('data.assignment_type', 'b2c');

        Sanctum::actingAs($driverUser);
        $id = $created->json('data.id');

        $this->postJson(
            "/api/v1/driver/assignments/{$id}/status",
            ['status' => 'accepted'],
            ['Idempotency-Key' => 'delivery-accept-0001'],
        )->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.available_statuses.0', 'picked_up')
            ->assertJsonPath('data.available_statuses.1', 'failed');

        $this->postJson("/api/v1/driver/assignments/{$id}/status", [
            'status' => 'out_for_delivery',
        ])->assertConflict();

        $this->postJson("/api/v1/driver/assignments/{$id}/status", [
            'status' => 'delivered',
        ])->assertConflict();

        $this->postJson(
            "/api/v1/driver/assignments/{$id}/status",
            ['status' => 'picked_up'],
            ['Idempotency-Key' => 'delivery-pickup-0001'],
        )->assertOk()
            ->assertJsonPath('data.status', 'picked_up')
            ->assertJsonPath('data.available_statuses.0', 'out_for_delivery')
            ->assertJsonPath('data.available_statuses.1', 'failed');

        $this->postJson(
            "/api/v1/driver/assignments/{$id}/status",
            [
                'status' => 'out_for_delivery',
                'note' => 'Leaving store now',
            ],
            ['Idempotency-Key' => 'delivery-start-0001'],
        )->assertOk()
            ->assertJsonPath('data.status', 'out_for_delivery')
            ->assertJsonPath('data.order.status', 'out_for_delivery')
            ->assertJsonPath('data.available_statuses.0', 'delivered')
            ->assertJsonPath('data.available_statuses.1', 'failed');

        $this->post(
            "/api/v1/driver/assignments/{$id}/status",
            [
                'status' => 'delivered',
                'note' => 'Delivered to customer',
                'proof_image' => UploadedFile::fake()->image('proof.jpg', 640, 480),
            ],
            [
                'Accept' => 'application/json',
                'Idempotency-Key' => 'delivery-complete-0001',
            ],
        )->assertOk()
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.order.status', 'delivered')
            ->assertJsonCount(0, 'data.available_statuses');

        $customerUserId = (int) DB::table('customers')
            ->where('id', $order->customer_id)
            ->value('user_id');

        $this->assertSame(2, DB::table('notifications')
            ->where('user_id', $customerUserId)
            ->where('app', 'customer')
            ->where('type', 'order.status_changed')
            ->count());
        $this->assertSame(2, DB::table('notifications')
            ->where('user_id', $admin->id)
            ->where('app', 'dashboard')
            ->where('type', 'order.status_changed')
            ->count());

        $beforeDuplicate = DB::table('notifications')->count();
        $this->postJson("/api/v1/driver/assignments/{$id}/status", [
            'status' => 'delivered',
        ])->assertConflict();
        $this->assertSame($beforeDuplicate, DB::table('notifications')->count());

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'from_status' => 'ready',
            'to_status' => 'out_for_delivery',
            'note' => 'Leaving store now',
        ]);
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'from_status' => 'out_for_delivery',
            'to_status' => 'delivered',
            'note' => 'Delivered to customer',
        ]);
        $this->assertDatabaseHas('delivery_proofs', [
            'driver_assignment_id' => $id,
            'order_id' => $order->id,
            'proof_type' => 'delivery_image',
            'to_status' => 'delivered',
            'note' => 'Delivered to customer',
            'idempotency_key' => 'delivery-complete-0001',
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'delivery.assignment.status_changed']);
    }

    public function test_failed_delivery_requires_reason_and_other_requires_note(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$storeId, $order] = $this->order('b2c');
        $order->update(['status' => 'ready']);

        $admin = $this->roleUser('B2C_STORE_ADMIN', 'accepted-failure-admin@example.test');
        $roleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id');
        DB::table('user_store_roles')->insert([
            'user_id' => $admin->id,
            'store_id' => $storeId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $driverUser = $this->roleUser('B2C_DRIVER', 'accepted-failure-driver@example.test');
        $driver = Driver::query()->create([
            'user_id' => $driverUser->id,
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);
        $assignmentId = $this->postJson('/api/v1/admin/deliveries/assign', [
            'driver_id' => $driver->id,
            'order_id' => $order->id,
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($driverUser);
        $this->postJson("/api/v1/driver/assignments/{$assignmentId}/status", [
            'status' => 'accepted',
        ])->assertOk();

        $this->postJson("/api/v1/driver/assignments/{$assignmentId}/status", [
            'status' => 'failed',
            'note' => 'Customer unavailable',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['failure_reason']);

        $this->postJson("/api/v1/driver/assignments/{$assignmentId}/status", [
            'status' => 'failed',
            'failure_reason' => 'other',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['note']);

        $this->postJson("/api/v1/driver/assignments/{$assignmentId}/status", [
            'status' => 'failed',
            'failure_reason' => 'other',
            'note' => 'Customer unavailable',
        ])->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.order.status', 'failed')
            ->assertJsonCount(0, 'data.available_statuses');

        $this->assertDatabaseHas('driver_assignments', [
            'id' => $assignmentId,
            'status' => 'failed',
        ]);
        $this->assertNotNull(DB::table('driver_assignments')->where('id', $assignmentId)->value('completed_at'));
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'from_status' => 'ready',
            'to_status' => 'failed',
            'note' => 'other: Customer unavailable',
        ]);
        $this->assertDatabaseHas('delivery_proofs', [
            'driver_assignment_id' => $assignmentId,
            'order_id' => $order->id,
            'proof_type' => 'failure_note',
            'to_status' => 'failed',
            'reason_code' => 'other',
            'note' => 'Customer unavailable',
        ]);

        $notificationCount = DB::table('notifications')->count();
        $this->postJson("/api/v1/driver/assignments/{$assignmentId}/status", [
            'status' => 'failed',
            'failure_reason' => 'other',
            'note' => 'Customer unavailable',
        ])->assertConflict();
        $this->assertSame($notificationCount, DB::table('notifications')->count());
    }

    public function test_driver_can_fail_from_out_for_delivery_with_reason_without_ready_reset(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$storeId, $order] = $this->order('b2c');
        $order->update(['status' => 'ready']);

        $admin = $this->roleUser('B2C_STORE_ADMIN', 'route-failure-admin@example.test');
        $roleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id');
        DB::table('user_store_roles')->insert([
            'user_id' => $admin->id,
            'store_id' => $storeId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $driverUser = $this->roleUser('B2C_DRIVER', 'route-failure-driver@example.test');
        $driver = Driver::query()->create([
            'user_id' => $driverUser->id,
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);
        $assignmentId = $this->postJson('/api/v1/admin/deliveries/assign', [
            'driver_id' => $driver->id,
            'order_id' => $order->id,
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($driverUser);
        foreach (['accepted', 'picked_up', 'out_for_delivery'] as $status) {
            $this->postJson("/api/v1/driver/assignments/{$assignmentId}/status", [
                'status' => $status,
            ])->assertOk();
        }

        $this->postJson("/api/v1/driver/assignments/{$assignmentId}/status", [
            'status' => 'failed',
            'failure_reason' => 'customer_no_answer',
        ])->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.order.status', 'failed')
            ->assertJsonCount(0, 'data.available_statuses');

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'from_status' => 'out_for_delivery',
            'to_status' => 'failed',
            'note' => 'customer_no_answer',
        ]);
        $this->assertDatabaseMissing('order_status_history', [
            'order_id' => $order->id,
            'from_status' => 'failed',
            'to_status' => 'ready',
        ]);
        $this->assertDatabaseHas('delivery_proofs', [
            'driver_assignment_id' => $assignmentId,
            'proof_type' => 'failure_note',
            'to_status' => 'failed',
            'reason_code' => 'customer_no_answer',
        ]);
    }

    public function test_picked_up_is_the_required_step_after_acceptance(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$storeId, $order] = $this->order('b2c');
        $order->update(['status' => 'ready']);

        $admin = $this->roleUser('B2C_STORE_ADMIN', 'legacy-pickup-admin@example.test');
        $roleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id');
        DB::table('user_store_roles')->insert([
            'user_id' => $admin->id,
            'store_id' => $storeId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $driverUser = $this->roleUser('B2C_DRIVER', 'legacy-pickup-driver@example.test');
        $driver = Driver::query()->create([
            'user_id' => $driverUser->id,
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);
        $assignmentId = $this->postJson('/api/v1/admin/deliveries/assign', [
            'driver_id' => $driver->id,
            'order_id' => $order->id,
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($driverUser);
        $this->postJson("/api/v1/driver/assignments/{$assignmentId}/status", [
            'status' => 'accepted',
        ])->assertOk();

        $this->postJson("/api/v1/driver/assignments/{$assignmentId}/status", [
            'status' => 'picked_up',
        ])->assertOk()
            ->assertJsonPath('data.status', 'picked_up')
            ->assertJsonPath('data.available_statuses.0', 'out_for_delivery')
            ->assertJsonPath('data.available_statuses.1', 'failed');
    }

    public function test_delivered_transition_can_store_optional_proof_image(): void
    {
        Storage::fake('public');
        $this->seed(CoreReferenceSeeder::class);
        [$storeId, $order] = $this->order('b2c');
        $order->update(['status' => 'ready']);

        $admin = $this->roleUser('B2C_STORE_ADMIN', 'proof-admin@example.test');
        $roleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id');
        DB::table('user_store_roles')->insert([
            'user_id' => $admin->id,
            'store_id' => $storeId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $driverUser = $this->roleUser('B2C_DRIVER', 'proof-driver@example.test');
        $driver = Driver::query()->create([
            'user_id' => $driverUser->id,
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);
        $assignmentId = $this->postJson('/api/v1/admin/deliveries/assign', [
            'driver_id' => $driver->id,
            'order_id' => $order->id,
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($driverUser);
        foreach (['accepted', 'picked_up', 'out_for_delivery'] as $status) {
            $this->postJson("/api/v1/driver/assignments/{$assignmentId}/status", [
                'status' => $status,
            ])->assertOk();
        }

        $response = $this->post(
            "/api/v1/driver/assignments/{$assignmentId}/status",
            [
                'status' => 'delivered',
                'note' => 'Delivered at reception',
                'proof_image' => UploadedFile::fake()->image('proof.jpg', 640, 480),
            ],
            ['Accept' => 'application/json'],
        )->assertOk()
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.order.status', 'delivered');

        $proof = DB::table('delivery_proofs')
            ->where('driver_assignment_id', $assignmentId)
            ->where('proof_type', 'delivery_image')
            ->first();

        $this->assertNotNull($proof);
        $this->assertSame('Delivered at reception', $proof->note);
        $this->assertNotNull($proof->file_path);
        Storage::disk('public')->assertExists($proof->file_path);
        $response->assertJsonPath('data.order.driver_history.0.proof_type', 'delivery_image');
        $response->assertJsonPath('data.order.driver_history.0.file_path', $proof->file_path);
    }

    public function test_delivered_requires_valid_proof_before_terminal_transition(): void
    {
        Storage::fake('public');
        $this->seed(CoreReferenceSeeder::class);
        [$storeId, $order] = $this->order('b2c');
        $order->update(['status' => 'ready']);

        $admin = $this->roleUser('B2C_STORE_ADMIN', 'proof-required-admin@example.test');
        $roleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id');
        DB::table('user_store_roles')->insert([
            'user_id' => $admin->id,
            'store_id' => $storeId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $driverUser = $this->roleUser('B2C_DRIVER', 'proof-required-driver@example.test');
        $driver = Driver::query()->create([
            'user_id' => $driverUser->id,
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);
        $assignmentId = $this->postJson('/api/v1/admin/deliveries/assign', [
            'driver_id' => $driver->id,
            'order_id' => $order->id,
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($driverUser);
        foreach (['accepted', 'picked_up', 'out_for_delivery'] as $status) {
            $this->postJson("/api/v1/driver/assignments/{$assignmentId}/status", [
                'status' => $status,
            ])->assertOk();
        }

        $this->postJson("/api/v1/driver/assignments/{$assignmentId}/status", [
            'status' => 'delivered',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['proof_image']);

        $this->assertDatabaseHas('driver_assignments', [
            'id' => $assignmentId,
            'status' => 'out_for_delivery',
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'out_for_delivery',
        ]);
    }

    public function test_idempotency_key_replays_same_transition_without_duplicate_side_effects(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$storeId, $order] = $this->order('b2c');
        $order->update(['status' => 'ready']);

        $admin = $this->roleUser('B2C_STORE_ADMIN', 'idempotency-admin@example.test');
        $roleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id');
        DB::table('user_store_roles')->insert([
            'user_id' => $admin->id,
            'store_id' => $storeId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $driverUser = $this->roleUser('B2C_DRIVER', 'idempotency-driver@example.test');
        $driver = Driver::query()->create([
            'user_id' => $driverUser->id,
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);
        $assignmentId = $this->postJson('/api/v1/admin/deliveries/assign', [
            'driver_id' => $driver->id,
            'order_id' => $order->id,
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($driverUser);
        $headers = ['Idempotency-Key' => 'accept-retry-0001'];
        $payload = [
            'status' => 'accepted',
            'note' => 'Taking this delivery',
        ];

        $this->postJson(
            "/api/v1/driver/assignments/{$assignmentId}/status",
            $payload,
            $headers,
        )->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('meta.idempotency_key', 'accept-retry-0001');

        $proofCount = DB::table('delivery_proofs')->count();
        $auditCount = DB::table('audit_logs')
            ->where('event', 'delivery.assignment.status_changed')
            ->count();
        $notificationCount = DB::table('notifications')->count();

        $this->postJson(
            "/api/v1/driver/assignments/{$assignmentId}/status",
            $payload,
            $headers,
        )->assertOk()
            ->assertJsonPath('data.status', 'accepted');

        $this->assertSame($proofCount, DB::table('delivery_proofs')->count());
        $this->assertSame(
            $auditCount,
            DB::table('audit_logs')->where('event', 'delivery.assignment.status_changed')->count(),
        );
        $this->assertSame($notificationCount, DB::table('notifications')->count());
        $this->assertDatabaseHas('delivery_proofs', [
            'driver_assignment_id' => $assignmentId,
            'order_id' => $order->id,
            'idempotency_key' => 'accept-retry-0001',
            'from_status' => 'assigned',
            'to_status' => 'accepted',
        ]);

        $this->postJson(
            "/api/v1/driver/assignments/{$assignmentId}/status",
            [
                'status' => 'accepted',
                'note' => 'Changed payload',
            ],
            $headers,
        )->assertConflict();
    }

    public function test_driver_payload_uses_immutable_order_delivery_snapshot(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$storeId, $order] = $this->order('b2c');

        $address = Address::query()->create([
            'customer_id' => $order->customer_id,
            'label' => 'Home',
            'recipient_name' => 'Delivery Customer',
            'delivery_phone' => '+201000000999',
            'line1' => 'Original delivery street',
            'city' => 'Cairo',
            'country_code' => 'EG',
            'landmark' => 'Original landmark',
            'delivery_notes' => 'Ring once',
            'latitude' => 30.0444200,
            'longitude' => 31.2357120,
            'location_source' => 'map_pin',
            'is_default' => true,
        ]);

        $order->fill([
            'address_id' => $address->id,
            ...app(OrderDeliveryAddressSnapshotService::class)->attributes($address),
            'status' => 'ready',
        ])->save();

        $address->update([
            'line1' => 'Mutated customer address',
            'landmark' => 'Mutated landmark',
            'latitude' => 29.5000000,
            'longitude' => 30.5000000,
        ]);

        $admin = $this->roleUser('B2C_STORE_ADMIN', 'snapshot-admin@example.test');
        $roleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id');
        DB::table('user_store_roles')->insert([
            'user_id' => $admin->id,
            'store_id' => $storeId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $driverUser = $this->roleUser('B2C_DRIVER', 'snapshot-driver@example.test');
        $driver = Driver::query()->create([
            'user_id' => $driverUser->id,
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);
        $assignmentId = $this->postJson('/api/v1/admin/deliveries/assign', [
            'driver_id' => $driver->id,
            'order_id' => $order->id,
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($driverUser);
        $this->getJson("/api/v1/driver/assignments/{$assignmentId}")
            ->assertOk()
            ->assertJsonPath('data.order.address.line1', 'Original delivery street')
            ->assertJsonPath('data.order.address.landmark', 'Original landmark')
            ->assertJsonPath('data.order.address.delivery_notes', 'Ring once')
            ->assertJsonPath('data.order.address.has_coordinates', true)
            ->assertJsonPath('data.order.navigation.available', true)
            ->assertJsonPath('data.order.navigation.latitude', 30.04442)
            ->assertJsonPath('data.order.navigation.longitude', 31.235712);
    }

    public function test_admin_can_reassign_and_unassign_order_while_preserving_history(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$storeId, $order] = $this->order('b2c');
        $order->update(['status' => 'ready']);

        $admin = $this->roleUser('B2C_STORE_ADMIN', 'delivery-reassign-admin@example.test');
        $roleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id');
        DB::table('user_store_roles')->insert([
            'user_id' => $admin->id,
            'store_id' => $storeId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $driverOneUser = $this->roleUser('B2C_DRIVER', 'delivery-reassign-one@example.test');
        $driverOne = Driver::query()->create([
            'user_id' => $driverOneUser->id,
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);
        $driverTwoUser = $this->roleUser('B2C_DRIVER', 'delivery-reassign-two@example.test');
        $driverTwo = Driver::query()->create([
            'user_id' => $driverTwoUser->id,
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);
        $firstId = $this->postJson('/api/v1/admin/deliveries/assign', [
            'driver_id' => $driverOne->id,
            'order_id' => $order->id,
        ])->assertCreated()->json('data.id');

        $secondId = $this->postJson('/api/v1/admin/deliveries/assign', [
            'driver_id' => $driverTwo->id,
            'order_id' => $order->id,
            'replace_existing' => true,
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('driver_assignments', [
            'id' => $firstId,
            'status' => 'reassigned',
        ]);
        $this->assertDatabaseHas('driver_assignments', [
            'id' => $secondId,
            'driver_id' => $driverTwo->id,
            'status' => 'assigned',
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'delivery.assignment.reassigned']);

        Sanctum::actingAs($driverOneUser);
        $this->getJson('/api/v1/driver/assignments?scope=active')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson("/api/v1/driver/assignments/{$firstId}")
            ->assertNotFound();

        $history = $this->getJson('/api/v1/driver/assignments?scope=all')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $firstId)
            ->assertJsonPath('data.0.status', 'reassigned');
        $history->assertJsonMissingPath('data.0.order.address');
        $history->assertJsonMissingPath('data.0.order.customer');
        $history->assertJsonMissingPath('data.0.order.navigation');

        Sanctum::actingAs($driverTwoUser);
        $this->getJson('/api/v1/driver/assignments?scope=active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $secondId)
            ->assertJsonPath('data.0.order.number', $order->order_number);

        Sanctum::actingAs($admin);
        $this->deleteJson('/api/v1/admin/deliveries/orders/'.$order->id, [
            'reason' => 'dispatcher_removed',
        ])->assertOk();

        $this->assertDatabaseHas('driver_assignments', [
            'id' => $secondId,
            'status' => 'unassigned',
        ]);

        Sanctum::actingAs($driverTwoUser);
        $this->getJson('/api/v1/driver/assignments?scope=active')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_legacy_driver_store_and_assignment_scope_are_reconciled_for_visibility(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$storeId, $order] = $this->order('b2c');
        $order->update(['status' => 'ready']);

        $driverUser = $this->roleUser('B2C_DRIVER', 'legacy-visible-driver@example.test');
        $driver = Driver::query()->create([
            'user_id' => $driverUser->id,
            'store_id' => null,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);
        $assignmentId = (int) DB::table('driver_assignments')->insertGetId([
            'driver_id' => $driver->id,
            'order_id' => $order->id,
            'store_id' => null,
            'assignment_type' => 'b2c',
            'status' => 'assigned',
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($driverUser);
        $this->getJson('/api/v1/driver/assignments?scope=active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $assignmentId)
            ->assertJsonPath('data.0.order.number', $order->order_number);

        $this->assertDatabaseHas('drivers', [
            'id' => $driver->id,
            'store_id' => $storeId,
        ]);
        $this->assertDatabaseHas('driver_assignments', [
            'id' => $assignmentId,
            'store_id' => $storeId,
        ]);
    }

    public function test_cancelled_assignment_is_history_not_active_driver_work(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$storeId, $order] = $this->order('b2c');
        $order->update(['status' => 'ready']);

        $driverUser = $this->roleUser('B2C_DRIVER', 'cancelled-history-driver@example.test');
        $driver = Driver::query()->create([
            'user_id' => $driverUser->id,
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);

        DB::table('driver_assignments')->insert([
            'driver_id' => $driver->id,
            'order_id' => $order->id,
            'store_id' => $storeId,
            'assignment_type' => 'b2c',
            'status' => 'cancelled',
            'assigned_at' => now()->subMinute(),
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($driverUser);
        $this->getJson('/api/v1/driver/assignments?scope=active')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/v1/driver/assignments?scope=all')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'cancelled');
    }

    public function test_driver_cannot_open_or_transition_another_drivers_assignment(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$storeId, $order] = $this->order('b2c');
        $order->update(['status' => 'ready']);

        $admin = $this->roleUser('B2C_STORE_ADMIN', 'delivery-admin-2@example.test');
        $roleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id');
        DB::table('user_store_roles')->insert([
            'user_id' => $admin->id,
            'store_id' => $storeId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $driverOneUser = $this->roleUser('B2C_DRIVER', 'delivery-driver-one@example.test');
        $driverOne = Driver::query()->create([
            'user_id' => $driverOneUser->id,
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);
        $driverTwoUser = $this->roleUser('B2C_DRIVER', 'delivery-driver-two@example.test');
        Driver::query()->create([
            'user_id' => $driverTwoUser->id,
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);
        $assignmentId = $this->postJson('/api/v1/admin/deliveries/assign', [
            'driver_id' => $driverOne->id,
            'order_id' => $order->id,
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($driverTwoUser);
        $this->getJson("/api/v1/driver/assignments/{$assignmentId}")->assertNotFound();
        $this->postJson("/api/v1/driver/assignments/{$assignmentId}/status", [
            'status' => 'accepted',
        ])->assertNotFound();
    }

    public function test_cross_channel_assignment_and_execution_are_denied(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [, $order] = $this->order('b2b');
        $admin = $this->roleUser('B2B_ADMIN', 'b2b-delivery-admin@example.test');
        $driverUser = $this->roleUser('B2C_DRIVER', 'wrong-driver@example.test');
        $driver = Driver::query()->create(['user_id' => $driverUser->id, 'driver_type' => 'b2c', 'is_available' => true, 'is_active' => true]);
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/deliveries/assign', ['driver_id' => $driver->id, 'order_id' => $order->id])->assertConflict();
        Sanctum::actingAs($driverUser);
        $this->getJson('/api/v1/driver/assignments')->assertOk()->assertJsonCount(0, 'data');
    }

    private function order(string $channel): array
    {
        $typeId = (int) DB::table('store_types')->where('code', strtoupper($channel))->value('id');
        $storeId = (int) DB::table('stores')->insertGetId(['store_type_id' => $typeId, 'code' => 'DEL-'.strtoupper($channel), 'name' => 'Delivery Store', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $customerUser = User::query()->create(['name' => 'Customer', 'email' => $channel.'-delivery-customer@example.test', 'password' => 'password', 'is_active' => true]);
        $customer = Customer::query()->create(['user_id' => $customerUser->id, 'type' => $channel, 'name' => 'Customer', 'email' => $customerUser->email]);
        $order = Order::query()->create(['store_id' => $storeId, 'customer_id' => $customer->id, 'order_number' => 'DEL-'.strtoupper($channel).'-1', 'channel' => $channel, 'status' => 'pending', 'currency' => 'KWD', 'subtotal' => 1, 'discount_total' => 0, 'delivery_total' => 0, 'grand_total' => 1]);

        return [$storeId, $order];
    }

    private function roleUser(string $role, string $email): User
    {
        $user = User::query()->create(['name' => $role, 'email' => $email, 'password' => 'password', 'is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

        return $user;
    }
}
