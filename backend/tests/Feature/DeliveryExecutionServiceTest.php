<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\DeliveryExecutionService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DeliveryExecutionServiceTest extends TestCase
{
    public function test_shared_transition_contract_supports_driver_and_van_execution_states(): void
    {
        $service = app(DeliveryExecutionService::class);
        $order = new Order(['status' => 'ready']);

        $this->assertSame(['accepted'], $service->availableStatuses('assigned', $order));
        $this->assertSame(['picked_up', 'failed'], $service->availableStatuses('accepted', $order));
        $this->assertSame(['out_for_delivery', 'failed'], $service->availableStatuses('picked_up', $order));

        $order->status = 'out_for_delivery';
        $this->assertSame(['delivered', 'failed'], $service->availableStatuses('out_for_delivery', $order));

        $order->status = 'failed';
        $this->assertSame(['out_for_delivery'], $service->availableStatuses('failed', $order));
        $this->assertSame([], $service->availableStatuses('failed', $order, completed: true));

        $order->status = 'delivered';
        $this->assertSame([], $service->availableStatuses('out_for_delivery', $order));
    }

    public function test_shared_idempotency_fingerprint_is_stable_and_rejects_changed_requests(): void
    {
        $service = app(DeliveryExecutionService::class);

        $first = $service->transitionFingerprint('accepted', 'same note', null, null);
        $replay = $service->transitionFingerprint('accepted', 'same note', null, null);
        $changed = $service->transitionFingerprint('accepted', 'changed note', null, null);

        $this->assertSame($first, $replay);
        $this->assertNotSame($first, $changed);

        $service->assertReplayMatches('accepted', $first, 'accepted', $replay);

        try {
            $service->assertReplayMatches('accepted', $first, 'accepted', $changed);
            $this->fail('Changed delivery requests must not replay under the same idempotency key.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
    }

    public function test_shared_normalization_keeps_failure_rules_actor_neutral(): void
    {
        $service = app(DeliveryExecutionService::class);

        $this->assertNull($service->normalizeNote('   '));
        $this->assertSame('left with customer', $service->normalizeNote(' left with customer '));
        $this->assertNull($service->normalizeFailureReason('accepted', 'wrong_address'));
        $this->assertSame('wrong_address', $service->normalizeFailureReason('failed', ' wrong_address '));
        $this->assertNull($service->normalizeIdempotencyKey(''));
        $this->assertSame('delivery-123', $service->normalizeIdempotencyKey(' delivery-123 '));
    }
}
