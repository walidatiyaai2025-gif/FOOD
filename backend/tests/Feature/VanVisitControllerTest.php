<?php

namespace Tests\Feature;

use App\Models\B2bCustomer;
use App\Models\Role;
use App\Models\User;
use App\Models\VanVisit;
use App\Services\VanRegistryService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VanVisitControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_actor_visit_feed_exposes_canonical_route_context_from_metadata(): void
    {
        $actor = $this->vanActor();

        VanVisit::query()->create([
            'actor_user_id' => $actor->id,
            'customer_type' => 'b2b',
            'customer_id' => 42,
            'store_id' => 7,
            'status' => 'planned',
            'metadata' => ['route_key' => 'ROUTE-A'],
        ]);

        $this->getJson('/api/v1/van/visits')
            ->assertOk()
            ->assertJsonPath('data.0.route_key', 'ROUTE-A')
            ->assertJsonPath('data.0.status', 'planned')
            ->assertJsonPath('data.0.customer_type', 'b2b')
            ->assertJsonPath('data.0.customer_id', 42);
    }

    public function test_actor_visit_feed_falls_back_to_legacy_route_metadata_keys(): void
    {
        $actor = $this->vanActor();

        VanVisit::query()->create([
            'actor_user_id' => $actor->id,
            'customer_type' => 'b2b',
            'customer_id' => 43,
            'store_id' => 8,
            'status' => 'planned',
            'metadata' => ['route_code' => 'ROUTE-LEGACY'],
        ]);

        $this->getJson('/api/v1/van/visits')
            ->assertOk()
            ->assertJsonPath('data.0.route_key', 'ROUTE-LEGACY');
    }

    public function test_van_visit_routes_reject_b2c_and_hide_legacy_b2c_visits_even_for_the_same_actor(): void
    {
        $actor = $this->vanActor();

        $b2b = VanVisit::query()->create([
            'actor_user_id' => $actor->id,
            'customer_type' => 'b2b',
            'customer_id' => 42,
            'store_id' => 7,
            'status' => 'planned',
        ]);
        $legacyB2c = VanVisit::query()->create([
            'actor_user_id' => $actor->id,
            'customer_type' => 'b2c',
            'customer_id' => 43,
            'store_id' => 8,
            'status' => 'planned',
        ]);

        $this->getJson('/api/v1/van/visits')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $b2b->id)
            ->assertJsonPath('data.0.customer_type', 'b2b')
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/van/customers/b2c/43')->assertNotFound();
        $this->postJson('/api/v1/van/visits/'.$legacyB2c->id.'/transition', [
            'status' => 'started',
        ])->assertNotFound();

        $this->postJson('/api/v1/van/visits', [
            'customer_type' => 'b2c',
            'customer_id' => 43,
            'idempotency_key' => 'v2-denied-van-b2c',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_type']);

        $this->assertDatabaseHas('van_visits', [
            'id' => $legacyB2c->id,
            'status' => 'planned',
        ]);
        $this->assertDatabaseMissing('van_visits', [
            'idempotency_key' => 'v2-denied-van-b2c',
        ]);
    }

    public function test_reassigned_operator_cannot_read_or_transition_earlier_vans_attributed_b2b_visits(): void
    {
        $actor = $this->vanActor();
        $currentVanId = (int) DB::table('van_assignments')
            ->where('representative_user_id', $actor->id)
            ->value('van_id');
        $this->assertGreaterThan(0, $currentVanId);

        $current = VanVisit::query()->create([
            'actor_user_id' => $actor->id,
            'customer_type' => 'b2b',
            'customer_id' => 42,
            'status' => 'planned',
            'metadata' => ['van_id' => $currentVanId],
        ]);
        $previous = VanVisit::query()->create([
            'actor_user_id' => $actor->id,
            'customer_type' => 'b2b',
            'customer_id' => 43,
            'status' => 'planned',
            'metadata' => ['van_id' => $currentVanId + 99],
        ]);

        $this->getJson('/api/v1/van/visits')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $current->id);

        $this->postJson('/api/v1/van/visits/'.$previous->id.'/transition', [
            'status' => 'started',
        ])->assertNotFound();

        $this->assertDatabaseHas('van_visits', [
            'id' => $previous->id,
            'status' => 'planned',
        ]);
    }

    public function test_same_van_new_assignment_cannot_reopen_previous_assignment_visits(): void
    {
        $actor = $this->vanActor();
        $assignment = DB::table('van_assignments')
            ->where('representative_user_id', $actor->id)
            ->first(['id', 'van_id']);
        $this->assertNotNull($assignment);

        $current = VanVisit::query()->create([
            'actor_user_id' => $actor->id,
            'customer_type' => 'b2b',
            'customer_id' => 42,
            'status' => 'planned',
            'metadata' => [
                'van_id' => (int) $assignment->van_id,
                'van_assignment_id' => (int) $assignment->id,
            ],
        ]);
        $prior = VanVisit::query()->create([
            'actor_user_id' => $actor->id,
            'customer_type' => 'b2b',
            'customer_id' => 43,
            'status' => 'planned',
            'metadata' => [
                'van_id' => (int) $assignment->van_id,
                'van_assignment_id' => (int) $assignment->id + 100,
            ],
        ]);

        $this->getJson('/api/v1/van/visits')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $current->id);

        $this->postJson('/api/v1/van/visits/'.$prior->id.'/transition', [
            'status' => 'started',
        ])->assertNotFound();

        $this->assertDatabaseHas('van_visits', [
            'id' => $prior->id,
            'status' => 'planned',
        ]);
    }

    public function test_visit_create_idempotency_never_replays_an_unrelated_or_out_of_scope_visit(): void
    {
        $actor = $this->vanActor();
        $buyer = B2bCustomer::query()->create(['name' => 'Wholesale buyer']);
        $otherBuyer = B2bCustomer::query()->create(['name' => 'Other wholesale buyer']);
        $assignment = DB::table('van_assignments')
            ->where('representative_user_id', $actor->id)
            ->first(['id', 'van_id']);
        $this->assertNotNull($assignment);

        $legacyRetail = VanVisit::query()->create([
            'actor_user_id' => $actor->id,
            'customer_type' => 'b2c',
            'customer_id' => 123,
            'status' => 'planned',
            'idempotency_key' => 'old-retail-replay',
        ]);
        $this->postJson('/api/v1/van/visits', [
            'customer_type' => 'b2b',
            'customer_id' => $buyer->id,
            'idempotency_key' => 'old-retail-replay',
        ])->assertStatus(409);

        $authorized = VanVisit::query()->create([
            'actor_user_id' => $actor->id,
            'customer_type' => 'b2b',
            'customer_id' => $buyer->id,
            'status' => 'planned',
            'idempotency_key' => 'valid-wholesale-replay',
            'metadata' => [
                'van_id' => (int) $assignment->van_id,
                'van_assignment_id' => (int) $assignment->id,
            ],
        ]);
        $this->postJson('/api/v1/van/visits', [
            'customer_type' => 'b2b',
            'customer_id' => $buyer->id,
            'idempotency_key' => 'valid-wholesale-replay',
        ])->assertCreated()->assertJsonPath('id', $authorized->id);

        $this->postJson('/api/v1/van/visits', [
            'customer_type' => 'b2b',
            'customer_id' => $otherBuyer->id,
            'idempotency_key' => 'valid-wholesale-replay',
        ])->assertStatus(409);

        $priorAssignment = VanVisit::query()->create([
            'actor_user_id' => $actor->id,
            'customer_type' => 'b2b',
            'customer_id' => $buyer->id,
            'status' => 'planned',
            'idempotency_key' => 'prior-assignment-replay',
            'metadata' => [
                'van_id' => (int) $assignment->van_id,
                'van_assignment_id' => (int) $assignment->id + 100,
            ],
        ]);
        $this->postJson('/api/v1/van/visits', [
            'customer_type' => 'b2b',
            'customer_id' => $buyer->id,
            'idempotency_key' => 'prior-assignment-replay',
        ])->assertStatus(409);

        $this->assertDatabaseHas('van_visits', ['id' => $legacyRetail->id, 'status' => 'planned']);
        $this->assertDatabaseHas('van_visits', ['id' => $priorAssignment->id, 'status' => 'planned']);
        $this->assertSame(3, VanVisit::query()->count());
    }

    private function vanActor(): User
    {
        $this->seed(CoreReferenceSeeder::class);
        $actor = User::factory()->create(['is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'VAN_OPERATOR')->firstOrFail());

        $registry = app(VanRegistryService::class);
        $van = $registry->createVan(['code' => 'VISIT-VAN-'.$actor->id]);
        $registry->assign($actor, $van, [
            'representative_user_id' => $actor->id,
            'assignment_type' => 'primary',
            'effective_from' => now()->subMinute(),
        ]);

        Sanctum::actingAs($actor, ['app:van']);

        return $actor;
    }
}
