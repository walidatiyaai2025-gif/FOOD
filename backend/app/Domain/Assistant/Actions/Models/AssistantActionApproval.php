<?php

namespace App\Domain\Assistant\Actions\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property array<string, mixed> $payload
 * @property array<string, mixed> $preview
 * @property array<string, mixed>|null $result
 * @property Carbon|null $expires_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $executed_at
 */
final class AssistantActionApproval extends Model
{
    protected $table = 'assistant_action_approvals';

    protected $guarded = [];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'preview' => 'array',
            'result' => 'array',
            'expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }
}
