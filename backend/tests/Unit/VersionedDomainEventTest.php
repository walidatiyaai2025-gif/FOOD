<?php

namespace Tests\Unit;

use App\Enterprise\Events\DomainEventContractRegistry;
use App\Enterprise\Events\DomainEventSerializer;
use App\Enterprise\Events\VersionedDomainEvent;
use LogicException;
use Tests\TestCase;

final class VersionedDomainEventTest extends TestCase
{
    public function test_event_round_trip_preserves_identity_version_scope_and_provenance(): void
    {
        config()->set('enterprise_events.contracts.OrderConfirmed', [1]);

        $event = VersionedDomainEvent::create(
            type: 'OrderConfirmed',
            version: 1,
            scope: [
                'organization_id' => 10,
                'store_id' => 21,
                'channel' => 'b2b',
            ],
            payload: ['order_id' => 99, 'total' => '125.500'],
            correlationId: 'corr-123',
            causationId: 'cause-456',
        );

        $serializer = app(DomainEventSerializer::class);
        $decoded = $serializer->deserialize($serializer->serialize($event));

        $this->assertSame($event->id, $decoded->id);
        $this->assertSame('OrderConfirmed', $decoded->type);
        $this->assertSame(1, $decoded->version);
        $this->assertSame($event->scope, $decoded->scope);
        $this->assertSame($event->payload, $decoded->payload);
        $this->assertSame('corr-123', $decoded->correlationId);
        $this->assertSame('cause-456', $decoded->causationId);
    }

    public function test_unknown_or_incompatible_event_version_fails_closed(): void
    {
        config()->set('enterprise_events.contracts.OrderConfirmed', [1]);

        $event = VersionedDomainEvent::create(
            type: 'OrderConfirmed',
            version: 2,
            scope: ['store_id' => 1],
            payload: ['order_id' => 5],
        );

        $this->expectException(LogicException::class);
        app(DomainEventContractRegistry::class)->assertSupported($event);
    }

    public function test_supported_versions_are_normalized_and_explicit(): void
    {
        config()->set('enterprise_events.contracts.InventoryAdjusted', [2, '1', 2, 0]);

        $registry = app(DomainEventContractRegistry::class);

        $this->assertSame([1, 2], $registry->supportedVersions('InventoryAdjusted'));
        $this->assertTrue($registry->supports('InventoryAdjusted', 1));
        $this->assertFalse($registry->supports('InventoryAdjusted', 3));
    }

    public function test_event_contract_layer_has_no_optional_business_module_dependency(): void
    {
        $paths = [
            app_path('Enterprise/Events/VersionedDomainEvent.php'),
            app_path('Enterprise/Events/DomainEventContractRegistry.php'),
            app_path('Enterprise/Events/DomainEventSerializer.php'),
        ];

        foreach ($paths as $path) {
            $source = file_get_contents($path);
            $this->assertIsString($source);
            $this->assertStringNotContainsString('App\\Domain\\', $source);
        }
    }
}
