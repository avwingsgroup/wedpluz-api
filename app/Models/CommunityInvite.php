<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunityInvite extends Model
{
    protected $table = 'community_invites';

    protected static string $idPrefix = 'cinv';

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class, 'community_id');
    }
}
