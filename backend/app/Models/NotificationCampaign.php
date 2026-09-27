<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationCampaign extends Model
{
    protected $table = 'notification_campaigns';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
            'run_count' => 'integer',
            'interval_value' => 'integer',
            'max_runs' => 'integer',
        ];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(NotificationCampaignRun::class, 'campaign_id');
    }
}
