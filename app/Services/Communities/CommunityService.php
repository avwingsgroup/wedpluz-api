<?php

namespace App\Services\Communities;

use App\Enums\CommunityRole;
use App\Enums\CommunityStatus;
use App\Exceptions\ApiException;
use App\Models\Community;
use App\Models\CommunityInvite;
use App\Models\CommunityMember;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Event;
use App\Models\User;
use App\Services\Media\UploadService;
use App\Services\Notifications\NotificationService;
use App\Services\Realtime\Realtime;
use App\Support\Codes;
use Illuminate\Support\Facades\DB;

/**
 * Communities (business-rules.md, "Communities and messaging"). Event
 * communities admit the organiser team (owner/co-admins) and ticket holders
 * automatically. Member permissions restrict members only; owners and
 * co-admins are never restricted. Each community has one conversation whose
 * participants mirror the active members.
 */
class CommunityService
{
    public function __construct(
        private readonly UploadService $uploads,
        private readonly NotificationService $notifications,
    ) {}

    public function ensureEventCommunity(Event $event): Community
    {
        $existing = Community::query()->where('event_id', $event->id)->first();
        if ($existing) {
            if ($existing->status === CommunityStatus::Archived && $event->status->value === 'live') {
                $existing->forceFill(['status' => CommunityStatus::Active, 'archive_at' => null])->save();
            }

            return $existing;
        }

        return DB::transaction(function () use ($event) {
            $community = $this->createCommunity($event->organizer_user_id, [
                'event_id' => $event->id,
                'name' => mb_substr($event->title, 0, 200),
                'description' => null,
                'avatar_url' => $event->cover_url,
                'permissions' => Community::DEFAULT_PERMISSIONS,
            ]);

            $managers = DB::table('event_managers')->where('event_id', $event->id)->where('status', 'accepted')->pluck('user_id');
            foreach ($managers as $managerId) {
                $this->addMember($community, $managerId, CommunityRole::CoAdmin);
            }
            $holders = DB::table('tickets')->where('event_id', $event->id)->whereIn('status', ['valid', 'checked_in'])
                ->whereNotNull('holder_user_id')->distinct()->pluck('holder_user_id');
            foreach ($holders as $holderId) {
                $this->addMember($community, $holderId, CommunityRole::Member);
            }

            return $community;
        });
    }

    /** @param array{eventId:?string, name:string, description:?string, avatarUploadId:?string, permissions:array} $data */
    public function create(User $user, array $data, ?Event $event): Community
    {
        if ($event && Community::query()->where('event_id', $event->id)->exists()) {
            throw ApiException::conflict('This event already has a community.');
        }

        return DB::transaction(function () use ($user, $data, $event) {
            $avatar = ! empty($data['avatarUploadId']) ? $this->uploads->consume($data['avatarUploadId'], $user, ['avatar', 'cover'], 'avatarUploadId')->url : $event?->cover_url;

            return $this->createCommunity($event?->organizer_user_id ?? $user->id, [
                'event_id' => $event?->id,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'avatar_url' => $avatar,
                'permissions' => array_replace(Community::DEFAULT_PERMISSIONS, array_map('boolval', $data['permissions'] ?? [])),
            ], $user->id);
        });
    }

    private function createCommunity(string $ownerId, array $attrs, ?string $creatorId = null): Community
    {
        $community = Community::query()->create($attrs + [
            'owner_user_id' => $ownerId,
            'invite_code' => Codes::unique('communities', 'invite_code', 10),
            'status' => CommunityStatus::Active,
        ]);
        Conversation::query()->create(['type' => 'community', 'community_id' => $community->id]);
        $this->addMember($community, $ownerId, CommunityRole::Owner);
        if ($creatorId && $creatorId !== $ownerId) {
            $this->addMember($community, $creatorId, CommunityRole::CoAdmin);
        }

        return $community;
    }

    /** Idempotent: re-adding an existing member keeps the higher role. */
    public function addMember(Community $community, string $userId, CommunityRole $role = CommunityRole::Member): void
    {
        $existing = CommunityMember::query()->row($community->id, $userId)->first();
        if ($existing) {
            $rank = [CommunityRole::Member->value => 1, CommunityRole::CoAdmin->value => 2, CommunityRole::Owner->value => 3];
            if (($rank[$role->value] ?? 0) > ($rank[$existing->role->value] ?? 0)) {
                CommunityMember::query()->row($community->id, $userId)->update(['role' => $role->value]);
            }
        } else {
            DB::table('community_members')->insert([
                'community_id' => $community->id, 'user_id' => $userId, 'role' => $role->value, 'joined_at' => now(),
            ]);
        }

        $conversationId = Conversation::query()->where('community_id', $community->id)->value('id');
        if ($conversationId) {
            DB::table('conversation_participants')->upsert(
                [['conversation_id' => $conversationId, 'user_id' => $userId, 'deleted_at' => null, 'created_at' => now(), 'updated_at' => now()]],
                ['conversation_id', 'user_id'],
                ['deleted_at', 'updated_at'],
            );
        }
    }

    public function removeMember(Community $community, string $userId): void
    {
        DB::table('community_members')->where('community_id', $community->id)->where('user_id', $userId)->delete();
        $conversationId = Conversation::query()->where('community_id', $community->id)->value('id');
        if ($conversationId) {
            ConversationParticipant::query()->where('conversation_id', $conversationId)->where('user_id', $userId)->delete();
        }
    }

    public function membership(Community $community, User $user): ?CommunityMember
    {
        return CommunityMember::query()->row($community->id, $user->id)->first();
    }

    public function requireMember(Community $community, User $user): CommunityMember
    {
        $member = $this->membership($community, $user);
        if ($member === null) {
            throw ApiException::notFound('Community not found.');
        }

        return $member;
    }

