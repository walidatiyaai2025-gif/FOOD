<?php

namespace App\Enterprise\Outbox;

use App\Enterprise\Events\DomainEventSerializer;
use App\Enterprise\Events\VersionedDomainEvent;
use App\Models\EnterpriseOutboxMessage;
use Illuminate\Database\QueryException;

final class TransactionalOutbox
{
    public function __construct(private readonly DomainEventSerializer $serializer) {}

    public function enqueue(VersionedDomainEvent $event): EnterpriseOutboxMessage
    {
        $payload = $this->serializer->serialize($event);

        try {
            return EnterpriseOutboxMessage::query()->create([
                'event_id' => $event->id,
                'event_type' => $event->type,
                'event_version' => $event->version,
                'payload' => $payload,
                'status' => 'pending',
                'attempt_count' => 0,
                'available_at' => now(),
            ]);
        } catch (QueryException $exception) {
            $existing = EnterpriseOutboxMessage::query()
                ->where('event_id', $event->id)
                ->first();

            if ($existing instanceof EnterpriseOutboxMessage) {
                return $existing;
            }

            throw $exception;
        }
    }
}
