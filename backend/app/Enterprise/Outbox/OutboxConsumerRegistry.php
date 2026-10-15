<?php

namespace App\Enterprise\Outbox;

use App\Enterprise\Events\VersionedDomainEvent;
use LogicException;

final class OutboxConsumerRegistry
{
    /**
     * @return list<OutboxConsumer>
     */
    public function forEvent(VersionedDomainEvent $event): array
    {
        $definitions = config("enterprise_outbox.consumers.{$event->type}", []);
        if (is_array($definitions) === false) {
            throw new LogicException("Outbox consumer list for [{$event->type}] must be an array.");
        }

        $consumers = [];
        foreach ($definitions as $class) {
            if (is_string($class) === false || $class === '') {
                throw new LogicException("Outbox consumer definition for [{$event->type}] is invalid.");
            }

            $consumer = app()->make($class);
            if ($consumer instanceof OutboxConsumer === false) {
                throw new LogicException("Outbox consumer [{$class}] must implement OutboxConsumer.");
            }

            $consumers[] = $consumer;
        }

        return $consumers;
    }
}
