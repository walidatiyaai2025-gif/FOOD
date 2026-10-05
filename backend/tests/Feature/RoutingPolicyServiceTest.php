<?php

namespace Tests\Feature;

use App\Models\RoutingDecisionTrace;
use App\Models\User;
use App\Services\FieldOperationConfigurationService;
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
        $this->assertSame('HYBRID', $first['routing_mode']);
        $this->assertSame('policy_fallback', $first['mode_resolution']['source']);
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
            $this->assertSame('AUTOMATIC', $trace->routing_mode);
            $this->assertSame('policy_fallback', $trace->mode_resolution['source']);
        }
    }

    public function test_publish_retires_previous_version_and_rollback_republishes_prior_rules_as_a_new_version(): void
    {
        $user = User::factory()->create();
        $service = app(RoutingPolicyService::class);
        $v1 = $service->publish($user, $service->createDraft($user, 'default', 'MANUAL', [
            ['name' => 'base', 'conditions' => [], 'actions' => ['warehouse' => 'WH-A']],
        ]));
        $v2 = $service->publish($user, $service->createDraft($user, 'default', 'AUTOMATIC', [
            ['name' => 'base', 'conditions' => [], 'actions' => ['warehouse' => 'WH-B']],
        ]));

        $this->assertSame(RoutingPolicyService::RETIRED, $v1->fresh()->status);
        $this->assertSame(RoutingPolicyService::PUBLISHED, $v2->fresh()->status);

        $restored = $service->rollback($user, $v1, 'known-good');

        $this->assertSame(3, $restored->version);
        $this->assertSame('MANUAL', $restored->mode);
        $this->assertSame('WH-A', $service->simulate($restored, [])['result']['warehouse']);
        $this->assertSame(RoutingPolicyService::RETIRED, $v2->fresh()->status);
    }

    public function test_control_plane_mode_overrides_policy_fallback_for_scoped_simulation(): void
    {
        $user = User::factory()->create();
        $configuration = app(FieldOperationConfigurationService::class);
        $configuration->upsertDefinition(
            key: 'routing.mode',
            valueType: 'string',
            defaultValue: 'MANUAL',
            failurePolicy: 'degrade_safe',
            validationSchema: ['allowed_values' => ['MANUAL', 'AUTOMATIC', 'HYBRID']],
        );
        $configuration->publish(
            $user,
            $configuration->createDraft($user, 'routing.mode', 'territory', 'alex-west', 'AUTOMATIC'),
        );

        $service = app(RoutingPolicyService::class);
        $policy = $service->createDraft($user, 'default', 'MANUAL', [
            ['name' => 'base', 'conditions' => [], 'actions' => ['warehouse' => 'WH-A']],
        ]);

        $decision = $service->simulate(
            $policy,
            [],
            ['country' => 'EG', 'territory' => 'alex-west'],
            '2026-10-05T12:00:00Z',
        );

        $this->assertSame('AUTOMATIC', $decision['routing_mode']);
        $this->assertSame('published_revision', $decision['mode_resolution']['source']);
        $this->assertSame('territory', $decision['mode_resolution']['scope_type']);
    }

    public function test_batch_simulation_is_read_only_and_preserves_deterministic_input_order(): void
    {
        $user = User::factory()->create();
        $service = app(RoutingPolicyService::class);
        $policy = $service->createDraft($user, 'default', 'HYBRID', [
            ['name' => 'west', 'conditions' => ['territory' => 'west'], 'actions' => ['warehouse' => 'WH-W']],
            ['name' => 'east', 'conditions' => ['territory' => 'east'], 'actions' => ['warehouse' => 'WH-E']],
        ]);

        $batch = $service->simulateBatch($policy, [
            ['territory' => 'east'],
            ['territory' => 'west'],
        ], at: '2027-01-01T00:00:00Z');

        $this->assertCount(2, $batch);
        $this->assertSame('WH-E', $batch[0]['result']['warehouse']);
        $this->assertSame('WH-W', $batch[1]['result']['warehouse']);
        $this->assertDatabaseCount('routing_decision_traces', 0);
    }
}
