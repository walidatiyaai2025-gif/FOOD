<?php

namespace App\Domain\Assistant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistantMessage extends Model
{
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';

    protected $table = 'assistant_messages';

    protected $guarded = [];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AssistantConversation::class, 'conversation_id');
    }

    protected function casts(): array
    {
        return [
            'confidence' => 'decimal:4',
            'payload' => 'array',
        ];
    }
}
