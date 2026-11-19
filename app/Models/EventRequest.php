<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventRequest extends Model
{
    protected $table = 'event_requests';

    protected static string $idPrefix = 'ereq';

    protected function casts(): array
    {
        return [
            'decided_at' => 'immutable_datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
