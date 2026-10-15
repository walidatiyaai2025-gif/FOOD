<?php

namespace App\Enterprise\Outbox;

use App\Enterprise\Events\VersionedDomainEvent;

interface OutboxConsumer
{
    public function handle(VersionedDomainEvent $event): void;
}
