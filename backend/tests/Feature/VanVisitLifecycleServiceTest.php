<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VanNoOrderReason;
use App\Models\VanVisit;
use App\Services\VanVisitLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class VanVisitLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_visit_lifecycle_requires_authoritative_order_for_completed_with_order(): void
    {
        $actor = User::factory()->create();
        $visit = VanVisit::query()->create([
            'actor_user_id' => $actor->id,
            'customer_type' => 'b2b',
            'customer_id' => 101,
            'status' => 'started',
            'started_at' => now(),
        ]);

        $this->expectException(ValidationException::class);

        app(VanVisitLifecycleService::class)->transition(
            $visit,
            'completed_with_order',
            $actor,
        );
    }

    public function test_no_order_completion_requires_active_configured_reason(): void
    {
        $actor = User::factory()->create();
        $visit = VanVisit::query()->create([
            'actor_user_id' => $actor->id,
            'customer_type' => 'b2b',
            'customer_id' => 102,
            'status' => 'started',
            'started_at' => now(),
        ]);

        $reason = VanNoOrderReason::query()->create([
            'code' => 'customer_declined',
            'label_en' => 'Customer declined',
            'label_ar' => 'رفض العميل',
            'is_active' => true,
        ]);

        $updated = app(VanVisitLifecycleService::class)->transition(
            $visit,
            'completed_no_order',
            $actor,
            noOrderReasonId: $reason->id,
        );

        $this->assertSame('completed_no_order', $updated->status);
        $this->assertSame($reason->id, $updated->no_order_reason_id);
        $this->assertNotNull($updated->completed_at);
    }

    public function test_same_transition_is_idempotent(): void
    {
        $actor = User::factory()->create();
        $visit = VanVisit::query()->create([
            'actor_user_id' => $actor->id,
            'customer_type' => 'b2b',
            'customer_id' => 103,
            'status' => 'planned',
        ]);

        $service = app(VanVisitLifecycleService::class);
        $started = $service->transition($visit, 'started', $actor);
        $startedAgain = $service->transition($started, 'started', $actor);

        $this->assertSame($started->id, $startedAgain->id);
        $this->assertSame('started', $startedAgain->status);
        $this->assertNotNull($startedAgain->started_at);
    }

    public function test_closed_visit_cannot_reopen_silently(): void
    {
        $actor = User::factory()->create();
        $visit = VanVisit::query()->create([
            'actor_user_id' => $actor->id,
            'customer_type' => 'b2b',
            'customer_id' => 104,
            'status' => 'closed',
            'closed_at' => now(),
        ]);

        $this->expectException(ValidationException::class);

        app(VanVisitLifecycleService::class)->transition($visit, 'started', $actor);
    }
}
