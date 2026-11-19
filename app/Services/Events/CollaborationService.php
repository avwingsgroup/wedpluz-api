<?php

namespace App\Services\Events;

use App\Enums\CommunityRole;
use App\Enums\ManagerPermission;
use App\Enums\VendorStatus;
use App\Exceptions\ApiException;
use App\Http\Resources\Fmt;
use App\Models\AppNotification;
use App\Models\Community;
use App\Models\Event;
use App\Models\EventManager;
use App\Models\EventVendorAssignment;
use App\Models\User;
use App\Models\VendorProfile;
use App\Services\Communities\CommunityService;
use App\Services\Notifications\NotificationService;
use App\Support\Platform;
use App\Support\Sql;
use Illuminate\Support\Facades\DB;

/**
 * Event managers and vendor assignments: invited as `pending`, accepted or
 * declined by the invitee (directly or from the accept/decline notification).
 * Only the organiser invites, edits or removes managers; inviting vendors
 * needs `manage_vendors`.
 */
class CollaborationService
{
    public function __construct(
        private readonly EventAccess $access,
        private readonly ActivityLogger $activity,
        private readonly NotificationService $notifications,
        private readonly CommunityService $communities,
    ) {}

    /** @param list<string> $permissions */
    public function inviteManager(Event $event, User $actor, ?string $userId, ?string $email, string $access, array $permissions): EventManager
    {
        $this->access->requireOrganizer($actor, $event);
        $target = $userId ? User::query()->find($userId) : User::query()->whereRaw('lower(email) = ?', [mb_strtolower((string) $email)])->first();
        if ($target === null || $target->anonymized_at !== null) {
            throw ApiException::validation('No Wedpluz account found for this person.', [$userId ? 'userId' : 'email' => ['Invite someone who has a Wedpluz account.']]);
        }
        if ($target->id === $event->organizer_user_id) {
            throw ApiException::validation('The organiser cannot be a manager.', ['userId' => ['You already organise this event.']]);
        }

        $manager = Sql::insertOrNull(fn () => EventManager::query()->create([
            'event_id' => $event->id,
            'user_id' => $target->id,
            'invited_by_user_id' => $actor->id,
            'access' => $access,
            'permissions' => $access === 'full' ? [] : array_values(array_unique($permissions)),
            'status' => 'pending',
            'invited_at' => now(),
        ]));
        if ($manager === null) {
            throw ApiException::conflict('This person is already a manager or has a pending invite.');
        }

        $this->notifications->notify($target, 'manager_invite', [
            'actor' => $actor,
            'tokens' => ['event' => $event->title],
            'data' => ['eventId' => $event->id, 'collaborationId' => $manager->id],
            'subject' => $manager,
            'email' => ['inviterName' => $actor->display_name, 'eventTitle' => $event->title, 'role' => 'manager', 'appUrl' => Platform::webUrl('/notifications')],
        ]);
        $this->activity->log($event, $actor, 'manager.invited', 'Invited '.$target->display_name.' as a manager');

        return $manager->setRelation('user', $target);
    }

    /** @param list<string>|null $permissions */
    public function updateManager(Event $event, User $actor, string $managerId, ?string $access, ?array $permissions): EventManager
    {
        $this->access->requireOrganizer($actor, $event);
        $manager = EventManager::query()->where('event_id', $event->id)->whereIn('status', ['pending', 'accepted'])->findOrFail($managerId);
        $access ??= $manager->access;
        $manager->forceFill([
            'access' => $access,
            'permissions' => $access === 'full' ? [] : array_values(array_unique($permissions ?? $manager->permissions ?? [])),
        ])->save();
        $this->activity->log($event, $actor, 'manager.updated', 'Changed access for '.$manager->user->display_name);

        return $manager->load('user');
    }

    public function removeManager(Event $event, User $actor, string $managerId): void
    {
        $this->access->requireOrganizer($actor, $event);
        $manager = EventManager::query()->with('user')->where('event_id', $event->id)->whereIn('status', ['pending', 'accepted'])->findOrFail($managerId);
        $manager->forceFill(['status' => 'removed', 'responded_at' => now()])->save();

        $community = Community::query()->where('event_id', $event->id)->first();
        if ($community && ! DB::table('tickets')->where('event_id', $event->id)->where('holder_user_id', $manager->user_id)->whereIn('status', ['valid', 'checked_in'])->exists()) {
            $this->communities->removeMember($community, $manager->user_id);
        } elseif ($community) {
            DB::table('community_members')->where('community_id', $community->id)->where('user_id', $manager->user_id)->update(['role' => 'member']);
        }
        $this->activity->log($event, $actor, 'manager.removed', 'Removed '.$manager->user->display_name.' as a manager');
    }

    public function inviteVendor(Event $event, User $actor, string $vendorId, ?string $role): EventVendorAssignment
    {
        $this->access->authorize($actor, $event, 'manage_vendors');
        $vendor = VendorProfile::query()->with('user')->find($vendorId);
        if ($vendor === null || $vendor->status !== VendorStatus::Active) {
            throw ApiException::validation('This vendor is not available.', ['vendorId' => ['Select an active vendor.']]);
        }

        $assignment = Sql::insertOrNull(fn () => EventVendorAssignment::query()->create([
            'event_id' => $event->id,
            'vendor_profile_id' => $vendor->id,
            'invited_by_user_id' => $actor->id,
            'role' => $role,
            'status' => 'pending',
            'invited_at' => now(),
        ]));
        if ($assignment === null) {
            throw ApiException::conflict('This vendor is already on the event or has a pending invite.');
        }

        $this->notifications->notify($vendor->user, 'vendor_invite', [
            'actor' => $actor,
            'tokens' => ['event' => $event->title],
            'data' => ['eventId' => $event->id, 'collaborationId' => $assignment->id],
            'subject' => $assignment,
            'email' => ['inviterName' => $actor->display_name, 'eventTitle' => $event->title, 'role' => $role ?: 'vendor', 'appUrl' => Platform::webUrl('/notifications')],
        ]);
        $this->activity->log($event, $actor, 'vendor.invited', 'Invited '.$vendor->business_name.' as a vendor');

        return $assignment->setRelation('vendor', $vendor);
    }

