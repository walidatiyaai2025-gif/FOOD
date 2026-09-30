<?php

namespace App\Services;

use App\Models\AppPreviewInvalidationEvent;
use App\Models\StorefrontRevision;

final class AppPreviewInvalidationService
{
    public const STOREFRONT_UPDATED = 'storefront.preview.updated';

    public const CONFIGURATION_UPDATED = 'app.preview.configuration.updated';

    public function emitForRevision(
        StorefrontRevision $revision,
        string $eventName = self::CONFIGURATION_UPDATED,
    ): AppPreviewInvalidationEvent {
        abort_unless(
            in_array($eventName, [self::STOREFRONT_UPDATED, self::CONFIGURATION_UPDATED], true),
            500,
            'Unsupported preview invalidation event.',
        );

        return AppPreviewInvalidationEvent::query()->create([
            'event_name' => $eventName,
            'channel' => (string) $revision->channel,
            'store_id' => (int) $revision->store_id,
            'revision_public_id' => (string) $revision->public_id,
            'revision_status' => (string) $revision->status,
            'checksum' => (string) $revision->checksum,
            'schema_version' => (int) $revision->schema_version,
            'occurred_at' => now(),
        ]);
    }

    /** @return array<string,mixed> */
    public function payload(AppPreviewInvalidationEvent $event): array
    {
        return [
            'event_id' => (int) $event->getKey(),
            'event' => (string) $event->event_name,
            'channel' => (string) $event->channel,
            'store_id' => (int) $event->store_id,
            'revision_id' => $event->revision_public_id,
            'revision_status' => $event->revision_status,
            'checksum' => $event->checksum,
            'schema_version' => $event->schema_version,
            'occurred_at' => $event->occurred_at->toIso8601String(),
        ];
    }
}
