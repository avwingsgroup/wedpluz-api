<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizer\BulkDecideRequest;
use App\Http\Requests\Organizer\CheckInRequest;
use App\Http\Requests\Organizer\CollaborationResponseRequest;
use App\Http\Requests\Organizer\CommunitySettingsRequest;
use App\Http\Requests\Organizer\DecideRequestRequest;
use App\Http\Requests\Organizer\EventPayloadRequest;
use App\Http\Requests\Organizer\EventStatusRequest;
use App\Http\Requests\Organizer\InviteManagerRequest;
use App\Http\Requests\Organizer\InviteVendorRequest;
use App\Http\Requests\Organizer\PromotionRequest;
use App\Http\Requests\Organizer\TaskRequest;
use App\Http\Requests\Organizer\UpdateManagerRequest;
use App\Http\Requests\Organizer\UpdateTaskRequest;
use App\Http\Resources\Fmt;
use App\Models\ActivityLog;
use App\Models\CheckIn;
use App\Models\Community;
use App\Models\Event;
use App\Models\EventManager;
use App\Models\EventRequest;
use App\Models\EventTask;
use App\Models\EventVendorAssignment;
use App\Models\PromoterEnrollment;
use App\Models\Ticket;
use App\Services\Checkout\CheckInService;
use App\Services\Communities\CommunityPresenter;
use App\Services\Communities\CommunityService;
use App\Services\Events\CollaborationService;
use App\Services\Events\EventAccess;
use App\Services\Events\EventPresenter;
use App\Services\Events\EventRequestService;
use App\Services\Events\EventService;
use App\Services\Events\PromotionService;
use App\Services\Events\TaskService;
use App\Support\ListQuery;
use App\Support\Platform;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/** /organizer/events/* — event management, scoped by event-level roles on every call. */
class OrganizerController extends Controller
{
    public function __construct(
        private readonly EventAccess $access,
        private readonly EventPresenter $presenter,
        private readonly EventService $events,
    ) {}

    // ---- Events ----------------------------------------------------------------

    public function index(Request $request): JsonResponse
    {
        $user = $this->customer();
        $list = ListQuery::from($request);
        $tab = (string) $request->query('tab', 'created');

        $query = Event::query()->select('events.*');
        if ($tab === 'team') {
            $query->where(fn ($q) => $q
                ->whereIn('events.id', DB::table('event_managers')->where('user_id', $user->id)->where('status', 'accepted')->select('event_id'))
                ->orWhereIn('events.id', DB::table('event_vendor_assignments')->join('vendor_profiles', 'vendor_profiles.id', '=', 'event_vendor_assignments.vendor_profile_id')
                    ->where('vendor_profiles.user_id', $user->id)->where('event_vendor_assignments.status', 'accepted')->select('event_vendor_assignments.event_id')));
        } else {
            $query->where('events.organizer_user_id', $user->id);
        }
        if ($status = $list->filter('status')) {
            match ($status) {
                'upcoming', 'active' => $query->whereIn('events.status', ['live', 'pending_review'])->whereRaw('coalesce(events.ends_at, events.starts_at, now()) >= now()'),
                'past' => $query->where(fn ($q) => $q->where('events.status', 'completed')->orWhereRaw('coalesce(events.ends_at, events.starts_at) < now()')),
                default => $query->where('events.status', $status),
            };
        }
        if ($pattern = $list->searchPattern()) {
            $query->where('events.title', 'ilike', $pattern);
        }
        $query->orderByRaw('events.starts_at desc nulls first')->orderByDesc('events.created_at');
        $page = $list->paginate($query);

        return response()->json(['data' => $this->presenter->manageSummaries($page->items(), $user), 'meta' => ListQuery::meta($page)]);
    }

    public function store(EventPayloadRequest $request): JsonResponse
    {
        $event = $this->events->create($this->customer(), $request->validated());

        return $this->created($this->presenter->detail($event, $this->customer()), 'Draft saved.');
    }

    public function show(string $id): JsonResponse
    {
        return $this->ok($this->presenter->managed($this->event($id), $this->customer()));
    }

    public function update(EventPayloadRequest $request, string $id): JsonResponse
    {
        $event = $this->events->update($this->event($id), $this->customer(), $request->validated());

        return $this->ok($this->presenter->detail($event, $this->customer()), 'Event saved.');
    }

