<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeatReservation extends Model
{
    protected $table = 'seat_reservations';

    protected static string $idPrefix = 'seat';

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(EventSchedule::class, 'schedule_id');
    }
}
