<?php

namespace App\Models;

use App\Enums\RefundStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    protected static string $idPrefix = 'rfd';

    protected function casts(): array
    {
        return [
            'status' => RefundStatus::class,
            'amount' => 'integer',
            'needs_attention' => 'boolean',
            'decided_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'decided_by_admin_id');
    }
}
