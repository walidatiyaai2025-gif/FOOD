<?php

namespace App\Domain\Assistant\Intelligence\Models;

use Illuminate\Database\Eloquent\Model;

final class AssistantIntelligenceSubscription extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'daily_enabled' => 'boolean',
            'weekly_enabled' => 'boolean',
        ];
    }
}
