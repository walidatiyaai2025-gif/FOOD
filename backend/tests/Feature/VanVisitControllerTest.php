<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\VanVisit;
use App\Services\VanRegistryService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
