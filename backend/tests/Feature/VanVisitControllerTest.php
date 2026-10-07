<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VanVisit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VanVisitControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_actor_visit_feed_exposes_canonical_route_context_from_metadata(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($actor);

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
        $actor = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($actor);

        VanVisit::query()->create([
            'actor_user_id' => $actor->id,
            'customer_type' => 'b2c',
            'customer_id' => 43,
            'store_id' => 8,
            'status' => 'planned',
            'metadata' => ['route_code' => 'ROUTE-LEGACY'],
        ]);

        $this->getJson('/api/v1/van/visits')
            ->assertOk()
            ->assertJsonPath('data.0.route_key', 'ROUTE-LEGACY');
    }
}
