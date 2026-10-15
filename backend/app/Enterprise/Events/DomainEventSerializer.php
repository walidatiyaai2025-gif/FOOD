<?php

namespace App\Enterprise\Events;

use JsonException;
use UnexpectedValueException;

final class DomainEventSerializer
{
    public function __construct(private readonly DomainEventContractRegistry $contracts) {}

    /**
     * @throws JsonException
     */
    public function serialize(VersionedDomainEvent $event): string
    {
        $this->contracts->assertSupported($event);

        return json_encode($event->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @throws JsonException
     */
    public function deserialize(string $json): VersionedDomainEvent
    {
        $values = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($values)) {
            throw new UnexpectedValueException('Domain event JSON must decode to an object.');
        }

        $event = VersionedDomainEvent::fromArray($values);
        $this->contracts->assertSupported($event);

        return $event;
    }
}
