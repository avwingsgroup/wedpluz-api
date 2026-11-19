<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveRequest extends Model
{
    protected $table = 'live_requests';

    protected static string $idPrefix = 'lreq';

    public function session(): BelongsTo
    {
        return $this->belongsTo(LiveSession::class, 'live_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
