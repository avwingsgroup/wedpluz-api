<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlannerChatMessage extends Model
{
    protected $table = 'planner_chat_messages';

    protected static string $idPrefix = 'pmsg';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(PlannerChat::class, 'chat_id');
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(PlannerGeneration::class, 'generation_id');
    }
}
