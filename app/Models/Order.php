<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Order extends Model
{
    protected static string $idPrefix = 'ord';

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'attendee' => 'array',
            'additional_guests' => 'array',
            'commission_rule_snapshot' => 'array',
            'subtotal' => 'integer',
            'ticket_subtotal' => 'integer',
            'service_fee' => 'integer',
            'discount' => 'integer',
            'total' => 'integer',
            'refunded_amount' => 'integer',
            'paid_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id')->withTrashed();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class, 'order_id');
    }

    public function foodLines(): HasMany
    {
        return $this->hasMany(OrderFoodLine::class, 'order_id');
    }

    public function seats(): HasMany
    {
        return $this->hasMany(SeatReservation::class, 'order_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'order_id');
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function latestPayment(): HasOne
    {
        return $this->hasOne(Payment::class, 'payable_id')->where('payable_type', 'order')->latestOfMany();
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class, 'order_id');
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class, 'order_id');
    }

    public function promoterCommission(): HasOne
    {
        return $this->hasOne(PromoterCommission::class, 'order_id');
    }

    public function isPaid(): bool
    {
        return $this->status === OrderStatus::Paid;
    }
}
