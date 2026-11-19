<?php

namespace App\Services\Events;

use App\Models\ActivityLog;
use App\Models\Event;
use App\Models\User;

/** Append-only organiser activity log for significant event changes. */
class ActivityLogger
{
    public function __construct(private readonly EventAccess $access) {}

    public function log(Event $event, ?User $actor, string $action, string $description, array $metadata = []): ActivityLog
    {
        $role = $actor ? ($this->access->role($actor, $event) ?? 'organizer') : 'system';

        return ActivityLog::query()->create([
            'event_id' => $event->id,
            'actor_user_id' => $actor?->id,
            'actor_role' => $role,
            'action' => $action,
            'description' => mb_substr($description, 0, 500),
            'metadata' => $metadata ?: null,
        ]);
    }
}
