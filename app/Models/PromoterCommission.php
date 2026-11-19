<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromoterCommission extends Model
{
    protected $table = 'promoter_commissions';

    protected static string $idPrefix = 'pcom';

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'credited_at' => 'immutable_datetime',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(PromoterEnrollment::class, 'enrollment_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
