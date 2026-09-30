<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $event_name
 * @property string $channel
 * @property int $store_id
 * @property string|null $revision_public_id
 * @property string|null $revision_status
 * @property string|null $checksum
 * @property int|null $schema_version
 * @property Carbon $occurred_at
 */
class AppPreviewInvalidationEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'store_id' => 'integer',
            'schema_version' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }
}
