<?php

namespace App\Domain\SmartOrdering\Models;

use App\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SmartOrderingSite extends Model
{
    protected $guarded = [];

    protected $hidden = ['custom_domain_verification_token_hash'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(SmartOrderingBranch::class, 'site_id');
    }

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
