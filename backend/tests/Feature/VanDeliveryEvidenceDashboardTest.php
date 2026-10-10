<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\B2bCustomerService;
use App\Services\VanRegistryService;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VanDeliveryEvidenceDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_and_serves_scoped_van_proof_failure_evidence(): void
    {
        Storage::fake('public');
        $this->seed(CoreReferenceSeeder::class);

        $storeId = app(WholesalePrincipal::class)->storeId();
        $customer = app(B2bCustomerService::class)->create([
            'name' => 'W07 Evidence Buyer',
        ]);
        $orderId = (int) DB::table('orders')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => (int) $customer->legacy_customer_id,
            'b2b_customer_id' => (int) $customer->getKey(),
            'order_number' => 'W07-VAN-EVIDENCE-1001',
            'channel' => 'b2b',
            'status' => 'failed',
            'currency' => 'KWD',
            'subtotal' => 20,
            'discount_total' => 0,
            'delivery_total' => 0,
            'tax_total' => 0,
            'grand_total' => 20,
            'payment_method' => 'cash_on_delivery',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $operator = User::factory()->create([
            'name' => 'W07 Van Operator',
            'is_active' => true,
        ]);
        $operator->roles()->attach(
            Role::query()->where('code', 'VAN_OPERATOR')->firstOrFail(),
        );

        $registry = app(VanRegistryService::class);
        $van = $registry->createVan(['code' => 'W07-EVIDENCE-VAN']);
        $registry->assign($operator, $van, [
            'representative_user_id' => $operator->id,
            'assignment_type' => 'primary',
            'effective_from' => now()->subMinute(),
        ]);
        $runtimeAssignment = DB::table('van_assignments')
            ->where('representative_user_id', $operator->id)
            ->where('van_id', $van->id)
            ->where('status', 'active')
            ->first(['id']);
        $this->assertNotNull($runtimeAssignment);

        $orderVanAssignmentId = (int) DB::table('order_van_assignments')->insertGetId([
            'order_id' => $orderId,
            'van_id' => $van->id,
            'van_assignment_id' => $runtimeAssignment->id,
            'status' => 'active',
            'source' => 'smart_routing',
            'reason' => 'w07_dashboard_evidence',
            'decision_key' => hash('sha256', 'w07-dashboard-'.$orderId),
            'assigned_by' => $operator->id,
            'assigned_at' => now()->subMinutes(15),
            'created_at' => now()->subMinutes(15),
            'updated_at' => now(),
        ]);

        $stateId = (int) DB::table('order_van_execution_states')->insertGetId([
            'order_van_assignment_id' => $orderVanAssignmentId,
            'order_id' => $orderId,
            'van_id' => $van->id,
            'status' => 'failed',
            'failure_reason_code' => 'other',
            'failure_note' => 'W07 gate was locked',
            'version' => 4,
            'last_transition_at' => now()->subMinute(),
            'created_at' => now()->subMinutes(15),
            'updated_at' => now(),
        ]);

        $proofPath = 'delivery-proofs/van/w07-dashboard-proof.jpg';
        Storage::disk('public')->put($proofPath, 'w07-proof');
        $proofEventId = (int) DB::table('order_van_execution_events')->insertGetId([
            'order_van_assignment_id' => $orderVanAssignmentId,
            'order_van_execution_state_id' => $stateId,
            'order_id' => $orderId,
            'van_id' => $van->id,
            'user_id' => $operator->id,
            'action' => 'transition',
            'idempotency_key' => 'w07-dashboard-failed',
            'request_fingerprint' => hash('sha256', 'w07-dashboard-failed'),
            'from_status' => 'out_for_delivery',
            'to_status' => 'failed',
            'proof_type' => 'failure_image',
            'proof_path' => $proofPath,
            'reason_code' => 'other',
            'note' => 'W07 gate was locked',
            'captured_at' => now()->subMinute(),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $admin = User::factory()->create([
            'name' => 'W07 Operations Owner',
            'email' => 'w07-dashboard-owner@example.test',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $admin->roles()->attach(
            Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail(),
        );

        $this->actingAs($admin)
            ->get('/admin/operations/orders?channel=b2b&order='.$orderId)
            ->assertOk()
            ->assertSee('data-van-delivery-evidence', false)
            ->assertSee('data-van-delivery-evidence-assignment="'.$orderVanAssignmentId.'"', false)
            ->assertSee('W07-EVIDENCE-VAN')
            ->assertSee('Van delivery timeline &amp; proof', false)
            ->assertSee('W07 gate was locked')
            ->assertSee('data-van-delivery-proof-link', false);

        $proofResponse = $this->actingAs($admin)
            ->get('/admin/operations/orders/van-assignments/'.$orderVanAssignmentId.'/proofs/'.$proofEventId)
            ->assertOk();

        $cacheControl = (string) $proofResponse->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('max-age=0', $cacheControl);

        $foreign = User::factory()->create(['is_active' => true]);
        $this->actingAs($foreign)
            ->get('/admin/operations/orders/van-assignments/'.$orderVanAssignmentId.'/proofs/'.$proofEventId)
            ->assertStatus(404);
    }
}
