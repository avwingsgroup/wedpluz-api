<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Composite key (conversation_id, user_id). */
class ConversationParticipant extends Eloquent
{
    protected $table = 'conversation_participants';

    protected $guarded = [];

    public $incrementing = false;

    protected $primaryKey = 'conversation_id';

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'unread_count' => 'integer',
            'is_pinned' => 'boolean',
            'is_muted' => 'boolean',
            'is_archived' => 'boolean',
            'is_request' => 'boolean',
            'last_read_at' => 'immutable_datetime',
            'request_declined_at' => 'immutable_datetime',
            'cleared_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:sP';
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    protected function setKeysForSaveQuery($query)
    {
        return $query->where('conversation_id', $this->getAttribute('conversation_id'))
            ->where('user_id', $this->getAttribute('user_id'));
    }
}
