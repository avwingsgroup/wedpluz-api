<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class VendorReview extends Model
{
    use SoftDeletes;

    protected $table = 'vendor_reviews';

    protected static string $idPrefix = 'rev';

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(VendorProfile::class, 'vendor_profile_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
