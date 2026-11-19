<?php

namespace App\Models;

use App\Enums\TicketStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ticket extends Model
{
    protected static string $idPrefix = 'tik';

    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'is_additional_guest' => 'boolean',
            'qr_secret_version' => 'integer',
            'checked_in_at' => 'immutable_datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id')->withTrashed();
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(EventSchedule::class, 'schedule_id');
    }

    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class, 'ticket_type_id')->withTrashed();
    }

    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'holder_user_id')->withTrashed();
    }

    public function seatReservation(): BelongsTo
    {
        return $this->belongsTo(SeatReservation::class, 'seat_reservation_id');
    }

    public function transferredToUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transferred_to_user_id')->withTrashed();
    }

    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by_user_id')->withTrashed();
    }
}
