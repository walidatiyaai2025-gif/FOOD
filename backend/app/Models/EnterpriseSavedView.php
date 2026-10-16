<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseSavedView extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'scope_id' => 'integer',
            'filters' => 'array',
            'columns' => 'array',
            'per_page' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
