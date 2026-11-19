<?php

namespace App\Services\Events;

use App\Enums\EventStatus;
use App\Exceptions\ApiException;
use App\Models\Event;
use App\Models\EventManager;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Event-level authorisation (authentication-and-permissions.md §5).
 *
 * Roles: organizer (owner) > manager (full | partial with permissions) >
 * vendor (accepted assignment: view event, own tasks, community).
 * Platform roles grant nothing here. Every /organizer/events/{id}/* call
 * resolves access through this class.
 */
class EventAccess
{
    /** @var array<string, array{role:?string, manager:?EventManager}> */
    private array $memo = [];

    public function role(User $user, Event $event): ?string
    {
        return $this->resolve($user, $event)['role'];
    }

    public function manager(User $user, Event $event): ?EventManager
    {
        return $this->resolve($user, $event)['manager'];
    }

    public function can(User $user, Event $event, string $permission): bool
    {
        $resolved = $this->resolve($user, $event);

        return match ($resolved['role']) {
            'organizer' => true,
            'manager' => $resolved['manager']->grants($permission),
            default => false,
        };
    }

    /** Load a managed event the user has any role on, else 404 (do not leak existence). */
    public function findForTeam(string $eventId, User $user): Event
    {
        $event = Event::query()->find($eventId);
        if ($event === null || $this->role($user, $event) === null) {
            throw ApiException::notFound('Event not found.');
        }

        return $event;
    }

    /** Require an event permission (organizer always passes). */
    public function authorize(User $user, Event $event, string $permission): void
    {
        if (! $this->can($user, $event, $permission)) {
            throw ApiException::forbidden('You do not have permission to do this for this event.');
        }
    }

    public function requireOrganizer(User $user, Event $event): void
    {
        if ($this->role($user, $event) !== 'organizer') {
            throw ApiException::forbidden('Only the event organiser can do this.');
        }
    }

    /** Organizer or a full-access manager. */
    public function requireFullAccess(User $user, Event $event): void
    {
        $resolved = $this->resolve($user, $event);
        if ($resolved['role'] !== 'organizer' && ! ($resolved['role'] === 'manager' && $resolved['manager']->access === 'full')) {
            throw ApiException::forbidden('You need full access to this event to do this.');
        }
    }

    /** Can the user see this event's public page (incl. non-live states)? */
    public function canView(?User $user, Event $event): bool
    {
        if ($event->status === EventStatus::Live || $event->status === EventStatus::Completed || $event->status === EventStatus::Cancelled) {
            if ($event->visibility === 'public') {
                return true;
            }
        }
        if ($user === null) {
            // Private live events are reachable by slug through invite/share links.
            return in_array($event->status, [EventStatus::Live, EventStatus::Completed, EventStatus::Cancelled], true);
        }
        if ($this->role($user, $event) !== null) {
            return true;
        }

        return in_array($event->status, [EventStatus::Live, EventStatus::Completed, EventStatus::Cancelled], true);
    }

    /** @return array{role:?string, manager:?EventManager} */
    private function resolve(User $user, Event $event): array
    {
        $key = $user->id.'|'.$event->id;
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        if ($event->organizer_user_id === $user->id) {
            return $this->memo[$key] = ['role' => 'organizer', 'manager' => null];
        }

        $manager = EventManager::query()->where('event_id', $event->id)->where('user_id', $user->id)->where('status', 'accepted')->first();
        if ($manager) {
            return $this->memo[$key] = ['role' => 'manager', 'manager' => $manager];
        }

        $isVendor = DB::table('event_vendor_assignments')
            ->join('vendor_profiles', 'vendor_profiles.id', '=', 'event_vendor_assignments.vendor_profile_id')
            ->where('event_vendor_assignments.event_id', $event->id)
            ->where('vendor_profiles.user_id', $user->id)
            ->where('event_vendor_assignments.status', 'accepted')
            ->exists();

        return $this->memo[$key] = ['role' => $isVendor ? 'vendor' : null, 'manager' => null];
    }
}
