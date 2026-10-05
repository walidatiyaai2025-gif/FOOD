<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AddressQualityReview extends Model
{
    protected $guarded = [];

    public function events(): HasMany
    {
        return $this->hasMany(AddressQualityReviewEvent::class);
    }

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'resolved_at' => 'datetime',
        ];
    }
}
