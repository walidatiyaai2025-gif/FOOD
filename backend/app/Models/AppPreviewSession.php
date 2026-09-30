<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $expires_at
 * @property Carbon|null $last_resolved_at
 * @property Carbon|null $revoked_at
 */
class AppPreviewSession extends Model
{
    protected $table = 'app_preview_sessions';

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    protected function casts(): array
    {
        return [
            'support_access' => 'boolean',
            'expires_at' => 'datetime',
            'last_resolved_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
