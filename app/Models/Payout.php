<?php

namespace App\Models;

use App\Enums\PayoutStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Payout extends Model
{
    protected static string $idPrefix = 'pyt';

    protected function casts(): array
    {
        return [
            'status' => PayoutStatus::class,
            'bank_account_snapshot' => 'array',
            'amount' => 'integer',
            'approved_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_user_id')->withTrashed();
    }

    public function settlements(): BelongsToMany
    {
        return $this->belongsToMany(Settlement::class, 'payout_settlements', 'payout_id', 'settlement_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'requested_by_admin_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'approved_by_admin_id');
    }
}
