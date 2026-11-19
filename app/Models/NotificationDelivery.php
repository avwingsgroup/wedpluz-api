<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationDelivery extends Model
{
    protected $table = 'notification_deliveries';

    protected static string $idPrefix = 'dlv';

    protected function casts(): array
    {
        return [
            'sent_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'opened_at' => 'immutable_datetime',
            'clicked_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
