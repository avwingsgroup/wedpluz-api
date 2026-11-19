<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\EventStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\EventController;
use App\Http\Controllers\Controller;
use App\Http\Resources\ContentReportResource;
use App\Models\ContentReport;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\Order;
use App\Models\User;
use App\Services\Admin\AdminPresenter;
use App\Services\Admin\AuditLogger;
use App\Services\Events\EventPresenter;
use App\Services\Events\EventService;
use App\Services\Notifications\NotificationService;
use App\Support\ListQuery;
use App\Support\Platform;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Event moderation: review queue, approve/reject, suspend/restore, feature; categories. */
class AdminEventsController extends Controller
{
    public function __construct(
        private readonly AdminPresenter $presenter,
        private readonly AuditLogger $audit,
        private readonly EventService $events,
        private readonly NotificationService $notifications,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $list = ListQuery::from($request);
        $query = Event::query()
            ->when($list->searchPattern(), fn ($q, $p) => $q->where(fn ($w) => $w->where('title', 'ilike', $p)->orWhere('slug', 'ilike', $p)
                ->orWhereHas('organizer', fn ($o) => $o->where('display_name', 'ilike', $p)->orWhere('email', 'ilike', $p))))
            ->when($list->filter('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($list->filter('categoryId'), fn ($q, $c) => $q->where('category_id', $c))
            ->when($list->filter('attendanceMode'), fn ($q, $m) => $q->where('attendance_mode', $m))
            ->when($list->bool('featured') !== null, fn ($q) => $q->where('is_featured', $list->bool('featured')))
            ->when($list->dateFrom(), fn ($q, $d) => $q->where('starts_at', '>=', $d))
            ->when($list->dateTo(), fn ($q, $d) => $q->where('starts_at', '<=', $d));
        $list->applySort($query, ['createdAt' => 'created_at', 'startsAt' => 'starts_at', 'ticketsSold' => 'tickets_sold', 'submittedAt' => 'submitted_at', 'title' => 'title'], 'createdAt');
        $page = $list->paginate($query);

        return response()->json(['data' => $this->presenter->events($page->items()), 'meta' => ListQuery::meta($page)]);
    }

    public function show(string $id, EventPresenter $events): JsonResponse
    {
        $event = $this->find($id);
        $orders = Order::query()->where('event_id', $event->id)->latest()->limit(20)->get();
        $reports = ContentReport::query()->with(['reporter', 'reportedUser', 'assignee'])->where('target_type', 'event')->where('target_id', $event->id)->latest()->limit(50)->get();

        return $this->ok(array_merge($events->detail($event, null), $this->presenter->event($event), [
            'stats' => $events->stats($event),
            'bookings' => $this->presenter->bookings($orders),
            'reports' => ContentReportResource::collection($reports)->resolve(),
        ]));
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $event = $this->find($id);
        if ($event->status !== EventStatus::PendingReview) {
            throw ApiException::conflict('Only events pending review can be approved.');
        }
        DB::transaction(function () use ($event) {
            $event->forceFill(['status' => EventStatus::Live, 'published_at' => now(), 'reviewed_by_admin_id' => $this->admin()->id, 'reviewed_at' => now(), 'rejection_reason' => null])->save();
            $this->events->goLive($event);
        });
        $this->notifyOrganizer($event, 'Your event is live', $event->title.' was approved and is now live.');
        $this->audit->log('event.approved', 'events', $event, $event->title, ['status' => 'pending_review'], ['status' => 'live', 'reason' => $data['reason'] ?? null]);

        return $this->ok($this->presenter->event($event->refresh()), 'Event approved.');
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $event = $this->find($id);
        if ($event->status !== EventStatus::PendingReview) {
            throw ApiException::conflict('Only events pending review can be rejected.');
        }
        $event->forceFill(['status' => EventStatus::Rejected, 'rejection_reason' => $data['reason'], 'reviewed_by_admin_id' => $this->admin()->id, 'reviewed_at' => now()])->save();
        $this->notifyOrganizer($event, 'Event needs changes', $event->title.' was not approved: '.$data['reason']);
        $this->audit->log('event.rejected', 'events', $event, $event->title, ['status' => 'pending_review'], ['status' => 'rejected', 'reason' => $data['reason']]);

        return $this->ok($this->presenter->event($event->refresh()), 'Event rejected.');
    }

    public function suspend(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $event = $this->find($id);
        if (! in_array($event->status, [EventStatus::Live, EventStatus::Completed], true)) {
            throw ApiException::conflict('Only live or completed events can be suspended.');
        }
        DB::transaction(function () use ($event, $data) {
            $event->forceFill(['status_before_suspension' => $event->status->value, 'status' => EventStatus::Suspended, 'suspended_reason' => $data['reason']])->save();
            DB::table('settlements')->where('event_id', $event->id)->whereIn('status', ['pending', 'ready'])->whereNull('payout_id')
                ->update(['status' => 'on_hold', 'hold_reason' => 'Event suspended: '.$data['reason'], 'updated_at' => now()]);
        });
        $this->notifyOrganizer($event, 'Event suspended', $event->title.' was suspended: '.$data['reason']);
        $this->audit->log('event.suspended', 'events', $event, $event->title, ['status' => $event->status_before_suspension], ['status' => 'suspended', 'reason' => $data['reason']]);

        return $this->ok($this->presenter->event($event->refresh()), 'Event suspended.');
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $event = $this->find($id);
        if ($event->status !== EventStatus::Suspended) {
            throw ApiException::conflict('Only suspended events can be restored.');
        }
        $event->forceFill(['status' => $event->status_before_suspension ?: EventStatus::Live->value, 'status_before_suspension' => null, 'suspended_reason' => null])->save();
        $this->audit->log('event.restored', 'events', $event, $event->title, ['status' => 'suspended'], ['status' => $event->status->value, 'reason' => $data['reason'] ?? null]);

        return $this->ok($this->presenter->event($event->refresh()), 'Event restored. Release any held settlement from Finance.');
    }

    public function feature(string $id): JsonResponse
    {
        return $this->setFeatured($id, true);
    }

    public function unfeature(string $id): JsonResponse
    {
        return $this->setFeatured($id, false);
    }

    private function setFeatured(string $id, bool $featured): JsonResponse
    {
        $event = $this->find($id);
        if ($featured && $event->status !== EventStatus::Live) {
            throw ApiException::conflict('Only live events can be featured.');
        }
        $event->forceFill(['is_featured' => $featured])->save();
        $this->audit->log($featured ? 'event.featured' : 'event.unfeatured', 'events', $event, $event->title, ['isFeatured' => ! $featured], ['isFeatured' => $featured]);

        return $this->ok($this->presenter->event($event));
    }

    // ---- Categories ----------------------------------------------------------------

    public function categories(): JsonResponse
    {
        $counts = DB::table('events')->whereNull('deleted_at')->selectRaw('category_id, count(*) c')->groupBy('category_id')->pluck('c', 'category_id');

        return $this->ok(EventCategory::query()->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn ($c) => EventController::category($c, (int) ($counts[$c->id] ?? 0)))->all());
    }

    public function createCategory(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'icon' => ['nullable', 'string', 'max:60']]);
        $slug = Str::slug($data['name']);
        if (EventCategory::query()->where('slug', $slug)->exists()) {
            throw ApiException::validation('A category with this name exists.', ['name' => ['This category already exists.']]);
        }
        $category = EventCategory::query()->create(['name' => $data['name'], 'slug' => $slug, 'icon' => $data['icon'] ?? 'calendar', 'sort_order' => (int) EventCategory::query()->max('sort_order') + 1]);
        $this->audit->log('category.created', 'events', $category, $category->name, null, ['name' => $category->name]);

