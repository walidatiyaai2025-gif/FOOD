<?php

namespace App\Enterprise\Events;

use LogicException;

final class DomainEventContractRegistry
{
    /**
     * @return list<int>
     */
    public function supportedVersions(string $type): array
    {
        $versions = config("enterprise_events.contracts.{$type}", []);

        if (! is_array($versions)) {
            return [];
        }

        return collect($versions)
            ->map(static fn (mixed $version): int => (int) $version)
            ->filter(static fn (int $version): bool => $version > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    public function assertSupported(VersionedDomainEvent $event): void
    {
        $versions = $this->supportedVersions($event->type);

        if (! in_array($event->version, $versions, true)) {
            throw new LogicException(
                "Unsupported domain event contract [{$event->type}.v{$event->version}].",
            );
        }
    }

    public function supports(string $type, int $version): bool
    {
        return in_array($version, $this->supportedVersions($type), true);
    }
}
