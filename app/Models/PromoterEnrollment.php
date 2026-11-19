<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromoterEnrollment extends Model
{
    protected $table = 'promoter_enrollments';

    protected static string $idPrefix = 'enr';

    protected function casts(): array
    {
        return [
            'joined_at' => 'immutable_datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(PromoterCommission::class, 'enrollment_id');
    }
}
