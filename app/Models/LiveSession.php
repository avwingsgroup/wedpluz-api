<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LiveSession extends Model
{
    protected $table = 'live_sessions';

    protected static string $idPrefix = 'live';

    protected function casts(): array
    {
        return [
            'audio_enabled' => 'boolean',
            'video_enabled' => 'boolean',
            'viewers_count' => 'integer',
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
        ];
    }

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class, 'community_id');
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(LiveRequest::class, 'live_session_id');
    }
}
