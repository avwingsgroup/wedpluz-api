<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountDeletionRequest extends Model
{
    protected $table = 'account_deletion_requests';

    protected static string $idPrefix = 'adr';

    protected function casts(): array
    {
        return [
            'requested_at' => 'immutable_datetime',
            'scheduled_for' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
