<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class StatusPost extends Model
{
    use SoftDeletes;

    protected $table = 'status_posts';

    protected static string $idPrefix = 'sts';

    protected function casts(): array
    {
        return [
            'likes_count' => 'integer',
            'views_count' => 'integer',
            'expires_at' => 'immutable_datetime',
            'media_purged_at' => 'immutable_datetime',
        ];
    }

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class, 'community_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
