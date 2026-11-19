<?php

namespace App\Models;

use App\Enums\CollaboratorStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventManager extends Model
{
    protected static string $idPrefix = 'mgr';

    protected function casts(): array
    {
        return [
            'status' => CollaboratorStatus::class,
            'permissions' => 'array',
            'invited_at' => 'immutable_datetime',
            'responded_at' => 'immutable_datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    public function grants(string $permission): bool
    {
        return $this->access === 'full' || in_array($permission, $this->permissions ?? [], true);
    }
}
