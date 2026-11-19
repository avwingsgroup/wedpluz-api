<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** In-app notification (`notifications` table) as rendered by AppNotification in the contract. */
class AppNotification extends Model
{
    protected static string $idPrefix = 'ntf';

    protected $table = 'notifications';

    protected function casts(): array
    {
        return [
            'segments' => 'array',
            'data' => 'array',
            'is_request' => 'boolean',
            'read_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id')->withTrashed();
    }
}
