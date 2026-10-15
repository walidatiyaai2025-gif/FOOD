<?php

namespace App\Models;

use App\Domain\SmartOrdering\RuntimeState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SmartOrderingSite extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['master_enabled' => 'boolean'];
    }

    public function runtimeState(): RuntimeState
    {
        return RuntimeState::tryFrom((string) $this->runtime_state) ?? RuntimeState::DISABLED;
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(SmartOrderingBranchScope::class, 'site_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(SmartOrderingRevision::class, 'site_id');
    }
}
