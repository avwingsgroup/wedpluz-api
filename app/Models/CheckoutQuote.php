<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CheckoutQuote extends Model
{
    protected $table = 'checkout_quotes';

    protected static string $idPrefix = 'qte';

    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'food_lines' => 'array',
            'subtotal' => 'integer',
            'ticket_subtotal' => 'integer',
            'service_fee' => 'integer',
            'discount' => 'integer',
            'total' => 'integer',
            'requires_seat_selection' => 'boolean',
            'requires_menu_selection' => 'boolean',
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function holds(): HasMany
    {
        return $this->hasMany(InventoryHold::class, 'quote_id');
    }
}
