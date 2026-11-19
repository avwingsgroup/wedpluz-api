<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BankAccount extends Model
{
    use SoftDeletes;

    protected $table = 'bank_accounts';

    protected static string $idPrefix = 'bnk';

    protected function casts(): array
    {
        return [
            'account_number_encrypted' => 'encrypted',
            'is_default' => 'boolean',
            'verified_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
