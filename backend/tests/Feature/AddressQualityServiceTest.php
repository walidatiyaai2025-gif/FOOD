<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AddressQualityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddressQualityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_unmapped_address_enters_actionable_queue_without_guessing_territory(): void
    {
        $service = app(AddressQualityService::class);

        $review = $service->queue('customer_address', 10, [
            'quality_class' => 'low_confidence',
            'confidence' => 0.30,
            'reason' => 'coordinates unavailable',
        ]);

        $this->assertSame('unmapped', $review->status);
        $this->assertNull($review->territory_key);
        $this->assertSame('unresolved', $review->resolution_source);
    }

    public function test_admin_confirmed_mapping_is_deterministic_and_audited(): void
    {
        $actor = User::factory()->create();
        $service = app(AddressQualityService::class);
        $review = $service->queue('customer_address', 10);

        $confirmed = $service->confirm($actor, $review, 'territory-alex-west', 'verified with customer');

        $this->assertSame('confirmed', $confirmed->status);
        $this->assertSame('territory-alex-west', $confirmed->territory_key);
        $this->assertSame('admin_confirmed', $confirmed->resolution_source);
        $this->assertDatabaseHas('address_quality_review_events', [
            'address_quality_review_id' => $review->id,
            'event_type' => 'confirmed',
            'new_territory_key' => 'territory-alex-west',
            'actor_id' => $actor->id,
        ]);
    }

    public function test_requeue_does_not_silently_create_a_second_review_after_resolution(): void
    {
        $actor = User::factory()->create();
        $service = app(AddressQualityService::class);
        $review = $service->queue('customer_address', 10);

        $service->confirm($actor, $review, 'territory-a', 'verified');

        $again = $service->queue('customer_address', 10, [
            'quality_class' => 'low_confidence',
            'confidence' => 0.20,
            'reason' => 'new ingestion signal',
        ]);

        $this->assertSame($review->id, $again->id);
        $this->assertSame('confirmed', $again->status);
        $this->assertSame('territory-a', $again->territory_key);
        $this->assertDatabaseCount('address_quality_reviews', 1);
    }

    public function test_reopen_preserves_correction_history_without_silent_rewrite(): void
    {
        $actor = User::factory()->create();
        $service = app(AddressQualityService::class);
        $review = $service->queue('customer_address', 10);

        $service->confirm($actor, $review, 'territory-a', 'first verification');
        $reopened = $service->reopen($actor, $review->fresh(), 'new contradictory evidence');

        $this->assertSame('unmapped', $reopened->status);
        $this->assertNull($reopened->territory_key);
        $this->assertDatabaseCount('address_quality_review_events', 2);
        $this->assertDatabaseHas('address_quality_review_events', [
            'event_type' => 'confirmed',
            'new_territory_key' => 'territory-a',
        ]);
    }
}
