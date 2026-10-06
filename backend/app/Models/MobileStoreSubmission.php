<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MobileStoreSubmission extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'asset_checklist' => 'array',
            'permission_declarations' => 'array',
            'privacy_checklist' => 'array',
            'manual_gaps' => 'array',
        ];
    }
}
