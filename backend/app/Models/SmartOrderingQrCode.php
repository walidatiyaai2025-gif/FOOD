<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class SmartOrderingQrCode extends Model
{
    protected $table = 'smart_ordering_qr_codes';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(SmartOrderingQrToken::class, 'qr_code_id');
    }

    public function activeToken(): HasOne
    {
        return $this->hasOne(SmartOrderingQrToken::class, 'qr_code_id')
            ->where('state', 'active')
            ->latestOfMany();
    }

    public function visits(): HasMany
    {
        return $this->hasMany(SmartOrderingQrVisit::class, 'qr_code_id');
    }
}
