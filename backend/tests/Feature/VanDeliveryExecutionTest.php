<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Services\VanRegistryService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VanDeliveryExecutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_van_executes_full_b2b_lifecycle_with_staged_delivery_proof(): void
    {
        Storage::fake('public');
        $actor = $this->vanActor();
        [$order, $assignmentId] = $this->assignedB2bOrder($actor, 'LIFECYCLE');

        $this->getJson('/api/v1/van/orders/'.$order->id.'/execution')
            ->assertOk()
            ->assertJsonPath('data.status', 'assigned')
            ->assertJsonPath('data.allowed_actions.0', 'accepted');

        $this->transition($order->id, 'accepted', 'van-accept-0001')
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.allowed_actions.0', 'picked_up');

        $this->transition($order->id, 'picked_up', 'van-pickup-0001')
            ->assertOk()
            ->assertJsonPath('data.status', 'picked_up');

        $this->transition($order->id, 'out_for_delivery', 'van-ofd-0001')
            ->assertOk()
            ->assertJsonPath('data.status', 'out_for_delivery')
            ->assertJsonPath('data.order_status', 'out_for_delivery');

        $proofResponse = $this->withHeader('Idempotency-Key', 'van-proof-0001')
            ->post('/api/v1/van/orders/'.$order->id.'/execution/proof', [
                'proof_image' => UploadedFile::fake()->image('van-delivery.jpg', 640, 480),
                'note' => 'Customer door proof',
            ]);

        $proofResponse
            ->assertOk()
            ->assertJsonPath('data.status', 'out_for_delivery')
            ->assertJsonPath('data.proof.available', true);

        DB::table('payments')->insert([
            'order_id' => $order->id,
            'invoice_id' => null,
            'provider' => 'cash_on_delivery',
            'provider_reference' => 'VAN-LIFECYCLE-'.$order->id,
            'status' => 'paid',
            'amount' => 20,
            'currency' => 'EGP',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->transition($order->id, 'delivered', 'van-delivered-0001')
            ->assertOk()
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.order_status', 'delivered')
            ->assertJsonPath('data.allowed_actions', []);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'delivered',
        ]);
        $this->assertDatabaseHas('order_van_execution_states', [
            'order_van_assignment_id' => $assignmentId,
            'status' => 'delivered',
            'failure_reason_code' => null,
        ]);
        $this->assertDatabaseCount('order_van_execution_events', 5);

        $proofPath = DB::table('order_van_execution_events')
            ->where('order_van_assignment_id', $assignmentId)
            ->where('action', 'proof_upload')
            ->value('proof_path');
        $this->assertIsString($proofPath);
        Storage::disk('public')->assertExists($proofPath);
    }

    public function test_delivered_is_blocked_until_authoritative_collection_is_settled(): void
    {
        Storage::fake('public');
        $actor = $this->vanActor();
        [$order] = $this->assignedB2bOrder($actor, 'COLLECTION');

        $this->transition($order->id, 'accepted', 'collect-accept-01')->assertOk();
        $this->transition($order->id, 'picked_up', 'collect-pickup-01')->assertOk();
        $this->transition($order->id, 'out_for_delivery', 'collect-ofd-0001')->assertOk();

        $this->withHeader('Idempotency-Key', 'collect-proof-01')
            ->post('/api/v1/van/orders/'.$order->id.'/execution/proof', [
                'proof_image' => UploadedFile::fake()->image('collection-proof.jpg', 640, 480),
            ])
            ->assertOk();

        $this->transition($order->id, 'delivered', 'collect-deliver1')
            ->assertStatus(409);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'out_for_delivery',
        ]);
        $this->assertDatabaseHas('order_van_execution_states', [
            'order_id' => $order->id,
            'status' => 'out_for_delivery',
        ]);
        $this->assertDatabaseMissing('order_van_execution_events', [
            'order_id' => $order->id,
            'to_status' => 'delivered',
        ]);
    }

    public function test_failed_delivery_is_idempotent_and_retry_returns_order_to_out_for_delivery(): void
    {
        $actor = $this->vanActor();
        [$order, $assignmentId] = $this->assignedB2bOrder($actor, 'RETRY');

        $this->transition($order->id, 'accepted', 'retry-accept-01')->assertOk();

        $payload = [
            'failure_reason' => 'customer_no_answer',
            'note' => 'Called twice with no answer',
        ];

        $this->withHeader('Idempotency-Key', 'van-failed-0001')
            ->postJson('/api/v1/van/orders/'.$order->id.'/execution/fail', $payload)
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.order_status', 'failed')
            ->assertJsonPath('data.failure_reason_code', 'customer_no_answer');

        $this->withHeader('Idempotency-Key', 'van-failed-0001')
            ->postJson('/api/v1/van/orders/'.$order->id.'/execution/fail', $payload)
            ->assertOk()
            ->assertJsonPath('data.replayed', true);

        $this->withHeader('Idempotency-Key', 'van-failed-0001')
            ->postJson('/api/v1/van/orders/'.$order->id.'/execution/fail', [
                ...$payload,
                'note' => 'Changed request must not replay',
            ])
            ->assertConflict();

        $this->assertSame(
            1,
            DB::table('order_van_execution_events')
                ->where('order_van_assignment_id', $assignmentId)
                ->where('idempotency_key', 'van-failed-0001')
                ->count(),
        );

        $this->withHeader('Idempotency-Key', 'van-retry-00001')
            ->postJson('/api/v1/van/orders/'.$order->id.'/execution/retry', [
                'note' => 'Customer is now reachable',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'out_for_delivery')
            ->assertJsonPath('data.order_status', 'out_for_delivery')
            ->assertJsonPath('data.failure_reason_code', null)
            ->assertJsonPath('data.allowed_actions.0', 'delivered')
            ->assertJsonPath('data.allowed_actions.1', 'failed');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'out_for_delivery',
        ]);
    }

    public function test_stale_reassigned_or_cross_van_actor_cannot_mutate_order(): void
    {
        $actorA = $this->vanActor();
        [$order, $assignmentId] = $this->assignedB2bOrder($actorA, 'STALE');

        $actorB = $this->vanActor();
        Sanctum::actingAs($actorB, ['app:van']);

        $this->transition($order->id, 'accepted', 'cross-van-00001')
            ->assertNotFound();

        Sanctum::actingAs($actorA, ['app:van']);
        DB::table('order_van_assignments')
            ->where('id', $assignmentId)
            ->update([
                'status' => 'reassigned',
                'ended_at' => now(),
                'updated_at' => now(),
            ]);

        $this->transition($order->id, 'accepted', 'stale-van-00001')
            ->assertNotFound();

        $this->assertDatabaseHas('order_van_execution_states', [
            'order_van_assignment_id' => $assignmentId,
            'status' => 'assigned',
        ]);
        $this->assertDatabaseCount('order_van_execution_events', 0);
    }

    public function test_allowed_actions_endpoint_exposes_current_van_execution_actions(): void
    {
        $actor = $this->vanActor();
        [$order] = $this->assignedB2bOrder($actor, 'ACTIONS');

        $this->getJson('/api/v1/van/orders/'.$order->id.'/execution/allowed-actions')
            ->assertOk()
            ->assertJsonPath('data.status', 'assigned')
            ->assertJsonPath('data.allowed_actions.0', 'accepted');
    }

    public function test_delivered_requires_proof_even_after_required_collection_is_settled(): void
    {
        Storage::fake('public');
        $actor = $this->vanActor();
        [$order] = $this->assignedB2bOrder($actor, 'PROOF-GATE');

        $this->transition($order->id, 'accepted', 'proofgate-accept')->assertOk();
        $this->transition($order->id, 'picked_up', 'proofgate-pickup')->assertOk();
        $this->transition($order->id, 'out_for_delivery', 'proofgate-ofd')->assertOk();

        DB::table('payments')->insert([
            'order_id' => $order->id,
            'invoice_id' => null,
            'provider' => 'cash_on_delivery',
            'provider_reference' => 'PROOF-GATE-'.$order->id,
            'status' => 'paid',
            'amount' => 20,
            'currency' => 'EGP',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->transition($order->id, 'delivered', 'proofgate-deliver')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['proof_image']);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'out_for_delivery',
        ]);
        $this->assertDatabaseHas('order_van_execution_states', [
            'order_id' => $order->id,
            'status' => 'out_for_delivery',
        ]);
        $this->assertDatabaseMissing('order_van_execution_events', [
            'order_id' => $order->id,
            'to_status' => 'delivered',
        ]);
    }

    public function test_failed_delivery_uses_configured_reason_lookup_and_other_requires_note(): void
    {
        $actor = $this->vanActor();
        [$order] = $this->assignedB2bOrder($actor, 'FAILURE-RULES');

        $this->transition($order->id, 'accepted', 'failure-rules-accept')->assertOk();

        $this->withHeader('Idempotency-Key', 'failure-invalid-reason')
            ->postJson('/api/v1/van/orders/'.$order->id.'/execution/fail', [
                'failure_reason' => 'not_configured',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['failure_reason']);

        $this->withHeader('Idempotency-Key', 'failure-other-note')
            ->postJson('/api/v1/van/orders/'.$order->id.'/execution/fail', [
                'failure_reason' => 'other',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['note']);

        $this->assertDatabaseHas('order_van_execution_states', [
            'order_id' => $order->id,
            'status' => 'accepted',
        ]);
    }

    public function test_same_van_runtime_reassignment_cannot_mutate_order_owned_by_stale_van_assignment(): void
    {
        $actor = $this->vanActor();
        [$order, $orderVanAssignmentId, $vanId] = $this->assignedB2bOrder($actor, 'RUNTIME-STALE');

        $oldRuntimeAssignment = DB::table('van_assignments')
            ->where('representative_user_id', $actor->id)
            ->where('van_id', $vanId)
            ->where('status', 'active')
            ->first(['id']);
        $this->assertNotNull($oldRuntimeAssignment);

        DB::table('van_assignments')
            ->where('id', $oldRuntimeAssignment->id)
            ->update([
                'status' => 'ended',
                'effective_until' => now()->subSecond(),
                'updated_at' => now(),
            ]);

        $newRuntimeAssignmentId = (int) DB::table('van_assignments')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'van_id' => $vanId,
            'representative_user_id' => $actor->id,
            'assignment_type' => 'primary',
            'status' => 'active',
            'effective_from' => now()->subSecond(),
            'loaded_work_count' => 0,
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertNotSame((int) $oldRuntimeAssignment->id, $newRuntimeAssignmentId);

        Sanctum::actingAs($actor, ['app:van']);

        $this->transition($order->id, 'accepted', 'runtime-stale-01')
            ->assertNotFound();

        $this->assertDatabaseHas('order_van_assignments', [
            'id' => $orderVanAssignmentId,
            'van_assignment_id' => (int) $oldRuntimeAssignment->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('order_van_execution_states', [
            'order_van_assignment_id' => $orderVanAssignmentId,
            'status' => 'assigned',
        ]);
        $this->assertDatabaseCount('order_van_execution_events', 0);
    }

    private function transition(int $orderId, string $status, string $key)
    {
        return $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/van/orders/'.$orderId.'/execution/transition', [
                'status' => $status,
            ]);
    }

    /** @return array{0:Order,1:int,2:int} */
    private function assignedB2bOrder(User $actor, string $suffix): array
    {
        $storeId = $this->store('B2B', 'W05-'.$suffix.'-'.$actor->id);
        $legacy = Customer::query()->create([
            'name' => 'W05 Customer '.$suffix,
            'type' => 'b2b',
            'email' => strtolower($suffix).'-'.$actor->id.'@w05.example.test',
        ]);
        $b2bCustomerId = (int) DB::table('b2b_customers')->insertGetId([
            'legacy_customer_id' => $legacy->id,
            'name' => 'W05 Customer '.$suffix,
            'email' => strtolower($suffix).'-'.$actor->id.'@w05.example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $order = Order::query()->create([
            'store_id' => $storeId,
            'customer_id' => $legacy->id,
            'b2b_customer_id' => $b2bCustomerId,
            'order_number' => 'W05-'.$suffix.'-'.$actor->id,
            'channel' => 'b2b',
            'status' => 'pending',
            'currency' => 'EGP',
            'subtotal' => 20,
            'discount_total' => 0,
            'delivery_total' => 0,
            'tax_total' => 0,
            'grand_total' => 20,
            'payment_method' => 'cash_on_delivery',
        ]);

        $vanAssignment = DB::table('van_assignments')
            ->where('representative_user_id', $actor->id)
            ->where('status', 'active')
            ->first(['id', 'van_id']);
        $this->assertNotNull($vanAssignment);

        $assignmentId = (int) DB::table('order_van_assignments')->insertGetId([
            'order_id' => $order->id,
            'van_id' => $vanAssignment->van_id,
            'van_assignment_id' => $vanAssignment->id,
            'status' => 'active',
            'source' => 'smart_routing',
            'reason' => 'w05_test_fixture',
            'decision_key' => hash('sha256', 'w05-'.$suffix.'-'.$order->id.'-'.$vanAssignment->van_id),
            'assigned_by' => $actor->id,
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('order_van_execution_states')->insert([
            'order_van_assignment_id' => $assignmentId,
            'order_id' => $order->id,
            'van_id' => $vanAssignment->van_id,
            'status' => 'assigned',
            'version' => 1,
            'last_transition_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$order, $assignmentId, (int) $vanAssignment->van_id];
    }

    private function store(string $type, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $type)->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function vanActor(): User
    {
        $this->seed(CoreReferenceSeeder::class);
        $actor = User::factory()->create(['is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'VAN_OPERATOR')->firstOrFail());

        $registry = app(VanRegistryService::class);
        $van = $registry->createVan(['code' => 'W05-VAN-'.$actor->id]);
        $registry->assign($actor, $van, [
            'representative_user_id' => $actor->id,
            'assignment_type' => 'primary',
            'effective_from' => now()->subMinute(),
        ]);

        Sanctum::actingAs($actor, ['app:van']);

        return $actor;
    }
}
