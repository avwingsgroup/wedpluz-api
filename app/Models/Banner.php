<?php

namespace App\Models;

use App\Jobs\ImportRemoteImageJob;

class Banner extends Model
{
    protected $table = 'banners';

    protected static string $idPrefix = 'bnr';

    /** Where a banner shows. `onboarding` = the sign-up / login image carousel (additive). */
    public const PLACEMENTS = ['home_hero', 'home_inline', 'events_top', 'vendors_top', 'app_home', 'onboarding'];

    /** Active now: switched on and inside its optional schedule. */
    public function scopeLive($query)
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    /** Remote images (e.g. Google profile pictures) are copied into Cloudinary after save. */
    protected static function booted(): void
    {
        static::saved(function (self $model) {
            if ($model->wasRecentlyCreated || $model->wasChanged('image_url')) {
                ImportRemoteImageJob::dispatchIfRemote($model->getTable(), $model->getKey(), 'image_url', 'banner', $model->image_url);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'is_active' => 'boolean',
            'priority' => 'integer',
            'impressions' => 'integer',
            'clicks' => 'integer',
        ];
    }
}
