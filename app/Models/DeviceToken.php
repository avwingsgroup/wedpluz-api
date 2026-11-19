<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceToken extends Eloquent
{
    protected $table = 'device_tokens';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
