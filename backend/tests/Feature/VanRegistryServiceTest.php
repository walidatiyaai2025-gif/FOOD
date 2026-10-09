<?php

namespace Tests\Feature;

use App\Models\GeographyNode;
use App\Models\OrderDispatchState;
use App\Models\OrderVanAssignment;
use App\Models\ServiceTerritory;
use App\Models\User;
use App\Models\VanAssignment;
use App\Models\VanVisit;
use App\Services\VanRegistryService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class VanRegistryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_assignments_are_effective_dated_and_history_is_not_rewritten(): void
    {
        $actor = User::factory()->create();
        $this->territories(['north', 'south']);
        $service = app(VanRegistryService::class);
        $van = $service->createVan(['code' => 'VAN-A']);

        $past = $service->assign($actor, $van, [
            'assignment_type' => 'primary',
            'effective_from' => '2026-01-01T00:00:00Z',
            'effective_until' => '2026-02-01T00:00:00Z',
            'territory_key' => 'north',
        ]);
        $current = $service->assign($actor, $van, [
            'assignment_type' => 'primary',
            'effective_from' => '2026-02-01T00:00:00Z',
            'territory_key' => 'south',
        ]);

        $this->assertSame('north', $past->fresh()->territory_key);
        $this->assertSame('south', $current->fresh()->territory_key);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'van.assignment.created',
            'user_id' => $actor->id,
            'auditable_type' => VanAssignment::class,
            'auditable_id' => $current->id,
        ]);
        $this->assertCount(1, $service->effectiveAssignments('2026-01-15T00:00:00Z'));
        $this->assertSame('north', $service->effectiveAssignments('2026-01-15T00:00:00Z')->first()->territory_key);
        $this->assertSame('south', $service->effectiveAssignments('2026-03-01T00:00:00Z')->first()->territory_key);
    }

    public function test_overlapping_primary_assignments_are_rejected(): void
    {
        $actor = User::factory()->create();
        $service = app(VanRegistryService::class);
        $van = $service->createVan(['code' => 'VAN-A']);

        $service->assign($actor, $van, [
            'assignment_type' => 'primary',
            'effective_from' => '2026-01-01T00:00:00Z',
        ]);

        $this->expectException(ValidationException::class);
        $service->assign($actor, $van, [
            'assignment_type' => 'primary',
            'effective_from' => '2026-01-02T00:00:00Z',
        ]);
    }

    public function test_primary_territory_cannot_be_owned_by_two_vans_for_overlapping_periods(): void
    {
        $actor = User::factory()->create();
        $this->territories(['north']);
        $service = app(VanRegistryService::class);
        $first = $service->createVan(['code' => 'VAN-A']);
        $second = $service->createVan(['code' => 'VAN-B']);

        $service->assign($actor, $first, [
            'assignment_type' => 'primary',
            'effective_from' => '2026-01-01T00:00:00Z',
            'territory_key' => 'north',
        ]);

        $this->expectException(ValidationException::class);
        $service->assign($actor, $second, [
            'assignment_type' => 'primary',
            'effective_from' => '2026-01-02T00:00:00Z',
            'territory_key' => 'north',
        ]);
    }

    public function test_unknown_territory_key_is_rejected(): void
    {
        $actor = User::factory()->create();
        $service = app(VanRegistryService::class);
        $van = $service->createVan(['code' => 'VAN-A']);

        $this->expectException(ValidationException::class);
        $service->assign($actor, $van, [
            'assignment_type' => 'primary',
            'effective_from' => '2026-01-01T00:00:00Z',
            'territory_key' => 'missing-territory',
        ]);
    }

    public function test_loaded_work_requires_explicit_transfer_before_suspension(): void
    {
        $actor = User::factory()->create();
        $service = app(VanRegistryService::class);
        $source = $service->createVan(['code' => 'VAN-A']);
        $target = $service->createVan(['code' => 'VAN-B']);

        $assignment = $service->assign($actor, $source, [
            'assignment_type' => 'primary',
            'effective_from' => '2026-01-01T00:00:00Z',
            'loaded_work_count' => 3,
        ]);

        try {
            $service->suspend($source);
            $this->fail('Suspension should require an explicit transfer target.');
        } catch (ValidationException) {
            $this->assertSame('active', $source->fresh()->status);
            $this->assertSame('active', $assignment->fresh()->status);
        }

        $service->suspend($source, $target, 'vehicle maintenance');

        $this->assertSame('suspended', $source->fresh()->status);
        $this->assertSame('ended', $assignment->fresh()->status);
        $this->assertSame($target->id, $assignment->fresh()->transferred_to_van_id);
        $this->assertSame('vehicle maintenance', $assignment->fresh()->transfer_reason);
    }

    public function test_assignment_can_be_edited_without_rewriting_other_assignment_history(): void
    {
        $actor = User::factory()->create();
        $this->territories(['north', 'south']);
        $service = app(VanRegistryService::class);
        $van = $service->createVan(['code' => 'VAN-EDIT']);

        $assignment = $service->assign($actor, $van, [
            'assignment_type' => 'primary',
            'effective_from' => '2026-01-01T00:00:00Z',
            'effective_until' => '2026-02-01T00:00:00Z',
            'territory_key' => 'north',
        ]);

        $updated = $service->updateAssignment($actor, $assignment, $van, [
            'assignment_type' => 'primary',
            'effective_from' => '2026-01-02T00:00:00Z',
            'effective_until' => '2026-02-02T00:00:00Z',
            'territory_key' => 'south',
            'loaded_work_count' => 4,
        ]);

        $this->assertSame('south', $updated->territory_key);
        $this->assertSame(4, (int) $updated->loaded_work_count);
        $this->assertSame('2026-01-02 00:00:00', $updated->effective_from->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'van.assignment.updated',
            'user_id' => $actor->id,
            'auditable_type' => VanAssignment::class,
            'auditable_id' => $assignment->id,
        ]);
    }

    public function test_delete_assignment_purges_only_assignment_owned_operations_and_preserves_core_order(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $actor = User::factory()->create();
        $operator = User::factory()->create();
        $service = app(VanRegistryService::class);
        $van = $service->createVan(['code' => 'VAN-PURGE']);

        $assignment = $service->assign($actor, $van, [
            'representative_user_id' => $operator->id,
            'assignment_type' => 'primary',
            'effective_from' => now()->subDays(2),
            'effective_until' => now()->addDays(2),
        ]);
        $otherAssignment = $service->assign($actor, $van, [
            'representative_user_id' => $operator->id,
            'assignment_type' => 'backup',
            'effective_from' => now()->subDay(),
            'effective_until' => now()->addDays(3),
        ]);

        $ownedVisit = VanVisit::query()->create([
            'actor_user_id' => $operator->id,
            'customer_type' => 'b2b',
            'customer_id' => 101,
            'status' => 'planned',
            'metadata' => [
                'van_id' => $van->id,
                'van_assignment_id' => $assignment->id,
            ],
        ]);
        $otherVisit = VanVisit::query()->create([
            'actor_user_id' => $operator->id,
            'customer_type' => 'b2b',
            'customer_id' => 102,
            'status' => 'planned',
            'metadata' => [
                'van_id' => $van->id,
                'van_assignment_id' => $otherAssignment->id,
            ],
        ]);

        $storeTypeId = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => 'VAN-PURGE-B2B',
            'name' => 'Van Purge Wholesale',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customerId = (int) DB::table('customers')->insertGetId([
            'type' => 'b2b',
            'name' => 'Van Purge Customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orderId = (int) DB::table('orders')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => $customerId,
            'order_number' => 'VAN-PURGE-ORDER',
            'channel' => 'b2b',
            'status' => 'pending',
            'currency' => 'EGP',
            'subtotal' => 10,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $decisionKey = hash('sha256', 'van-purge-decision');
        $orderLink = OrderVanAssignment::query()->create([
            'order_id' => $orderId,
            'van_id' => $van->id,
            'van_assignment_id' => $assignment->id,
            'status' => 'active',
            'source' => 'test',
            'reason' => 'test',
            'decision_key' => $decisionKey,
            'assigned_by' => $actor->id,
            'assigned_at' => now(),
        ]);
        OrderDispatchState::query()->create([
            'order_id' => $orderId,
            'status' => 'assigned',
            'routing_source' => 'manual_customer_service',
            'routing_reason' => 'test',
            'current_assignee_type' => 'van',
            'current_assignee_id' => $van->id,
            'decision_key' => $decisionKey,
            'context' => ['van_assignment_id' => $assignment->id],
            'decided_at' => now(),
        ]);

        $summary = $service->deleteAssignment($actor, $assignment);

        $this->assertSame(1, $summary['visits']);
        $this->assertSame(1, $summary['order_van_assignments']);
        $this->assertSame(1, $summary['dispatch_states_reset']);
        $this->assertDatabaseMissing('van_assignments', ['id' => $assignment->id]);
        $this->assertDatabaseMissing('van_visits', ['id' => $ownedVisit->id]);
        $this->assertDatabaseHas('van_visits', ['id' => $otherVisit->id]);
        $this->assertDatabaseMissing('order_van_assignments', ['id' => $orderLink->id]);
        $this->assertDatabaseHas('orders', ['id' => $orderId]);
        $this->assertDatabaseHas('order_dispatch_states', [
            'order_id' => $orderId,
            'status' => 'awaiting_dispatch',
            'current_assignee_type' => null,
            'current_assignee_id' => null,
        ]);
        $this->assertDatabaseHas('van_assignments', ['id' => $otherAssignment->id]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'van.assignment.deleted',
            'user_id' => $actor->id,
            'auditable_type' => VanAssignment::class,
            'auditable_id' => $assignment->id,
        ]);
    }

    /** @param list<string> $codes */
    private function territories(array $codes): void
    {
        $country = GeographyNode::query()->create([
            'type' => 'country',
            'code' => 'registry-test-country',
            'name_ar' => 'مصر',
            'name_en' => 'Egypt',
            'country_code' => 'EGT',
            'is_active' => true,
        ]);

        foreach ($codes as $code) {
            ServiceTerritory::query()->create([
                'code' => $code,
                'name_ar' => $code,
                'name_en' => $code,
                'country_node_id' => $country->id,
                'status' => 'active',
                'effective_from' => '2025-01-01 00:00:00',
            ]);
        }
    }
}
