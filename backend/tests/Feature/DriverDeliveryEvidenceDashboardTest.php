<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DeliveryProof;
use App\Models\Driver;
use App\Models\DriverAssignment;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\TestCase;

class DriverDeliveryEvidenceDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
        Storage::fake('public');
    }

    public function test_authorized_operations_user_sees_immutable_timeline_and_proof_without_raw_path_leakage(): void
    {
        [$storeId, $order, $customer] = $this->orderFixture('EVIDENCE-A');
        $admin = $this->storeAdmin($storeId, 'evidence-admin@example.test');
        $driver = $this->driver($storeId, 'evidence-driver@example.test');

        $failedAssignment = $this->assignment($driver, $order, 'failed', now()->subMinutes(8));
        $this->event($failedAssignment, $order, 'assigned', 'accepted');
        $this->event($failedAssignment, $order, 'accepted', 'picked_up');
        $this->event($failedAssignment, $order, 'picked_up', 'out_for_delivery');
        $this->event(
            $failedAssignment,
            $order,
            'out_for_delivery',
            'failed',
            proofType: 'failure_note',
            reasonCode: 'customer_no_answer',
            note: 'Called twice',
        );

        $deliveredAssignment = $this->assignment($driver, $order, 'delivered', now()->subMinutes(3));
        $this->event($deliveredAssignment, $order, 'assigned', 'accepted');
        $this->event($deliveredAssignment, $order, 'accepted', 'picked_up');
        $this->event($deliveredAssignment, $order, 'picked_up', 'out_for_delivery');
        Storage::disk('public')->put('delivery-proofs/evidence-proof.jpg', 'proof-bytes');
        $proof = $this->event(
            $deliveredAssignment,
            $order,
            'out_for_delivery',
            'delivered',
            proofType: 'delivery_image',
            note: 'Handed to customer',
            filePath: 'delivery-proofs/evidence-proof.jpg',
        );

        $order->forceFill(['status' => 'delivered'])->save();

        $response = $this->actingAs($admin)
            ->getJson(route('admin.driver-live-tracking.evidence', $deliveredAssignment->id))
            ->assertOk()
            ->assertJsonPath('data.id', $deliveredAssignment->id)
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonCount(4, 'data.timeline')
            ->assertJsonPath('data.timeline.3.to_status', 'delivered')
            ->assertJsonPath('data.timeline.3.note', 'Handed to customer')
            ->assertJsonPath('data.timeline.3.proof.available', true);

        $response->assertJsonMissingPath('data.timeline.3.file_path');
        $this->assertStringNotContainsString(
            'delivery-proofs/evidence-proof.jpg',
            $response->getContent(),
        );

        $proofUrl = $response->json('data.timeline.3.proof.url');
        $this->assertIsString($proofUrl);
        $proofResponse = $this->actingAs($admin)
            ->get($proofUrl)
            ->assertOk();
        $cacheControl = (string) $proofResponse->headers->get('cache-control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('max-age=0', $cacheControl);

        $this->actingAs($admin)
            ->get(route('admin.operations.orders.index', ['order' => $order->id]))
            ->assertOk()
            ->assertSee('data-delivery-evidence', false)
            ->assertSee('data-delivery-evidence-assignment="'.$failedAssignment->id.'"', false)
            ->assertSee('data-delivery-evidence-assignment="'.$deliveredAssignment->id.'"', false)
            ->assertDontSee('customer_no_answer')
            ->assertSee('العميل لا يجيب')
            ->assertSee('Called twice')
            ->assertSee('Handed to customer')
            ->assertSee('data-delivery-proof-link', false)
            ->assertDontSee('delivery-proofs/evidence-proof.jpg');

        $customer->forceFill(['name' => 'Changed customer profile'])->save();

        $this->actingAs($admin)
            ->getJson(route('admin.driver-live-tracking.evidence', $deliveredAssignment->id))
            ->assertOk()
            ->assertJsonPath('data.timeline.3.note', 'Handed to customer');

        $this->expectException(LogicException::class);
        $proof->update(['note' => 'tampered']);
    }

    public function test_foreign_store_user_cannot_read_evidence_or_proof(): void
    {
        [$storeId, $order] = $this->orderFixture('EVIDENCE-OWNER');
        [$foreignStoreId] = $this->orderFixture('EVIDENCE-FOREIGN');
        $foreignAdmin = $this->storeAdmin($foreignStoreId, 'foreign-evidence-admin@example.test');
        $driver = $this->driver($storeId, 'owner-evidence-driver@example.test');
        $assignment = $this->assignment($driver, $order, 'delivered', now()->subMinute());

        Storage::disk('public')->put('delivery-proofs/foreign-guard.jpg', 'proof');
        $proof = $this->event(
            $assignment,
            $order,
            'out_for_delivery',
            'delivered',
            proofType: 'delivery_image',
            filePath: 'delivery-proofs/foreign-guard.jpg',
        );

        $this->actingAs($foreignAdmin)
            ->getJson(route('admin.driver-live-tracking.evidence', $assignment->id))
            ->assertNotFound();

        $this->actingAs($foreignAdmin)
            ->get(route('admin.driver-live-tracking.proofs.show', [
                'assignment' => $assignment->id,
                'proof' => $proof->id,
            ]))
            ->assertNotFound();
    }

    /** @return array{0:int,1:Order,2:Customer} */
    private function orderFixture(string $code): array
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customerUser = User::query()->create([
            'name' => $code.' Customer',
            'email' => strtolower($code).'@customer.example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $customer = Customer::query()->create([
            'user_id' => $customerUser->id,
            'type' => 'b2c',
            'name' => $customerUser->name,
            'email' => $customerUser->email,
        ]);
        $order = Order::query()->create([
            'store_id' => $storeId,
            'customer_id' => $customer->id,
            'order_number' => $code.'-ORDER',
            'channel' => 'b2c',
            'status' => 'ready',
            'currency' => 'KWD',
            'subtotal' => 1,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 1,
        ]);

        return [$storeId, $order, $customer];
    }

    private function storeAdmin(int $storeId, string $email): User
    {
        $user = $this->roleUser('B2C_STORE_ADMIN', $email);
        $roleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id');

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function driver(int $storeId, string $email): Driver
    {
        $user = $this->roleUser('B2C_DRIVER', $email);

        return Driver::query()->create([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);
    }

    private function assignment(
        Driver $driver,
        Order $order,
        string $status,
        $assignedAt,
    ): DriverAssignment {
        return DriverAssignment::query()->create([
            'driver_id' => $driver->id,
            'order_id' => $order->id,
            'store_id' => $order->store_id,
            'assignment_type' => 'b2c',
            'status' => $status,
            'assigned_at' => $assignedAt,
            'completed_at' => in_array($status, ['failed', 'delivered'], true) ? now() : null,
        ]);
    }

    private function event(
        DriverAssignment $assignment,
        Order $order,
        ?string $fromStatus,
        string $toStatus,
        string $proofType = 'status_transition',
        ?string $reasonCode = null,
        ?string $note = null,
        ?string $filePath = null,
    ): DeliveryProof {
        return DeliveryProof::query()->create([
            'driver_assignment_id' => $assignment->id,
            'order_id' => $order->id,
            'user_id' => null,
            'proof_type' => $proofType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'file_path' => $filePath,
            'reason_code' => $reasonCode,
            'note' => $note,
            'captured_at' => now(),
        ]);
    }

    private function roleUser(string $roleCode, string $email): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => $email,
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }
}
