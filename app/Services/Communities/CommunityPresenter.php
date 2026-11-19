<?php

namespace App\Services\Communities;

use App\Http\Resources\Fmt;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\User;
use App\Services\Events\EventPresenter;
use App\Support\Models;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Community and CommunityMember resource shapes, batched for lists. */
class CommunityPresenter
{
    /** @param iterable<Community> $communities @return list<array> */
    public function many(iterable $communities, User $viewer): array
    {
        $communities = Models::collect($communities);
        if ($communities->isEmpty()) {
            return [];
        }
        $communities = Models::collect($communities);
        $communities->loadMissing(['event.promotion']);
        $ids = $communities->pluck('id');

        $counts = DB::table('community_members')->whereIn('community_id', $ids)
            ->selectRaw('community_id, count(*) as members')->groupBy('community_id')->pluck('members', 'community_id');
        $online = DB::table('community_members')
            ->join('users', 'users.id', '=', 'community_members.user_id')
            ->whereIn('community_id', $ids)->where('users.last_seen_at', '>=', now()->subMinutes(5))
            ->selectRaw('community_id, count(*) as c')->groupBy('community_id')->pluck('c', 'community_id');
        $mine = DB::table('community_members')->whereIn('community_id', $ids)->where('user_id', $viewer->id)->get()->keyBy('community_id');

        return $communities->map(fn (Community $c) => $this->shape($c, (int) ($counts[$c->id] ?? 0), (int) ($online[$c->id] ?? 0), $mine[$c->id] ?? null))->values()->all();
    }

    public function one(Community $community, User $viewer): array
    {
        return $this->many([$community], $viewer)[0];
    }

    private function shape(Community $c, int $members, int $online, ?object $mine): array
    {
        $event = $c->event;

        return [
            'id' => $c->id,
            'eventId' => $c->event_id,
            'event' => $event ? [
                'id' => $event->id,
                'slug' => $event->slug,
                'title' => $event->title,
                'startsAt' => Fmt::date($event->starts_at) ?? Fmt::date($event->created_at),
                'coverUrl' => $event->cover_url ?: EventPresenter::placeholderCover(),
                'isPromoted' => (bool) $event->promotion?->enabled,
            ] : null,
            'name' => $c->name,
            'description' => $c->description,
            'avatarUrl' => $c->avatar_url,
            'membersCount' => $members,
            'onlineCount' => $online,
            'myRole' => $mine->role ?? 'none',
            'permissions' => $c->permissionSet(),
            'inviteLink' => $c->inviteLink(),
            'isArchived' => (bool) ($mine->is_archived ?? false) || $c->status->value === 'archived',
            'isMuted' => (bool) ($mine->is_muted ?? false),
            'isPinned' => (bool) ($mine->is_pinned ?? false),
            'createdAt' => Fmt::date($c->created_at),
        ];
    }

    /** @param Collection<int, CommunityMember> $members */
    public static function members(Collection $members): array
    {
        return $members->map(fn (CommunityMember $m) => self::member($m))->values()->all();
    }

    public static function member(CommunityMember $m): array
    {
        $user = $m->user;

        return [
            'id' => $m->user_id,
            'user' => Fmt::user($user),
            'role' => $m->role->value,
            'joinedAt' => Fmt::date($m->joined_at),
            'isVendor' => (bool) $user?->is_vendor,
            'isOnline' => $user?->last_seen_at !== null && $user->last_seen_at->gte(now()->subMinutes(5)),
        ];
    }
}
