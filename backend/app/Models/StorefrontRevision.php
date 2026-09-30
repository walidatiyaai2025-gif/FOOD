<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $store_id
 * @property string $channel
 * @property string $status
 * @property int $schema_version
 * @property array<string,mixed> $payload
 * @property string $checksum
 * @property int|null $parent_revision_id
 * @property int|null $source_revision_id
 * @property int|null $created_by
 * @property int|null $published_by
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class StorefrontRevision extends Model
{
    protected $guarded = [];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function parentRevision(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_revision_id');
    }

    public function sourceRevision(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_revision_id');
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'schema_version' => 'integer',
            'published_at' => 'datetime',
        ];
    }
}
