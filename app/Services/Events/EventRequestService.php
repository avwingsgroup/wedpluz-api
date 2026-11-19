<?php

namespace App\Services\Events;

use App\Enums\EventStatus;
use App\Exceptions\ApiException;
use App\Http\Resources\Fmt;
use App\Models\Event;
use App\Models\EventRequest;
use App\Models\User;
use App\Services\Checkout\FreeTicketIssuer;
use App\Services\Notifications\NotificationService;
use App\Services\Realtime\Realtime;
use App\Support\Platform;
use App\Support\Sql;
use Illuminate\Support\Facades\DB;

/**
 * Attendance requests for approval-only events. Approval lets the user proceed
 * to checkout (paid events) or issues a ticket directly (free events).
 */
class EventRequestService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly ActivityLogger $activity,
        private readonly FreeTicketIssuer $freeTickets,
    ) {}

    public function submit(Event $event, User $user, string $firstName, string $lastName): EventRequest
    {
        if ($event->status !== EventStatus::Live) {
            throw ApiException::conflict('This event is not accepting requests.');
        }
        if (! $event->approval_required) {
            throw ApiException::validation('This event does not require approval. Get a ticket instead.', ['eventId' => ['Approval is not required for this event.']]);
        }
        if ($event->organizer_user_id === $user->id) {
            throw ApiException::conflict('You are the organiser of this event.');
        }

        $request = Sql::insertOrNull(fn () => EventRequest::query()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'status' => 'pending',
        ]));
        if ($request === null) {
            throw ApiException::conflict('You have already requested to attend this event.');
        }

        $organizer = User::query()->find($event->organizer_user_id);
        if ($organizer) {
            $this->notifications->notify($organizer, 'event_request_received', [
                'actor' => $user,
                'tokens' => ['event' => $event->title],
                'data' => ['eventId' => $event->id, 'requestId' => $request->id],
                'link' => Platform::webUrl('/manage/events/'.$event->id.'/requests'),
            ]);
        }
        Realtime::emit('event.'.$event->id.'.manage', 'request.created', self::resource($request->setRelation('event', $event)->setRelation('user', $user)));

        return $request;
    }

    public function decide(Event $event, EventRequest $request, User $actor, string $status): EventRequest
    {
        $changed = DB::transaction(function () use ($event, $request, $actor, $status) {
            $locked = EventRequest::query()->whereKey($request->id)->lockForUpdate()->first();
            if ($locked->status === $status) {
                return false;
            }
            if ($locked->status !== 'pending' && ! ($locked->status === 'approved' && $status === 'declined')) {
                throw ApiException::conflict('This request was already decided.');
            }
            $locked->forceFill(['status' => $status, 'decided_by_user_id' => $actor->id, 'decided_at' => now()])->save();
            $request->setRawAttributes($locked->getAttributes(), true);

            if ($status === 'approved' && $event->is_free) {
                $this->freeTickets->issueForApprovedRequest($event, $locked);
            }
            $this->activity->log($event, $actor, 'request.'.$status, ucfirst($status).' the request from '.$locked->first_name.' '.$locked->last_name);

            return true;
        });

        if ($changed) {
            $user = User::query()->find($request->user_id);
            if ($user) {
                $this->notifications->notify($user, $status === 'approved' ? 'event_request_approved' : 'event_request_declined', [
                    'tokens' => ['event' => $event->title],
                    'data' => ['eventId' => $event->id, 'eventSlug' => $event->slug],
                    'link' => Platform::webUrl('/events/'.$event->slug),
                    'email' => ['title' => 'Request '.$status, 'body' => 'Your request to attend '.$event->title.' was '.$status.'.'],
                ]);
            }
            Realtime::emit('event.'.$event->id.'.manage', 'request.updated', self::resource($request->load(['event', 'user'])));
        }

        return $request;
    }

    public static function resource(EventRequest $r): array
    {
        $event = $r->event;

        return [
            'id' => $r->id,
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'startsAt' => Fmt::date($event->starts_at) ?? Fmt::date($event->created_at),
                'coverUrl' => $event->cover_url ?: EventPresenter::placeholderCover(),
            ],
            'user' => Fmt::user($r->user),
            'firstName' => $r->first_name,
            'lastName' => $r->last_name,
            'status' => $r->status,
            'createdAt' => Fmt::date($r->created_at),
        ];
    }
}
