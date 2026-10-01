<?php

namespace App\Domain\Assistant\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AssistantConversation extends Model
{
    protected $table = 'assistant_conversations';

    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AssistantMessage::class, 'conversation_id');
    }

    public function state(): HasOne
    {
        return $this->hasOne(AssistantConversationState::class, 'conversation_id');
    }

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }
}
