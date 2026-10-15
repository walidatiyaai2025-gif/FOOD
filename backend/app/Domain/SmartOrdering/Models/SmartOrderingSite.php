<?php

namespace App\Domain\SmartOrdering\Models;

use App\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $store_id
 * @property string $slug
 * @property string $runtime_state
 * @property bool $master_enabled
 * @property string|null $custom_domain
 * @property string|null $custom_domain_verification_token_hash
 * @property Carbon|null $custom_domain_verified_at
 */
class SmartOrderingSite extends Model
{
    protected $guarded = [];

    protected $hidden = ['custom_domain_verification_token_hash'];

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** @return HasMany<SmartOrderingBranch, $this> */
    public function branches(): HasMany
    {
        return $this->hasMany(SmartOrderingBranch::class, 'site_id');
    }

    /** @return HasMany<SmartOrderingRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(SmartOrderingRevision::class, 'site_id');
    }

    protected function casts(): array
    {
        return [
            'master_enabled' => 'boolean',
            'custom_domain_verified_at' => 'datetime',
        ];
    }
}
