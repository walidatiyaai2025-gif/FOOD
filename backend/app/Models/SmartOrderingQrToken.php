<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SmartOrderingQrToken extends Model
{
    protected $table = 'smart_ordering_qr_tokens';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'token_value' => 'encrypted',
            'legacy_expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function qrCode(): BelongsTo
    {
        return $this->belongsTo(SmartOrderingQrCode::class, 'qr_code_id');
    }
}
