<?php

namespace App\Domain\Assistant\Intelligence\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $user_id
 * @property int|null $store_id
 * @property string|null $channel
 * @property string $cadence
 * @property string $idempotency_key
 * @property string $state
 * @property array<string,mixed> $payload
 * @property CarbonImmutable $snapshot_at
 */
final class AssistantIntelligenceBrief extends Model
{
    public const STATE_GENERATED = 'generated';

    public const STATE_DELIVERED = 'delivered';

    public const STATE_BLOCKED = 'blocked';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'snapshot_at' => 'immutable_datetime',
            'payload' => 'array',
            'authorization_checked_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
        ];
    }
}
