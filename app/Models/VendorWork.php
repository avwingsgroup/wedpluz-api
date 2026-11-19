<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class VendorWork extends Model
{
    use SoftDeletes;

    protected $table = 'vendor_works';

    protected static string $idPrefix = 'wrk';

    protected function casts(): array
    {
        return [
            'likes_count' => 'integer',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(VendorProfile::class, 'vendor_profile_id');
    }

    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'owner')->orderBy('sort_order');
    }
}