    public function publish(string $id): JsonResponse
    {
        $event = $this->events->publish($this->event($id), $this->customer());
        $message = $event->status->value === 'pending_review' ? 'Submitted for review. We will notify you once it is approved.' : 'Your event is live.';

        return $this->ok($this->presenter->detail($event, $this->customer()), $message);
    }

    public function destroy(string $id): Response
    {
        $this->events->delete($this->event($id), $this->customer());

        return $this->noContent();
    }

    public function status(EventStatusRequest $request, string $id): JsonResponse
    {
        $event = $this->events->changeStatus($this->event($id), $this->customer(), $request->string('status'), $request->input('cancellationReason'));

        return $this->ok($this->presenter->detail($event, $this->customer()));
    }

    public function analytics(string $id): JsonResponse
    {
        $event = $this->event($id);
        $user = $this->customer();
        if (! $this->access->can($user, $event, 'view_revenue') && ! $this->access->can($user, $event, 'manage_guests')) {
            throw ApiException::forbidden('You do not have access to analytics for this event.');
        }
        $revenueVisible = $this->access->can($user, $event, 'view_revenue');
        $stats = $this->presenter->stats($event);

        $byType = DB::table('ticket_types')->where('event_id', $event->id)->whereNull('deleted_at')->orderBy('sort_order')
            ->get(['id', 'name', 'price', 'sold', 'quantity', 'sales_end_at']);
        $revenueByType = DB::table('order_lines')->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->where('orders.event_id', $event->id)->where('orders.status', 'paid')
            ->groupBy('order_lines.ticket_type_id')->selectRaw('order_lines.ticket_type_id, sum(order_lines.subtotal) as revenue')
            ->pluck('revenue', 'ticket_type_id');

        $tz = str_replace("'", '', Platform::timezone());
        $salesByDay = DB::table('orders')->join('order_lines', 'order_lines.order_id', '=', 'orders.id')
            ->where('orders.event_id', $event->id)->where('orders.status', 'paid')->where('orders.paid_at', '>=', now()->subDays(30))
            ->groupByRaw("(orders.paid_at at time zone '$tz')::date")
            ->selectRaw("(orders.paid_at at time zone '$tz')::date as day, sum(order_lines.quantity) as tickets, sum(order_lines.subtotal) as revenue")
            ->orderBy('day')->get();

        $recent = Ticket::query()->with(['holder', 'ticketType'])->where('event_id', $event->id)->where('is_additional_guest', false)
            ->whereIn('status', ['valid', 'checked_in'])->latest()->limit(10)->get();

        return $this->ok([
            'revenue' => $revenueVisible ? $stats['revenue'] : 0,
            'ticketsSold' => $stats['ticketsSold'],
            'checkedIn' => $stats['checkedIn'],
            'capacity' => $stats['capacity'],
            'byTicketType' => $byType->map(fn ($t) => [
                'ticketTypeId' => $t->id, 'name' => $t->name, 'price' => (int) $t->price, 'sold' => (int) $t->sold, 'quantity' => (int) $t->quantity,
                'revenue' => $revenueVisible ? (int) ($revenueByType[$t->id] ?? 0) : 0,
                'salesEndAt' => $t->sales_end_at ? Fmt::date(CarbonImmutable::parse($t->sales_end_at)) : null,
            ])->all(),
            'salesByDay' => $salesByDay->map(fn ($d) => ['date' => (string) $d->day, 'tickets' => (int) $d->tickets, 'revenue' => $revenueVisible ? (int) $d->revenue : 0])->all(),
            'recentBuyers' => $recent->map(fn (Ticket $t) => ['user' => Fmt::user($t->holder), 'ticketTypeName' => $t->ticketType?->name ?? 'Ticket', 'purchasedAt' => Fmt::date($t->created_at)])->all(),
        ]);
    }

    // ---- Guests, requests, check-ins -----------------------------------------------

