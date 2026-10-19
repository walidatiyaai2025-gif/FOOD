<?php

namespace App\Domain\Assistant\Intelligence\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $recipient_user_id
 * @property int|null $store_id
 * @property string|null $channel
 * @property string $source_key
 * @property string $severity
 * @property string $status
 * @property string $title_ar
 * @property string $title_en
 * @property string $narrative_ar
 * @property string $narrative_en
 * @property array<string,mixed> $evidence
 * @property array<string,mixed>|null $proposed_action
 * @property CarbonImmutable $snapshot_at
 * @property CarbonImmutable|null $stale_after_at
 * @property CarbonImmutable|null $acknowledged_at
 * @property CarbonImmutable|null $resolved_at
 */
final class AssistantIntelligenceFinding extends Model
{
    public const STATUS_UNREAD = 'unread';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public const STATUS_RESOLVED = 'resolved';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'proposed_action' => 'array',
            'snapshot_at' => 'immutable_datetime',
            'stale_after_at' => 'immutable_datetime',
            'acknowledged_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
