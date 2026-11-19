<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromoCode extends Model
{
    protected $table = 'promo_codes';

    protected static string $idPrefix = 'promo';

    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'max_uses' => 'integer',
            'used_count' => 'integer',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'applies_to_ticket_type_ids' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }
}
