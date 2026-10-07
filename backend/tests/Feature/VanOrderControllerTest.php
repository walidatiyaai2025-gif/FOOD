<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VanVisit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VanOrderControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_van_catalog_rejects_customer_outside_actor_visit_scope(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($actor);

        $this->getJson('/api/v1/van/customers/b2b/999/catalog?store_id=7')
            ->assertNotFound();
    }

    public function test_van_order_creation_requires_idempotency_key_before_checkout(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($actor);

        VanVisit::query()->create([
            'actor_user_id' => $actor->id,
            'customer_type' => 'b2b',
            'customer_id' => 999,
            'store_id' => 7,
            'status' => 'planned',
        ]);

        $this->postJson('/api/v1/van/customers/b2b/999/orders', [
            'store_id' => 7,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['Idempotency-Key']);
    }

    public function test_van_order_feed_is_empty_without_actor_customer_scope(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($actor);

        $this->getJson('/api/v1/van/orders')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }
}
