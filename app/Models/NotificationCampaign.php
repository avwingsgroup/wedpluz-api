<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationCampaign extends Model
{
    protected $table = 'notification_campaigns';

    protected static string $idPrefix = 'cmp';

    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'audience' => 'array',
            'stats' => 'array',
            'scheduled_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by_admin_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class, 'campaign_id');
    }
}
