<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorPromo extends Model
{
    protected $table = 'vendor_promos';

    protected static string $idPrefix = 'vpr';

    protected function casts(): array
    {
        return [
            'valid_until' => 'immutable_datetime',
            'discount_percent' => 'float',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(VendorProfile::class, 'vendor_profile_id');
    }
}
