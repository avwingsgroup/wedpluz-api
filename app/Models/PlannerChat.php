<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PlannerChat extends Model
{
    use SoftDeletes;

    protected $table = 'planner_chats';

    protected static string $idPrefix = 'pchat';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(PlannerChatMessage::class, 'chat_id');
    }
}
