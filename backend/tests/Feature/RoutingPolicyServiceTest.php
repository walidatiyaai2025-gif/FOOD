<?php

namespace Tests\Feature;

use App\Models\RoutingDecisionTrace;
use App\Models\User;
use App\Services\RoutingPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoutingPolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_simulation_is_deterministic_and_does_not_persist_a_decision_trace(): void
    {
        $user = User::factory()->create();
        $service = app(RoutingPolicyService::class);
        $policy = $service->createDraft($user, 'default', 'HYBRID', [
            ['name' => 'territory', 'conditions' => ['territory' => 'west'], 'actions' => ['warehouse' => 'WH-A', 'priority' => 20]],
            ['name' => 'urgent', 'conditions' => ['urgent' => true], 'actions' => ['priority' => 100, 'manual_review' => true]],
        ]);

        $first = $service->simulate($policy, ['territory' => 'west', 'urgent' => true]);
        $second = $service->simulate($policy, ['territory' => 'west', 'urgent' => true]);

        $this->assertSame($first, $second);
        $this->assertSame(['warehouse' => 'WH-A', 'priority' => 100, 'manual_review' => true], $first['result']);
        $this->assertDatabaseCount('routing_decision_traces', 0);
    }

    public function test_only_effective_published_policy_routes_production_and_trace_is_persisted(): void
    {
        $user = User::factory()->create();
        $service = app(RoutingPolicyService::class);
        $draft = $service->createDraft($user, 'default', 'AUTOMATIC', [
            ['name' => 'all', 'conditions' => [], 'actions' => ['warehouse' => 'WH-A']],
        ], from: '2026-10-10T00:00:00Z');
        $service->publish($user, $draft);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        try {
            $service->route('default', 'order', '1', [], '2026-10-09T00:00:00Z');
        } finally {
            $trace = $service->route('default', 'order', '1', [], '2026-10-10T00:00:00Z');
            $this->assertInstanceOf(RoutingDecisionTrace::class, $trace);
            $this->assertSame('WH-A', $trace->result['warehouse']);
        }
    }

    public function test_rollback_republishes_prior_rules_as_a_new_version(): void
    {
        $user = User::factory()->create();
        $service = app(RoutingPolicyService::class);
        $v1 = $service->publish($user, $service->createDraft($user, 'default', 'MANUAL', [
            ['name' => 'base', 'conditions' => [], 'actions' => ['warehouse' => 'WH-A']],
        ]));
        $service->publish($user, $service->createDraft($user, 'default', 'AUTOMATIC', [
            ['name' => 'base', 'conditions' => [], 'actions' => ['warehouse' => 'WH-B']],
        ]));

        $restored = $service->rollback($user, $v1, 'known-good');
        $this->assertSame(3, $restored->version);
        $this->assertSame('MANUAL', $restored->mode);
        $this->assertSame('WH-A', $service->simulate($restored, [])['result']['warehouse']);
    }
}
