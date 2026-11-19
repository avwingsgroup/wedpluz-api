<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TicketType extends Model
{
    use SoftDeletes;

    protected $table = 'ticket_types';

    protected static string $idPrefix = 'tkt';

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'quantity' => 'integer',
            'sold' => 'integer',
            'sales_start_at' => 'immutable_datetime',
            'sales_end_at' => 'immutable_datetime',
            'per_order_limit' => 'integer',
            'seat_selection' => 'boolean',
            'menu_selection' => 'boolean',
            'is_early_bird' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(EventSchedule::class, 'schedule_id');
    }
}