    public function guests(Request $request, string $id): JsonResponse
    {
        $event = $this->event($id);
        $user = $this->customer();
        if (! $this->access->can($user, $event, 'manage_guests') && ! $this->access->can($user, $event, 'scan_tickets')) {
            throw ApiException::forbidden('You do not have access to the guest list.');
        }
        $list = ListQuery::from($request);
        $query = Ticket::query()->with(['holder', 'ticketType', 'order', 'schedule'])->where('tickets.event_id', $event->id)
            ->when($list->filter('filter'), fn ($q, $f) => match ($f) {
                'checked_in' => $q->where('tickets.status', 'checked_in'),
                'not_checked_in' => $q->where('tickets.status', 'valid'),
                'cancelled' => $q->whereIn('tickets.status', ['cancelled', 'transferred']),
                'guests' => $q->where('tickets.is_additional_guest', true),
                default => $q,
            }, fn ($q) => $q->whereIn('tickets.status', ['valid', 'checked_in']))
            ->when($list->searchPattern(), fn ($q, $p) => $q->where(fn ($w) => $w->where('tickets.holder_name', 'ilike', $p)->orWhere('tickets.code', 'ilike', $p)->orWhere('tickets.holder_email', 'ilike', $p)))
            ->orderBy('tickets.holder_name');
        $page = $list->paginate($query, 50);
        $requests = DB::table('event_requests')->where('event_id', $event->id)->whereIn('user_id', collect($page->items())->pluck('holder_user_id')->filter())->pluck('status', 'user_id');
        $subEvents = DB::table('sub_events')->where('event_id', $event->id)->get()->groupBy('schedule_id');

        return $this->paginated($page, fn (Ticket $t) => [
            'id' => $t->id,
            'user' => Fmt::user($t->holder) ?? ['id' => '', 'displayName' => $t->holder_name, 'avatarUrl' => null],
            'ticketTypeName' => $t->is_additional_guest ? 'Guest' : ($t->ticketType?->name ?? 'Ticket'),
            'ticketStatus' => $t->status->value,
            'checkedInAt' => Fmt::date($t->checked_in_at),
            'requestStatus' => $requests[$t->holder_user_id] ?? null,
            'paymentStatus' => ($t->order?->total ?? 0) === 0 ? 'free' : ($t->order?->paid_at ? 'paid' : 'unpaid'),
            'subEventTitle' => $t->schedule_id ? ($subEvents[$t->schedule_id][0]->title ?? null) : null,
        ]);
    }

