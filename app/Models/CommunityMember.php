<?php

namespace App\Models;

use App\Enums\CommunityRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model as Eloquent;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Composite key (community_id, user_id). Update via query builder, not save(). */
class CommunityMember extends Eloquent
{
    protected $table = 'community_members';

    protected $guarded = [];

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'community_id';

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'role' => CommunityRole::class,
            'is_muted' => 'boolean',
            'is_pinned' => 'boolean',
            'is_archived' => 'boolean',
            'joined_at' => 'immutable_datetime',
        ];
    }

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class, 'community_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, [CommunityRole::Owner, CommunityRole::CoAdmin], true);
    }

    /** Scope a query to this member row (composite key). */
    public function scopeRow(Builder $query, string $communityId, string $userId): Builder
    {
        return $query->where('community_id', $communityId)->where('user_id', $userId);
    }

    protected function setKeysForSaveQuery($query)
    {
        return $query->where('community_id', $this->getAttribute('community_id'))
            ->where('user_id', $this->getAttribute('user_id'));
    }
}