        return $this->created(EventController::category($category, 0));
    }

    public function updateCategory(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:120'], 'slug' => ['sometimes', 'alpha_dash', 'max:120'], 'icon' => ['sometimes', 'string', 'max:60'], 'isActive' => ['sometimes', 'boolean']]);
        $category = EventCategory::query()->findOrFail($id);
        $before = $category->only(['name', 'slug', 'icon', 'is_active']);
        $category->forceFill(array_filter(['name' => $data['name'] ?? null, 'slug' => $data['slug'] ?? null, 'icon' => $data['icon'] ?? null], fn ($v) => $v !== null)
            + (array_key_exists('isActive', $data) ? ['is_active' => $data['isActive']] : []))->save();
        $this->audit->log('category.updated', 'events', $category, $category->name, $before, $category->only(['name', 'slug', 'icon', 'is_active']));

        return $this->ok(EventController::category($category, Event::query()->where('category_id', $category->id)->count()));
    }

    public function deleteCategory(string $id): Response
    {
        $category = EventCategory::query()->findOrFail($id);
        if (Event::withTrashed()->where('category_id', $category->id)->exists()) {
            throw ApiException::conflict('This category has events. Deactivate it instead.');
        }
        $category->delete();
        $this->audit->log('category.deleted', 'events', $category, $category->name);

        return $this->noContent();
    }

    private function find(string $id): Event
    {
        return Event::withTrashed()->find($id) ?? throw ApiException::notFound('Event not found.');
    }

    private function notifyOrganizer(Event $event, string $title, string $body): void
    {
        $organizer = User::query()->find($event->organizer_user_id);
        if ($organizer) {
            $this->notifications->notify($organizer, 'system', ['title' => $title, 'body' => $body, 'data' => ['eventId' => $event->id], 'link' => Platform::webUrl('/manage/events/'.$event->id)]);
        }
    }
}
