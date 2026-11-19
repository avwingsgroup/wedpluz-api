<?php

namespace App\Models;

use App\Enums\MessageType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Message extends Model
{
    protected static string $idPrefix = 'msg';

    protected function casts(): array
    {
        return [
            'type' => MessageType::class,
            'is_pinned' => 'boolean',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id')->withTrashed();
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'reply_to_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id')->withTrashed();
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Media::class, 'owner')->orderBy('sort_order');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(MessageReaction::class, 'message_id');
    }

    public function stars(): HasMany
    {
        return $this->hasMany(MessageStar::class, 'message_id');
    }

    public function isDeleted(): bool
    {
        return $this->deleted_by !== 'none';
    }
}
