<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountDeletionRequest extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'retention_context' => 'array',
            'anonymized_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function anonymizedAt(): ?CarbonImmutable
    {
        $value = $this->getAttribute('anonymized_at');

        return $value === null ? null : CarbonImmutable::parse((string) $value);
    }

    public function completedAt(): ?CarbonImmutable
    {
        $value = $this->getAttribute('completed_at');

        return $value === null ? null : CarbonImmutable::parse((string) $value);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
