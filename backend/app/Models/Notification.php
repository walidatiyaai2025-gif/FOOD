<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Notification extends Model
{
    protected $table = 'notifications';

    protected $guarded = [];

    public function reads(): HasMany
    {
        return $this->hasMany(NotificationRead::class);
    }

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }
}
