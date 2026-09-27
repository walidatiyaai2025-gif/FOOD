<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationCampaignRun extends Model
{
    protected $table = 'notification_campaign_runs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
