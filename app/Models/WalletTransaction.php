<?php

namespace App\Models;

use App\Enums\TransactionStatus;
use App\Enums\WalletTransactionType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    protected static string $idPrefix = 'wtx';

    protected function casts(): array
    {
        return [
            'type' => WalletTransactionType::class,
            'status' => TransactionStatus::class,
            'amount' => 'integer',
            'fee' => 'integer',
            'metadata' => 'array',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'wallet_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id')->withTrashed();
    }
}
