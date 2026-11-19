<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventVendorAssignment extends Model
{
    protected $table = 'event_vendor_assignments';

    protected static string $idPrefix = 'eva';

    protected function casts(): array
    {
        return [
            'invited_at' => 'immutable_datetime',
            'responded_at' => 'immutable_datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(VendorProfile::class, 'vendor_profile_id');
    }
}
