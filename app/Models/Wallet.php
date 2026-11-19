<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user's wallet. The balance is derived from the append-only ledger;
 * `cached_balance` and `cached_pending_balance` are updated only in the same
 * database transaction as the ledger entries (see LedgerService).
 */
class Wallet extends Model
{
    protected static string $idPrefix = 'wal';

    protected $hidden = ['pin_hash'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'pin_failed_attempts' => 'integer',
            'pin_locked_until' => 'immutable_datetime',
            'cached_balance' => 'integer',
            'cached_pending_balance' => 'integer',
            'daily_withdrawal_limit' => 'integer',
            'activated_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class, 'wallet_id');
    }

    public function availableAccount(): string
    {
        return 'wallet:'.$this->id;
    }

    public function pendingAccount(): string
    {
        return 'wallet_pending:'.$this->id;
    }
}
