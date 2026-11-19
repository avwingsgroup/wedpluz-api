<?php

namespace App\Models;

use App\Enums\CommunityStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Community extends Model
{
    protected static string $idPrefix = 'com';

    public const DEFAULT_PERMISSIONS = [
        'membersCanSendMessages' => true,
        'membersCanPostStatus' => false,
        'membersCanInvite' => false,
        'membersCanGoLive' => false,
        'requireApprovalToJoin' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => CommunityStatus::class,
            'permissions' => 'array',
            'archive_at' => 'immutable_datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id')->withTrashed();
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id')->withTrashed();
    }

    public function members(): HasMany
    {
        return $this->hasMany(CommunityMember::class, 'community_id');
    }

    public function conversation(): HasOne
    {
        return $this->hasOne(Conversation::class, 'community_id');
    }

    public function statuses(): HasMany
    {
        return $this->hasMany(StatusPost::class, 'community_id');
    }

    public function permission(string $key): bool
    {
        return (bool) (($this->permissions ?? []) + self::DEFAULT_PERMISSIONS)[$key];
    }

    /** @return array<string,bool> */
    public function permissionSet(): array
    {
        return array_map('boolval', array_intersect_key(($this->permissions ?? []) + self::DEFAULT_PERMISSIONS, self::DEFAULT_PERMISSIONS));
    }

    public function inviteLink(): string
    {
        return rtrim((string) config('wedpluz.web_origin'), '/').'/communities/join/'.$this->invite_code;
    }
}
