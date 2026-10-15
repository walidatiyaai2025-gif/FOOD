<?php

namespace App\Domain\SmartOrdering\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $site_id
 * @property string $code
 * @property string|null $name_ar
 * @property string $name_en
 * @property string $timezone
 * @property bool $is_default
 * @property bool $is_active
 */
class SmartOrderingBranch extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<SmartOrderingSite, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(SmartOrderingSite::class, 'site_id');
    }

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean'];
    }
}
