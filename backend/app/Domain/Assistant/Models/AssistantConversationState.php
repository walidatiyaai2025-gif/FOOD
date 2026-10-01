<?php

namespace App\Domain\Assistant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistantConversationState extends Model
{
    protected $table = 'assistant_conversation_state';

    protected $guarded = [];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AssistantConversation::class, 'conversation_id');
    }

    protected function casts(): array
    {
        return [
            'last_period' => 'array',
            'last_authorized_entities' => 'array',
            'result_references' => 'array',
            'pending_clarification_slots' => 'array',
        ];
    }
}
