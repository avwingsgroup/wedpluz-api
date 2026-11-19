<?php

namespace App\Models;

use App\Jobs\ImportRemoteImageJob;

class Banner extends Model
{
    protected $table = 'banners';

    protected static string $idPrefix = 'bnr';

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
