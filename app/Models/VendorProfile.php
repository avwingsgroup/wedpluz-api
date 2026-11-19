<?php

namespace App\Models;

use App\Enums\VendorStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class VendorProfile extends Model
{
    use SoftDeletes;

    protected static string $idPrefix = 'vnd';

    protected function casts(): array
    {
        return [
            'status' => VendorStatus::class,
            'socials' => 'array',
            'rating_avg' => 'float',
            'ratings_count' => 'integer',
            'likes_count' => 'integer',
            'works_count' => 'integer',
            'is_featured' => 'boolean',
            'verified_at' => 'immutable_datetime',
            'contact_email_verified_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(VendorCategory::class, 'category_id');
    }

    public function works(): HasMany
    {
        return $this->hasMany(VendorWork::class, 'vendor_profile_id')->latest();
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(VendorReview::class, 'vendor_profile_id')->latest();
    }

    public function promos(): HasMany
    {
        return $this->hasMany(VendorPromo::class, 'vendor_profile_id')->latest();
    }

    public function verificationDocuments(): HasMany
    {
        return $this->hasMany(VendorVerificationDocument::class, 'vendor_profile_id');
    }

    public function isActive(): bool
    {
        return $this->status === VendorStatus::Active;
    }
}
