<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Dispute extends Model
{
    protected $table = 'disputes';

    protected static string $idPrefix = 'dsp';

    protected function casts(): array
    {
        return [
            'notes' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by_user_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'assigned_admin_id');
    }
}
