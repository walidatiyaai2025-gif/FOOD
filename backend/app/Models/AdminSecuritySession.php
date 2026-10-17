<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class AdminSecuritySession extends Model
{
    protected $fillable = [
        'public_id',
        'user_id',
        'session_hash',
        'ip_address',
        'user_agent',
        'last_seen_at',
        'mfa_verified_at',
        'revoked_at',
        'revoked_by',
    ];

    protected static function booted(): void
    {
        self::creating(function (self $session): void {
            $session->public_id ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'mfa_verified_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
