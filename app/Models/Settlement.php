<?php

namespace App\Models;

use App\Enums\SettlementStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Settlement extends Model
{
    protected static string $idPrefix = 'stl';

    protected function casts(): array
    {
        return [
            'status' => SettlementStatus::class,
            'gross_sales' => 'integer',
            'refunds' => 'integer',
            'platform_commission' => 'integer',
            'gateway_fees' => 'integer',
            'promoter_commissions' => 'integer',
            'adjustments' => 'integer',
            'net_payable' => 'integer',
            'eligible_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
        ];
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_user_id')->withTrashed();
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id')->withTrashed();
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class, 'payout_id');
    }
}