    public function requireAdmin(Community $community, User $user): CommunityMember
    {
        $member = $this->requireMember($community, $user);
        if (! $member->isAdmin()) {
            throw ApiException::forbidden('Only community admins can do this.');
        }

        return $member;
    }

    /** Owners and co-admins always pass; members need the permission flag. */
    public function requirePermission(Community $community, User $user, string $permission): CommunityMember
    {
        $member = $this->requireMember($community, $user);
        if ($community->status !== CommunityStatus::Active) {
            throw ApiException::forbidden('This community is not active.');
        }
        if (! $member->isAdmin() && ! $community->permission($permission)) {
            throw ApiException::forbidden('Admins have turned this off for members.');
        }

        return $member;
    }

    public function join(Community $community, User $user, bool $viaInvite = false): Community
    {
        if ($community->status !== CommunityStatus::Active) {
            throw ApiException::forbidden('This community is not accepting members.');
        }
        if ($this->membership($community, $user)) {
            return $community;
        }

        $allowed = $viaInvite
            || ! $community->permission('requireApprovalToJoin')
            || CommunityInvite::query()->where('community_id', $community->id)->where('user_id', $user->id)->where('status', 'pending')->exists();
        if ($community->event_id && ! $viaInvite) {
            $hasTicket = DB::table('tickets')->where('event_id', $community->event_id)->where('holder_user_id', $user->id)->whereIn('status', ['valid', 'checked_in'])->exists();
            $allowed = $allowed && ($hasTicket || ! $community->permission('requireApprovalToJoin'));
        }
        if (! $allowed) {
            throw ApiException::forbidden('You need an invite to join this community.');
        }

        DB::transaction(function () use ($community, $user) {
            $this->addMember($community, $user->id);
            CommunityInvite::query()->where('community_id', $community->id)->where('user_id', $user->id)->where('status', 'pending')->update(['status' => 'accepted']);
        });
        Realtime::emit('community.'.$community->id, 'member.updated', ['communityId' => $community->id, 'userId' => $user->id, 'action' => 'joined']);

        return $community;
    }

    public function leave(Community $community, User $user): void
    {
        $member = $this->requireMember($community, $user);
        if ($member->role === CommunityRole::Owner) {
            throw ApiException::conflict('The owner cannot leave the community.');
        }
        $this->removeMember($community, $user->id);
    }

    /** @param list<string> $userIds @param list<string> $emails */
    public function invite(Community $community, User $inviter, array $userIds, array $emails): int
    {
        $this->requirePermission($community, $inviter, 'membersCanInvite');
        $targets = User::query()->whereIn('id', $userIds)
            ->orWhereIn(DB::raw('lower(email)'), array_map('mb_strtolower', $emails))
            ->get();

        $count = 0;
        foreach ($targets as $target) {
            if ($target->id === $inviter->id || $this->membership($community, $target)) {
                continue;
            }
            $invite = CommunityInvite::query()->firstOrCreate(
                ['community_id' => $community->id, 'user_id' => $target->id, 'status' => 'pending'],
                ['invited_by_user_id' => $inviter->id, 'email' => $target->email],
            );
            if ($invite->wasRecentlyCreated) {
                $this->notifications->notify($target, 'community_invite', [
                    'actor' => $inviter,
                    'tokens' => ['community' => $community->name],
                    'data' => ['communityId' => $community->id, 'inviteId' => $invite->id],
                    'subject' => $invite,
                ]);
                $count++;
            }
        }

        return $count;
    }

    /** Accept or decline a community invite from its notification. */
    public function respondToInvite(CommunityInvite $invite, User $user, bool $accept): void
    {
        if ($invite->user_id !== $user->id || $invite->status !== 'pending') {
            throw ApiException::conflict('This invite is no longer pending.');
        }
        if ($accept) {
            $community = Community::query()->findOrFail($invite->community_id);
            $this->join($community, $user, viaInvite: true);
        }
        $invite->forceFill(['status' => $accept ? 'accepted' : 'declined'])->save();
    }

    /** Regenerate the invite link (admins, or members when membersCanInvite). */
    public function regenerateInvite(Community $community, User $user): string
    {
        $this->requirePermission($community, $user, 'membersCanInvite');
        $community->forceFill(['invite_code' => Codes::unique('communities', 'invite_code', 10)])->save();

        return $community->inviteLink();
    }

    public function changeRole(Community $community, User $actor, string $userId, string $role): CommunityMember
    {
        $actorMember = $this->requireMember($community, $actor);
        if ($actorMember->role !== CommunityRole::Owner) {
            throw ApiException::forbidden('Only the community owner can change roles.');
        }
        $target = CommunityMember::query()->row($community->id, $userId)->first();
        if ($target === null) {
            throw ApiException::notFound('Member not found.');
        }
        if ($target->role === CommunityRole::Owner) {
            throw ApiException::conflict('The owner role cannot be changed.');
        }
        CommunityMember::query()->row($community->id, $userId)->update(['role' => $role]);

        return CommunityMember::query()->row($community->id, $userId)->with('user')->first();
    }

    public function kick(Community $community, User $actor, string $userId): void
    {
        $actorMember = $this->requireAdmin($community, $actor);
        $target = CommunityMember::query()->row($community->id, $userId)->first();
        if ($target === null) {
            throw ApiException::notFound('Member not found.');
        }
        if ($target->role === CommunityRole::Owner || ($target->role === CommunityRole::CoAdmin && $actorMember->role !== CommunityRole::Owner)) {
            throw ApiException::forbidden('You cannot remove this member.');
        }
        $this->removeMember($community, $userId);
    }
}