    public function removeVendor(Event $event, User $actor, string $assignmentId): void
    {
        $this->access->authorize($actor, $event, 'manage_vendors');
        $assignment = EventVendorAssignment::query()->with('vendor')->where('event_id', $event->id)->whereIn('status', ['pending', 'accepted'])->findOrFail($assignmentId);
        $assignment->forceFill(['status' => 'removed', 'responded_at' => now()])->save();
        DB::table('event_task_assignees')->whereIn('task_id', DB::table('event_tasks')->where('event_id', $event->id)->select('id'))
            ->where('user_id', $assignment->vendor->user_id)->delete();
        $this->activity->log($event, $actor, 'vendor.removed', 'Removed '.$assignment->vendor->business_name.' from the event');
    }

    /** Accept or decline a manager or vendor invite addressed to the user. */
    public function respond(User $user, string $collaborationId, string $status): EventManager|EventVendorAssignment
    {
        $accept = $status === 'accepted';
        $item = EventManager::query()->where('user_id', $user->id)->find($collaborationId)
            ?? EventVendorAssignment::query()->whereHas('vendor', fn ($q) => $q->where('user_id', $user->id))->find($collaborationId);
        if ($item === null) {
            throw ApiException::notFound('Invite not found.');
        }
        $current = $item->status instanceof \BackedEnum ? $item->status->value : $item->status;
        if ($current !== 'pending') {
            throw ApiException::conflict('This invite is no longer pending.');
        }

        $event = Event::query()->findOrFail($item->event_id);
        DB::transaction(function () use ($item, $accept, $event, $user) {
            $item->forceFill(['status' => $accept ? 'accepted' : 'declined', 'responded_at' => now()])->save();
            if ($accept && $event->community_enabled) {
                $community = Community::query()->where('event_id', $event->id)->first();
                if ($community) {
                    $this->communities->addMember($community, $user->id, $item instanceof EventManager ? CommunityRole::CoAdmin : CommunityRole::Member);
                }
            }
            AppNotification::query()->where('user_id', $user->id)->where('subject_id', $item->id)->where('action', 'accept_decline')
                ->update(['action_state' => $accept ? 'accepted' : 'declined', 'read_at' => now()]);
        });

        $isManager = $item instanceof EventManager;
        $this->activity->log($event, $user, ($isManager ? 'manager.' : 'vendor.').($accept ? 'accepted' : 'declined'), ($accept ? 'Accepted' : 'Declined').' the '.($isManager ? 'manager' : 'vendor').' invite');
        $inviter = User::query()->find($item->invited_by_user_id ?? $event->organizer_user_id);
        if ($inviter) {
            $this->notifications->notify($inviter, $accept ? 'manager_invite_accepted' : 'collaboration_declined', [
                'actor' => $user,
                'tokens' => ['event' => $event->title],
                'data' => ['eventId' => $event->id],
            ]);
        }

        return $item->refresh();
    }

    /** EventCollaboration[] for GET /me/collaborations. */
    public function forUser(User $user): array
    {
        $managers = EventManager::query()->with('event.organizer')->where('user_id', $user->id)->whereIn('status', ['pending', 'accepted'])->latest('invited_at')->get();
        $vendorIds = VendorProfile::query()->where('user_id', $user->id)->pluck('id');
        $assignments = EventVendorAssignment::query()->with('event.organizer')->whereIn('vendor_profile_id', $vendorIds)->whereIn('status', ['pending', 'accepted'])->latest('invited_at')->get();

        return $managers->concat($assignments)->filter(fn ($c) => $c->event !== null)
            ->sortByDesc(fn ($c) => $c->invited_at)->values()
            ->map(fn ($c) => self::collaboration($c))->all();
    }

    public static function collaboration(EventManager|EventVendorAssignment $c): array
    {
        $event = $c->event;

        return [
            'id' => $c->id,
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'startsAt' => Fmt::date($event->starts_at) ?? Fmt::date($event->created_at),
                'coverUrl' => $event->cover_url ?: EventPresenter::placeholderCover(),
                'organizer' => Fmt::user($event->organizer),
            ],
            'status' => is_string($c->status) ? $c->status : $c->status->value,
            'invitedAt' => Fmt::date($c->invited_at),
        ];
    }

    public static function manager(EventManager $m): array
    {
        return [
            'id' => $m->id,
            'user' => Fmt::user($m->user),
            'access' => $m->access,
            'permissions' => $m->access === 'full' ? ManagerPermission::values() : array_values($m->permissions ?? []),
            'status' => $m->status->value,
            'invitedAt' => Fmt::date($m->invited_at),
            'respondedAt' => Fmt::date($m->responded_at),
        ];
    }

    public static function vendorAssignment(EventVendorAssignment $a): array
    {
        $vendor = $a->vendor;

        return [
            'id' => $a->id,
            'vendor' => [
                'id' => $vendor->id,
                'businessName' => $vendor->business_name,
                'category' => $vendor->category?->name ?? '',
                'avatarUrl' => $vendor->avatar_url,
                'userId' => $vendor->user_id,
            ],
            'role' => $a->role,
            'status' => $a->status,
            'invitedAt' => Fmt::date($a->invited_at),
        ];
    }
}