    public function requests(Request $request, string $id): JsonResponse
    {
        $event = $this->event($id);
        $this->access->authorize($this->customer(), $event, 'manage_guests');
        $list = ListQuery::from($request);
        $query = EventRequest::query()->with(['event', 'user'])->where('event_id', $event->id)
            ->when($list->filter('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")->orderByDesc('created_at');

        return $this->paginated($list->paginate($query), fn (EventRequest $r) => EventRequestService::resource($r));
    }

    public function decideRequest(DecideRequestRequest $request, string $id, string $requestId, EventRequestService $service): JsonResponse
    {
        $event = $this->event($id);
        $this->access->authorize($this->customer(), $event, 'manage_guests');
        $item = EventRequest::query()->where('event_id', $event->id)->findOrFail($requestId);

        return $this->ok(EventRequestService::resource($service->decide($event, $item, $this->customer(), $request->string('status'))->load(['event', 'user'])));
    }

    public function bulkDecide(BulkDecideRequest $request, string $id, EventRequestService $service): JsonResponse
    {
        $event = $this->event($id);
        $this->access->authorize($this->customer(), $event, 'manage_guests');
        $updated = 0;
        foreach (EventRequest::query()->where('event_id', $event->id)->whereIn('id', $request->validated('ids'))->where('status', 'pending')->get() as $item) {
            $service->decide($event, $item, $this->customer(), $request->string('status'));
            $updated++;
        }

        return $this->ok(['updated' => $updated]);
    }

    public function checkIns(Request $request, string $id): JsonResponse
    {
        $event = $this->event($id);
        $this->access->authorize($this->customer(), $event, 'scan_tickets');
        $query = CheckIn::query()->with(['ticket.holder', 'ticket.ticketType', 'scanner'])->where('event_id', $event->id)->where('result', 'ok')->latest();

        return $this->paginated(ListQuery::from($request)->paginate($query, 50), fn (CheckIn $c) => CheckInService::record($c));
    }

    public function checkIn(CheckInRequest $request, string $id, CheckInService $checkIns): JsonResponse
    {
        $event = $this->event($id);
        $this->access->authorize($this->customer(), $event, 'scan_tickets');
        $device = $request->input('device') ?: mb_substr((string) $request->userAgent(), 0, 255);

        return $this->ok($checkIns->validate($event, $this->customer(), $request->string('code'), $request->string('method'), $device));
    }

    // ---- Managers & vendors ------------------------------------------------------------

    public function managers(Request $request, string $id): JsonResponse
    {
        $event = $this->event($id);
        $search = ListQuery::from($request)->searchPattern();
        $managers = EventManager::query()->with('user')->where('event_id', $event->id)->whereIn('status', ['pending', 'accepted'])
            ->when($search, fn ($q, $p) => $q->whereHas('user', fn ($u) => $u->where('display_name', 'ilike', $p)))->orderBy('invited_at')->get();

        return $this->ok($managers->map(fn ($m) => CollaborationService::manager($m))->all());
    }

    public function inviteManager(InviteManagerRequest $request, string $id, CollaborationService $collab): JsonResponse
    {
        $manager = $collab->inviteManager($this->event($id), $this->customer(), $request->input('userId'), $request->input('email'), $request->string('access'), $request->validated('permissions') ?? []);

        return $this->created(CollaborationService::manager($manager), 'Invite sent.');
    }

    public function updateManager(UpdateManagerRequest $request, string $id, string $managerId, CollaborationService $collab): JsonResponse
    {
        $manager = $collab->updateManager($this->event($id), $this->customer(), $managerId, $request->input('access'), $request->validated('permissions'));

        return $this->ok(CollaborationService::manager($manager));
    }

    public function removeManager(string $id, string $managerId, CollaborationService $collab): Response
    {
        $collab->removeManager($this->event($id), $this->customer(), $managerId);

        return $this->noContent();
    }

    public function vendors(Request $request, string $id): JsonResponse
    {
        $event = $this->event($id);
        $search = ListQuery::from($request)->searchPattern();
        $assignments = EventVendorAssignment::query()->with('vendor.category')->where('event_id', $event->id)->whereIn('status', ['pending', 'accepted'])
            ->when($search, fn ($q, $p) => $q->whereHas('vendor', fn ($v) => $v->where('business_name', 'ilike', $p)))->orderBy('invited_at')->get();

        return $this->ok($assignments->map(fn ($a) => CollaborationService::vendorAssignment($a))->all());
    }

    public function inviteVendor(InviteVendorRequest $request, string $id, CollaborationService $collab): JsonResponse
    {
        $assignment = $collab->inviteVendor($this->event($id), $this->customer(), $request->string('vendorId'), $request->input('role'));

        return $this->created(CollaborationService::vendorAssignment($assignment->load('vendor.category')), 'Invite sent.');
    }

    public function removeVendor(string $id, string $assignmentId, CollaborationService $collab): Response
    {
        $collab->removeVendor($this->event($id), $this->customer(), $assignmentId);

        return $this->noContent();
    }

    // ---- Tasks & activity --------------------------------------------------------------

    public function tasks(Request $request, string $id, TaskService $tasks): JsonResponse
    {
        $list = $tasks->list($this->event($id), $this->customer(), $request->query('assigneeId'), $request->query('search'), (string) $request->query('sort', 'created'));

        return $this->ok($list->map(fn ($t) => TaskService::resource($t))->all());
    }

    public function createTask(TaskRequest $request, string $id, TaskService $tasks): JsonResponse
    {
        return $this->created(TaskService::resource($tasks->create($this->event($id), $this->customer(), $request->validated())));
    }

    public function updateTask(UpdateTaskRequest $request, string $id, string $taskId, TaskService $tasks): JsonResponse
    {
        $event = $this->event($id);
        $task = EventTask::query()->where('event_id', $event->id)->findOrFail($taskId);

        return $this->ok(TaskService::resource($tasks->update($event, $this->customer(), $task, $request->validated())));
    }

    public function deleteTask(string $id, string $taskId, TaskService $tasks): Response
    {
        $event = $this->event($id);
        $tasks->delete($event, $this->customer(), EventTask::query()->where('event_id', $event->id)->findOrFail($taskId));

        return $this->noContent();
    }

    public function activity(Request $request, string $id): JsonResponse
    {
        $event = $this->event($id);
        if ($this->access->role($this->customer(), $event) === 'vendor') {
            throw ApiException::forbidden('Vendors cannot view the activity log.');
        }
        $list = ListQuery::from($request);
        $query = ActivityLog::query()->with('actor')->where('event_id', $event->id)
            ->when($list->filter('role'), fn ($q, $role) => $q->where('actor_role', rtrim($role, 's')))
            ->orderByDesc('created_at');

        return $this->paginated($list->paginate($query), fn (ActivityLog $a) => [
            'id' => $a->id,
            'actor' => Fmt::user($a->actor) ?? ['id' => 'system', 'displayName' => 'Wedpluz', 'avatarUrl' => null],
            'actorRole' => $a->actor_role,
            'action' => $a->action,
            'description' => $a->description,
            'createdAt' => Fmt::date($a->created_at),
        ]);
    }

    // ---- Promotion & community ------------------------------------------------------------

    public function promotion(string $id, PromotionService $promotions): JsonResponse
    {
        return $this->ok($promotions->settings($this->event($id)));
    }

    public function updatePromotion(PromotionRequest $request, string $id, PromotionService $promotions): JsonResponse
    {
        return $this->ok($promotions->update($this->event($id), $this->customer(), $request->safe()->except('promotersCount')));
    }

    public function promoters(string $id, PromotionService $promotions): JsonResponse
    {
        $event = $this->event($id);
        $this->access->authorize($this->customer(), $event, 'view_revenue');

        return $this->ok($promotions->leaderboard($event));
    }

    public function community(string $id, CommunityPresenter $presenter): JsonResponse
    {
        $event = $this->event($id);
        $community = Community::query()->where('event_id', $event->id)->first();

        return $this->ok($community ? $presenter->one($community, $this->customer()) : null);
    }

    public function updateCommunity(CommunitySettingsRequest $request, string $id, CommunityService $communities, CommunityPresenter $presenter): JsonResponse
    {
        $event = $this->event($id);
        $this->access->authorize($this->customer(), $event, 'manage_community');
        $enabled = $request->boolean('enabled');

        $community = DB::transaction(function () use ($event, $enabled, $request, $communities) {
            $event->forceFill(['community_enabled' => $enabled])->save();
            $community = Community::query()->where('event_id', $event->id)->first();
            if ($enabled && $community === null && in_array($event->status->value, ['live', 'completed'], true)) {
                $community = $communities->ensureEventCommunity($event);
            }
            if ($community) {
                $community->forceFill([
                    'status' => $enabled ? 'active' : 'archived',
                    'permissions' => array_replace($community->permissionSet(), array_map('boolval', $request->validated('permissions') ?? [])),
                ])->save();
            }

            return $community;
        });

        return $this->ok($community ? $presenter->one($community->refresh(), $this->customer()) : null);
    }

    // ---- My collaborations & promotions ------------------------------------------------------

    public function collaborations(CollaborationService $collab): JsonResponse
    {
        return $this->ok($collab->forUser($this->customer()));
    }

    public function respondCollaboration(CollaborationResponseRequest $request, string $id, CollaborationService $collab): JsonResponse
    {
        $item = $collab->respond($this->customer(), $id, $request->string('status'));

        return $this->ok(CollaborationService::collaboration($item->load('event.organizer')));
    }

    public function myPromotions(Request $request, PromotionService $promotions): JsonResponse
    {
        $list = ListQuery::from($request);
        $status = $list->filter('status');
        $query = PromoterEnrollment::query()->select('promoter_enrollments.*')
            ->join('events', 'events.id', '=', 'promoter_enrollments.event_id')
            ->where('promoter_enrollments.user_id', $this->customer()->id)
            ->when($status === 'active', fn ($q) => $q->whereNotIn('events.status', ['completed', 'cancelled'])->whereRaw('coalesce(events.ends_at, events.starts_at, now()) >= now()'))
            ->when($status === 'past', fn ($q) => $q->where(fn ($w) => $w->whereIn('events.status', ['completed', 'cancelled'])->orWhereRaw('coalesce(events.ends_at, events.starts_at) < now()')))
            ->when($list->searchPattern(), fn ($q, $p) => $q->where('events.title', 'ilike', $p))
            ->orderByDesc('promoter_enrollments.joined_at');
        $page = $list->paginate($query);

        return response()->json(['data' => $promotions->resources(collect($page->items()), $this->customer()), 'meta' => ListQuery::meta($page)]);
    }

    public function myPromotion(string $id, PromotionService $promotions): JsonResponse
    {
        $enrollment = PromoterEnrollment::query()->where('user_id', $this->customer()->id)->findOrFail($id);

        return $this->ok($promotions->resources(collect([$enrollment]), $this->customer())[0]);
    }

    public function joinPromotion(string $eventId, PromotionService $promotions): JsonResponse
    {
        $event = Event::query()->find($eventId) ?? throw ApiException::notFound('Event not found.');
        $enrollment = $promotions->join($event, $this->customer());

        return $this->created($promotions->resources(collect([$enrollment]), $this->customer())[0], 'You are now promoting this event.');
    }

    private function event(string $id): Event
    {
        return $this->access->findForTeam($id, $this->customer());
    }
}
