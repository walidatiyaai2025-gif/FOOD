<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property-read Collection<int, RoutingPolicyRule> $rules
 */
class RoutingPolicy extends Model
{
    protected $guarded = [];

    public function rules(): HasMany
    {
        return $this->hasMany(RoutingPolicyRule::class)->orderBy('position');
    }

    protected function casts(): array
    {
        return [
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
            'published_at' => 'datetime',
        ];
    }
}
