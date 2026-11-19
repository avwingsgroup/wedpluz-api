<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected static string $idPrefix = 'cnv';

    protected function casts(): array
    {
        return [
            'last_message_at' => 'immutable_datetime',
        ];
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class, 'conversation_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'conversation_id');
    }

    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_message_id');
    }

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class, 'community_id');
    }

    public static function directKey(string $a, string $b): string
    {
        $pair = [$a, $b];
        sort($pair);

        return implode(':', $pair);
    }
}
