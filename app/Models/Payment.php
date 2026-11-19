<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One provider transaction for an order, wallet top-up or subscription.
 * Unique on (provider, provider_reference).
 */
class Payment extends Model
{
    protected static string $idPrefix = 'pay';

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount' => 'integer',
            'fee' => 'integer',
            'refunded_amount' => 'integer',
            'client_params' => 'array',
            'raw_payload' => 'array',
            'verified_at' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime',
        ];
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    public function refundableAmount(): int
    {
        return max(0, $this->amount - $this->refunded_amount);
    }
}
