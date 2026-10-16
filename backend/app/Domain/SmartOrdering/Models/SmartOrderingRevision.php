<?php

namespace App\Domain\SmartOrdering\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $site_id
 * @property string $status
 * @property int $schema_version
 * @property array<string, mixed> $payload
 * @property string $checksum
 * @property int|null $parent_revision_id
 * @property int|null $created_by
 * @property int|null $published_by
 * @property int|null $scheduled_by
 * @property Carbon|null $scheduled_at
 * @property Carbon|null $published_at
 */
class SmartOrderingRevision extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<SmartOrderingSite, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(SmartOrderingSite::class, 'site_id');
    }

    /** @return BelongsTo<SmartOrderingRevision, $this> */
    public function parentRevision(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_revision_id');
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'schema_version' => 'integer',
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }
}
