<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReconciliationRecord extends Model
{
    protected $table = 'reconciliation_records';

    protected static string $idPrefix = 'rec';

    protected function casts(): array
    {
        return [
            'provider_report_line' => 'array',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }
}
