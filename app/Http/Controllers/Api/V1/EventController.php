<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EventStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventCategory;
use App\Services\Events\EventAccess;
use App\Services\Events\EventDiscovery;
use App\Services\Events\EventPresenter;
use App\Services\Events\EventRequestService;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/** Public event discovery, categories, saved events and attendance requests. */
class EventController extends Controller
{
    public function __construct(
        private readonly EventDiscovery $discovery,
        private readonly EventPresenter $presenter,
        private readonly EventAccess $access,
    ) {}

    public function categories(): JsonResponse
    {
        $counts = DB::table('events')->where('status', 'live')->where('visibility', 'public')->whereNull('deleted_at')
            ->selectRaw('category_id, count(*) as c')->groupBy('category_id')->pluck('c', 'category_id');

        return $this->ok(EventCategory::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn (EventCategory $c) => self::category($c, (int) ($counts[$c->id] ?? 0)))->all());
    }

    public function index(Request $request): JsonResponse
    {
        $page = ListQuery::from($request)->paginate($this->discovery->search($request));

        return response()->json([
            'data' => $this->presenter->summaries($page->items(), $this->optionalCustomer()),
            'meta' => ListQuery::meta($page),
        ]);
    }

    public function featured(): JsonResponse
    {
        $events = $this->discovery->base()->where('is_featured', true)->orderBy('starts_at')->limit(12)->get();

        return $this->ok($this->presenter->summaries($events, $this->optionalCustomer()));
    }

    public function upcoming(Request $request): JsonResponse
    {
        $location = trim((string) $request->query('location', ''));
        $events = $this->discovery->base()->where('starts_at', '>=', now())
            ->when($location !== '', fn ($q) => $q->where('city', 'ilike', '%'.addcslashes($location, '%_\\').'%'))
            ->orderBy('starts_at')->limit(12)->get();

        return $this->ok($this->presenter->summaries($events, $this->optionalCustomer()));
    }

    public function popular(): JsonResponse
    {
        $events = $this->discovery->base()->orderByDesc('tickets_sold')->orderByDesc('saves_count')->orderBy('starts_at')->limit(12)->get();

        return $this->ok($this->presenter->summaries($events, $this->optionalCustomer()));
    }

    public function promoted(): JsonResponse
    {
        $events = $this->discovery->base()->whereHas('promotion', fn ($q) => $q->where('enabled', true))->orderBy('starts_at')->limit(24)->get();

        return $this->ok($this->presenter->summaries($events, $this->optionalCustomer()));
    }

    public function show(string $slug): JsonResponse|RedirectResponse
    {
        $event = Event::query()->where('slug', $slug)->orWhere('id', $slug)->first();
        if ($event === null && ($moved = $this->movedTo('event', $slug, Event::class))) {
            return $moved;
        }
        $viewer = $this->optionalCustomer();
        if ($event === null || ! $this->access->canView($viewer, $event)) {
            throw ApiException::notFound('Event not found.');
        }

        return $this->ok($this->presenter->detail($event, $viewer));
    }

    public function related(string $id): JsonResponse
    {
        $event = $this->findPublic($id);
        $events = $this->discovery->base()->where('id', '!=', $event->id)
            ->where(fn ($q) => $q->where('category_id', $event->category_id)->orWhere('city', $event->city))
            ->orderByRaw('case when category_id = ? then 0 else 1 end', [$event->category_id])->orderBy('starts_at')->limit(8)->get();

        return $this->ok($this->presenter->summaries($events, $this->optionalCustomer()));
    }

    public function organizerEvents(Request $request, string $id): JsonResponse
    {
        $event = $this->findPublic($id);
        $query = Event::query()->discoverable()->where('organizer_user_id', $event->organizer_user_id)->where('id', '!=', $event->id)
            ->orderByRaw('case when starts_at >= now() then 0 else 1 end')->orderBy('starts_at');
        $page = ListQuery::from($request)->paginate($query, 12);

        return response()->json(['data' => $this->presenter->summaries($page->items(), $this->optionalCustomer()), 'meta' => ListQuery::meta($page)]);
    }

    public function save(string $id): JsonResponse
    {
        $event = $this->findPublic($id);
        $inserted = DB::table('saved_events')->insertOrIgnore(['user_id' => $this->customer()->id, 'event_id' => $event->id, 'created_at' => now()]);
        if ($inserted) {
            Event::query()->whereKey($event->id)->increment('saves_count');
        }

        return $this->ok(['saved' => true]);
    }

    public function unsave(string $id): Response
    {
        $deleted = DB::table('saved_events')->where('user_id', $this->customer()->id)->where('event_id', $id)->delete();
        if ($deleted) {
            Event::query()->whereKey($id)->where('saves_count', '>', 0)->decrement('saves_count');
        }

        return $this->noContent();
    }

    public function saved(Request $request): JsonResponse
    {
        $list = ListQuery::from($request);
        $query = Event::query()->select('events.*')
            ->join('saved_events', 'saved_events.event_id', '=', 'events.id')
            ->where('saved_events.user_id', $this->customer()->id)
            ->whereIn('events.status', [EventStatus::Live->value, EventStatus::Completed->value, EventStatus::Cancelled->value])
            ->when($list->searchPattern(), fn ($q, $p) => $q->where('events.title', 'ilike', $p))
            ->orderByDesc('saved_events.created_at');
        $page = $list->paginate($query);

        return response()->json(['data' => $this->presenter->summaries($page->items(), $this->customer()), 'meta' => ListQuery::meta($page)]);
    }

    public function requestToAttend(Request $request, string $id, EventRequestService $requests): JsonResponse
    {
        $data = $request->validate([
            'firstName' => ['required', 'string', 'max:100'],
            'lastName' => ['required', 'string', 'max:100'],
        ]);
        $result = $requests->submit($this->findPublic($id), $this->customer(), $data['firstName'], $data['lastName']);

        return $this->ok(['status' => $result->status], 'Your request has been sent to the organiser.');
    }

    public function suggestions(Request $request): JsonResponse
    {
        return $this->ok($this->discovery->suggestions((string) $request->query('q', ''), $request->query('location')));
    }

    public function locations(Request $request): JsonResponse
    {
        return $this->ok($this->discovery->locations((string) $request->query('q', '')));
    }

    private function findPublic(string $id): Event
    {
        $event = Event::query()->find($id);
        if ($event === null || ! $this->access->canView($this->optionalCustomer(), $event)) {
            throw ApiException::notFound('Event not found.');
        }

        return $event;
    }

    public static function category(EventCategory $c, ?int $eventsCount = null): array
    {
        $data = ['id' => $c->id, 'name' => $c->name, 'slug' => $c->slug, 'icon' => $c->icon, 'isActive' => $c->is_active];
        if ($eventsCount !== null) {
            $data['eventsCount'] = $eventsCount;
        }

        return $data;
    }
}
