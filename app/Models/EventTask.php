<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventTask extends Model
{
    protected static string $idPrefix = 'task';

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'due_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'event_task_assignees', 'task_id', 'user_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EventTaskAttachment::class, 'task_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id')->withTrashed();
    }

    public function isOverdue(): bool
    {
        return $this->due_at !== null && $this->due_at->isPast() && $this->status !== TaskStatus::Done;
    }
}
