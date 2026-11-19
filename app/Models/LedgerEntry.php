<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class LedgerEntry extends Eloquent
{
    protected $table = 'ledger_entries';

    protected $guarded = [];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
