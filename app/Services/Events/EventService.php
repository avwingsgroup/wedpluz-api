<?php

namespace App\Services\Events;

use App\Enums\EventStatus;
use App\Exceptions\ApiException;
use App\Http\Middleware\RequestContext;
use App\Jobs\CancelEventJob;
use App\Jobs\NotifyEventUpdatedJob;
use App\Models\Event;
use App\Models\EventPromotion;
use App\Models\EventSchedule;
use App\Models\HallLayout;
use App\Models\PlannerMenu;
use App\Models\SubEvent;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Communities\CommunityService;
use App\Services\Finance\SettlementService;
use App\Services\Media\UploadService;
use App\Services\Platform\SlugHistory;
use App\Services\Subscriptions\PlanGate;
use App\Support\Codes;
use App\Support\RichText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Organiser-side event lifecycle (business-rules.md, "Event lifecycle"):
 * draft -> publish -> pending_review | live -> completed | cancelled.
 */
class EventService
{
    public function __construct(
        private readonly EventAccess $access,
        private readonly ActivityLogger $activity,
        private readonly UploadService $uploads,
        private readonly CommunityService $communities,
    ) {}

    /** @param array<string,mixed> $data validated camelCase payload */
    public function create(User $user, array $data): Event
    {
        return DB::transaction(function () use ($user, $data) {
            $title = trim((string) ($data['title'] ?? '')) ?: 'Untitled event';
            $event = Event::query()->create([
                'organizer_user_id' => $user->id,
                'title' => $title,
                'slug' => $this->uniqueSlug($title),
                'status' => EventStatus::Draft,
                'currency' => config('wedpluz.default_currency', 'NGN'),
                'source' => RequestContext::source(),
            ]);
            EventPromotion::query()->create(['event_id' => $event->id, 'enabled' => false, 'commission_percent' => 10]);
            $user->addRole('organizer');

            $this->apply($event, $user, $data, isNew: true);
            $this->activity->log($event, $user, 'event.created', 'Created the event draft');

            return $event->refresh();
        });
    }

    /** @param array<string,mixed> $data */
    public function update(Event $event, User $user, array $data): Event
    {
        if (in_array($event->status, [EventStatus::Cancelled, EventStatus::Completed, EventStatus::Suspended], true)) {
            throw ApiException::conflict('This event can no longer be edited.');
        }

        $assetsOnly = array_keys($data) === ['assets'];
        $financial = array_intersect(array_keys($data), ['ticketTypes', 'isFree']) !== [];
        if ($assetsOnly) {
            $this->access->authorize($user, $event, 'manage_assets');
        } else {
            $this->access->authorize($user, $event, 'edit_event');
            if ($financial) {
                $this->access->requireFullAccess($user, $event);
            }
            if (isset($data['assets'])) {
                $this->access->authorize($user, $event, 'manage_assets');
            }
        }

        $wasLive = $event->status === EventStatus::Live;
        $before = $this->scheduleFingerprint($event);

        DB::transaction(function () use ($event, $user, $data) {
            Event::query()->whereKey($event->id)->lockForUpdate()->first();
            $oldTitle = $event->title;
            $this->apply($event, $user, $data, isNew: false);
            if ($event->title !== $oldTitle) {
                // The URL follows the title; links to a published event keep working via a redirect.
                app(SlugHistory::class)->change('event', $event, $this->uniqueSlug($event->title), rememberOld: $event->published_at !== null);
            }
            if ($event->status === EventStatus::Rejected) {
                // A rejected event returns to editable and can be republished.
                $event->forceFill(['status' => EventStatus::Draft])->save();
            }
            $this->activity->log($event, $user, 'event.updated', 'Updated event details', ['fields' => array_keys($data)]);
        });

        $event->refresh();
        if ($wasLive && $before !== $this->scheduleFingerprint($event)) {
            NotifyEventUpdatedJob::dispatch($event->id, 'The date, time or venue changed.');
        }

        return $event;
    }

    public function publish(Event $event, User $user): Event
    {
        $this->access->authorize($user, $event, 'edit_event');
        if (! in_array($event->status, [EventStatus::Draft, EventStatus::Rejected], true)) {
            throw ApiException::conflict('Only draft or rejected events can be published.');
        }

        $event->load(['schedules', 'ticketTypes', 'category', 'organizer']);
        $this->validateCompleteness($event);

        DB::transaction(function () use ($event, $user) {
            if ($event->is_free && $event->ticketTypes->where('is_active', true)->isEmpty()) {
                TicketType::query()->create([
                    'event_id' => $event->id,
                    'name' => 'General admission',
                    'price' => 0,
                    'currency' => $event->currency,
                    'quantity' => $event->guest_limit ?: 100000,
                    'per_order_limit' => 10,
                    'is_active' => true,
                ]);
            }

            $needsReview = $this->needsReview($event);
            $event->forceFill([
                'status' => $needsReview ? EventStatus::PendingReview : EventStatus::Live,
                'submitted_at' => now(),
                'rejection_reason' => null,
                'published_at' => $needsReview ? $event->published_at : now(),
            ])->save();
            $this->recalculate($event);

            if (! $needsReview) {
                $this->goLive($event);
            }
            $this->activity->log($event, $user, 'event.published', $needsReview ? 'Submitted the event for review' : 'Published the event');
        });

        return $event->refresh();
    }

    /** Side effects of an event becoming live (publish or admin approval). */
    public function goLive(Event $event): void
    {
        if ($event->community_enabled) {
            $this->communities->ensureEventCommunity($event);
        }
    }

    public function delete(Event $event, User $user): void
    {
        $this->access->requireOrganizer($user, $event);
        if ($event->status !== EventStatus::Draft) {
            throw ApiException::conflict('Only draft events can be deleted.');
        }
        $event->delete();
    }

    public function changeStatus(Event $event, User $user, string $status, ?string $reason): Event
    {
        $this->access->requireFullAccess($user, $event);

        if ($status === 'completed') {
            if ($event->status !== EventStatus::Live) {
                throw ApiException::conflict('Only live events can be marked completed.');
            }
            $this->complete($event);
            $this->activity->log($event, $user, 'event.completed', 'Marked the event completed');
        } else {
            if (! in_array($event->status, [EventStatus::Live, EventStatus::PendingReview, EventStatus::Draft], true)) {
                throw ApiException::conflict('This event cannot be cancelled.');
            }
            if (trim((string) $reason) === '') {
                throw ApiException::validation('A cancellation reason is required.', ['cancellationReason' => ['Tell attendees why the event is cancelled.']]);
            }
            $this->cancel($event, (string) $reason);
            $this->activity->log($event, $user, 'event.cancelled', 'Cancelled the event', ['reason' => $reason]);
        }

        return $event->refresh();
    }

    public function complete(Event $event): void
    {
        DB::transaction(function () use ($event) {
            $event->forceFill(['status' => EventStatus::Completed, 'completed_at' => now()])->save();
            app(SettlementService::class)->createForEvent($event);
        });
    }

    /** Organiser/admin cancellation: closes sales, refunds paid orders in full, notifies holders, archives the community in 7 days. */
    public function cancel(Event $event, string $reason): void
    {
        DB::transaction(function () use ($event, $reason) {
            $event->forceFill([
                'status' => EventStatus::Cancelled,
                'cancellation_reason' => mb_substr($reason, 0, 1000),
                'cancelled_at' => now(),
            ])->save();
            TicketType::query()->where('event_id', $event->id)->update(['is_active' => false]);
            DB::table('communities')->where('event_id', $event->id)->update(['archive_at' => now()->addDays(7)]);
        });
        CancelEventJob::dispatch($event->id);
    }

    // ---------------------------------------------------------------------
    // Payload application
    // ---------------------------------------------------------------------

    /** @param array<string,mixed> $data */
    private function apply(Event $event, User $user, array $data, bool $isNew): void
    {
        $fields = [];
        $map = [
            'visibility' => 'visibility', 'approvalRequired' => 'approval_required', 'guestLimit' => 'guest_limit',
            'allowGuestInvites' => 'allow_guest_invites', 'maxAdditionalGuests' => 'max_additional_guests',
            'isAdultOnly' => 'is_adult_only', 'isFree' => 'is_free', 'categoryId' => 'category_id',
        ];
        foreach ($map as $key => $column) {
            if (array_key_exists($key, $data)) {
                $fields[$column] = $data[$key];
            }
        }
        if (array_key_exists('title', $data) && trim((string) $data['title']) !== '') {
            $fields['title'] = trim((string) $data['title']);
        }
        if (array_key_exists('description', $data)) {
            $fields['description'] = RichText::sanitize((string) $data['description']);
        }
        if (! empty($data['coverUploadId'])) {
            $fields['cover_url'] = $this->uploads->consume($data['coverUploadId'], $user, ['event_cover', 'cover'], 'coverUploadId')->url;
        } elseif (array_key_exists('coverUrl', $data)) {
            $fields['cover_url'] = $data['coverUrl'];
        }
        if (array_key_exists('organizerInfo', $data)) {
            $fields['organizer_info'] = $data['organizerInfo'];
        }
        if (array_key_exists('tags', $data)) {
            $fields['tags'] = array_values(array_unique(array_map(fn ($t) => mb_strtolower(trim($t)), $data['tags'] ?? [])));
        }
        if (array_key_exists('gallery', $data)) {
            $fields['gallery'] = array_values($data['gallery'] ?? []);
        }
        if (array_key_exists('assets', $data)) {
            $fields['hall_layout_id'] = $this->ownedLayout($data['assets']['hallLayoutId'] ?? null, $event, 'assets.hallLayoutId');
            $fields['menu_id'] = $this->ownedMenu($data['assets']['menuId'] ?? null, $event, 'assets.menuId');
        }
        if ($fields !== []) {
            $event->forceFill($fields)->save();
        }

        $scheduleIds = [];
        if (array_key_exists('schedules', $data)) {
            $scheduleIds = $this->syncSchedules($event, $data['schedules'] ?? []);
        }
        if (array_key_exists('subEvents', $data)) {
            if (! empty($data['subEvents'])) {
                $organizer = $event->organizer_user_id === $user->id ? $user : User::query()->find($event->organizer_user_id);
                app(PlanGate::class)->require($organizer, 'sub_events', 'Sub-events are available on the Premium plan. Upgrade to add them.');
            }
            $this->syncSubEvents($event, $data['subEvents'] ?? [], $scheduleIds);
        }
        if (array_key_exists('ticketTypes', $data)) {
            $this->syncTicketTypes($event, $data['ticketTypes'] ?? [], $scheduleIds);
        }

        $this->recalculate($event);
    }

    /** @return array<string,string> client id -> server id */
    private function syncSchedules(Event $event, array $items): array
    {
        $existing = EventSchedule::query()->where('event_id', $event->id)->get()->keyBy('id');
        $map = [];
        $keep = [];

        foreach (array_values($items) as $i => $item) {
            $venue = $item['venue'] ?? null;
            $attrs = [
                'label' => $item['label'] ?? null,
                'starts_at' => $item['startsAt'],
                'ends_at' => $item['endsAt'] ?? null,
                'attendance_mode' => $item['attendanceMode'],
                'venue' => $venue,
                'online' => $item['online'] ?? null,
                'city' => $venue['city'] ?? null,
                'lat' => $venue['lat'] ?? null,
                'lng' => $venue['lng'] ?? null,
                'hall_layout_id' => $this->ownedLayout($item['hallLayoutId'] ?? null, $event, "schedules.$i.hallLayoutId"),
                'menu_id' => $this->ownedMenu($item['menuId'] ?? null, $event, "schedules.$i.menuId"),
                'sort_order' => $i,
            ];
            $clientId = $item['id'] ?? null;
            if ($clientId && $existing->has($clientId)) {
                $existing[$clientId]->forceFill($attrs)->save();
                $map[$clientId] = $clientId;
                $keep[] = $clientId;
            } else {
                $schedule = EventSchedule::query()->create($attrs + ['event_id' => $event->id]);
                if ($clientId) {
                    $map[$clientId] = $schedule->id;
                }
                $keep[] = $schedule->id;
            }
        }

        $removed = $existing->keys()->diff($keep);
        if ($removed->isNotEmpty()) {
            $sold = DB::table('tickets')->whereIn('schedule_id', $removed)->whereIn('status', ['valid', 'checked_in'])->exists();
            if ($sold) {
                throw ApiException::validation('A date with sold tickets cannot be removed.', ['schedules' => ['A date with sold tickets cannot be removed.']]);
            }
            EventSchedule::query()->whereIn('id', $removed)->delete();
        }

        return $map;
    }

    private function syncSubEvents(Event $event, array $items, array $scheduleMap): void
    {
        $existing = SubEvent::query()->where('event_id', $event->id)->get()->keyBy('id');
        $keep = [];
        foreach (array_values($items) as $i => $item) {
            $attrs = [
                'title' => $item['title'],
                'description' => RichText::sanitize((string) ($item['description'] ?? '')),
                'cover_url' => $item['coverUrl'] ?? null,
                'schedule_id' => $this->resolveScheduleId($event, $item['scheduleId'] ?? null, $scheduleMap, "subEvents.$i.scheduleId"),
                'starts_at' => $item['startsAt'],
                'ends_at' => $item['endsAt'] ?? null,
                'requires_approval' => (bool) ($item['requiresApproval'] ?? false),
                'is_included_in_bundle' => (bool) ($item['isIncludedInBundle'] ?? true),
                'sort_order' => $i,
            ];
            $id = $item['id'] ?? null;
            if ($id && $existing->has($id)) {
                $existing[$id]->forceFill($attrs)->save();
                $keep[] = $id;
            } else {
                $keep[] = SubEvent::query()->create($attrs + ['event_id' => $event->id])->id;
            }
        }
        SubEvent::query()->where('event_id', $event->id)->whereNotIn('id', $keep)->delete();
    }

    private function syncTicketTypes(Event $event, array $items, array $scheduleMap): void
    {
        $existing = TicketType::query()->where('event_id', $event->id)->get()->keyBy('id');
        $keep = [];
        foreach (array_values($items) as $i => $item) {
            $id = $item['id'] ?? null;
            $current = $id ? $existing->get($id) : null;
            $quantity = (int) $item['quantity'];
            if ($current && $quantity < $current->sold) {
                throw ApiException::validation('Quantity cannot be below tickets already sold.', ["ticketTypes.$i.quantity" => ["At least {$current->sold} tickets were already sold."]]);
            }
            if ((int) $item['price'] > 0 && ($event->is_free)) {
                throw ApiException::validation('A free event cannot have paid tickets.', ["ticketTypes.$i.price" => ['Free events cannot have paid tickets.']]);
            }

            $attrs = [
                'name' => $item['name'],
                'description' => $item['description'] ?? null,
                'schedule_id' => $this->resolveScheduleId($event, $item['scheduleId'] ?? null, $scheduleMap, "ticketTypes.$i.scheduleId"),
                'price' => (int) $item['price'],
                'currency' => $item['currency'] ?? $event->currency,
                'quantity' => $quantity,
                'sales_start_at' => $item['salesStartAt'] ?? null,
                'sales_end_at' => $item['salesEndAt'] ?? null,
                'per_order_limit' => (int) ($item['perOrderLimit'] ?? 10),
                'seat_selection' => (bool) ($item['seatSelection'] ?? false),
                'menu_selection' => (bool) ($item['menuSelection'] ?? false),
                'is_early_bird' => (bool) ($item['isEarlyBird'] ?? false),
                'is_active' => (bool) ($item['isActive'] ?? true),
                'sort_order' => $i,
            ];
            if ($current) {
                $current->forceFill($attrs)->save();
                $keep[] = $current->id;
            } else {
                $keep[] = TicketType::query()->create($attrs + ['event_id' => $event->id])->id;
            }
        }

        $removed = $existing->keys()->diff($keep);
        foreach ($removed as $removedId) {
            if ($existing[$removedId]->sold > 0) {
                throw ApiException::validation('A ticket type with sales cannot be removed. Deactivate it instead.', ['ticketTypes' => ['"'.$existing[$removedId]->name.'" has sales. Deactivate it instead of removing it.']]);
            }
        }
        TicketType::query()->whereIn('id', $removed)->delete();

        $currencies = TicketType::query()->where('event_id', $event->id)->distinct()->pluck('currency');
        if ($currencies->count() > 1) {
            throw ApiException::validation('All ticket types must use the same currency.', ['ticketTypes' => ['Use one currency for all ticket types.']]);
        }
        if ($currencies->count() === 1) {
            $event->forceFill(['currency' => $currencies->first()])->save();
        }
    }

    private function resolveScheduleId(Event $event, ?string $id, array $map, string $field): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }
        if (isset($map[$id])) {
            return $map[$id];
        }
        if (EventSchedule::query()->where('event_id', $event->id)->whereKey($id)->exists()) {
            return $id;
        }
        throw ApiException::validation('Unknown event date.', [$field => ['The selected date does not belong to this event.']]);
    }

    private function ownedLayout(?string $id, Event $event, string $field): ?string
    {
        if (! $id) {
            return null;
        }
        $ok = HallLayout::query()->whereKey($id)->where(fn ($q) => $q->where('user_id', $event->organizer_user_id)->orWhere('event_id', $event->id))->exists();
        if (! $ok) {
            throw ApiException::validation('Unknown hall layout.', [$field => ['The selected hall layout is invalid.']]);
        }

        return $id;
    }

    private function ownedMenu(?string $id, Event $event, string $field): ?string
    {
        if (! $id) {
            return null;
        }
        $ok = PlannerMenu::query()->whereKey($id)->where(fn ($q) => $q->where('user_id', $event->organizer_user_id)->orWhere('event_id', $event->id))->exists();
        if (! $ok) {
            throw ApiException::validation('Unknown menu.', [$field => ['The selected menu is invalid.']]);
        }

        return $id;
    }

    // ---------------------------------------------------------------------
    // Rules
    // ---------------------------------------------------------------------

    public function validateCompleteness(Event $event): void
    {
        $errors = [];
        if (trim($event->title) === '' || $event->title === 'Untitled event') {
            $errors['title'] = ['Add a title'];
        }
        if ($event->category_id === null) {
            $errors['categoryId'] = ['Choose a category'];
        }
        if ($event->schedules->isEmpty()) {
            $errors['schedules'] = ['Add at least one date'];
        }
        foreach ($event->schedules as $i => $s) {
            if ($s->attendance_mode !== 'online' && empty($s->venue['name'] ?? null) && empty($s->venue['address'] ?? null)) {
                $errors["schedules.$i.venue"] = ['Add a venue'];
            }
            if ($s->attendance_mode !== 'physical' && empty($s->online['link'] ?? null)) {
                $errors["schedules.$i.online"] = ['Add an online link'];
            }
            if ($s->ends_at && $s->ends_at->lt($s->starts_at)) {
                $errors["schedules.$i.endsAt"] = ['The end must be after the start'];
            }
        }
        $active = $event->ticketTypes->where('is_active', true);
        if ($active->isEmpty() && ! $event->is_free) {
            $errors['ticketTypes'] = ['Add at least one ticket type or mark the event as free'];
        }
        $lastEnd = $event->schedules->map(fn ($s) => $s->ends_at ?? $s->starts_at)->max();
        foreach ($event->ticketTypes->values() as $i => $t) {
            $schedule = $t->schedule_id ? $event->schedules->firstWhere('id', $t->schedule_id) : null;
            $limit = $schedule ? ($schedule->ends_at ?? $schedule->starts_at) : $lastEnd;
            if ($t->sales_end_at && $limit && $t->sales_end_at->gt($limit)) {
                $errors["ticketTypes.$i.salesEndAt"] = ['Sales must end by the end of the event'];
            }
            if ($t->sales_start_at && $t->sales_end_at && $t->sales_end_at->lt($t->sales_start_at)) {
                $errors["ticketTypes.$i.salesEndAt"] = ['Sales must end after they start'];
            }
        }

        if ($errors !== []) {
            throw ApiException::validation('Complete the event before publishing.', $errors);
        }
    }

    private function needsReview(Event $event): bool
    {
        $organizer = $event->organizer;
        $paid = $event->ticketTypes->where('price', '>', 0)->isNotEmpty();
        $hadPaidBefore = $paid && Event::query()->where('organizer_user_id', $event->organizer_user_id)->where('id', '!=', $event->id)
            ->whereIn('status', ['live', 'completed'])->whereHas('ticketTypes', fn ($q) => $q->where('price', '>', 0))->exists();

        return ! $organizer?->isIdentityVerified()
            || ($paid && ! $hadPaidBefore)
            || $event->is_adult_only
            || (bool) $event->category?->requires_review;
    }

    /** Maintain denormalised listing columns. */
    public function recalculate(Event $event): void
    {
        $schedules = EventSchedule::query()->where('event_id', $event->id)->orderBy('starts_at')->get();
        $active = TicketType::query()->where('event_id', $event->id)->where('is_active', true)->get();
        $first = $schedules->first();
        $modes = $schedules->pluck('attendance_mode')->unique();
        $paid = $active->where('price', '>', 0);

        $event->forceFill([
            'starts_at' => $first?->starts_at,
            'ends_at' => $schedules->map(fn ($s) => $s->ends_at ?? $s->starts_at)->max(),
            'city' => $first?->city,
            'attendance_mode' => $modes->count() > 1 ? 'hybrid' : ($modes->first() ?? $event->attendance_mode ?? 'physical'),
            'min_price' => (int) ($paid->min('price') ?? 0),
            // Derived from active ticket types when there are any; otherwise the organiser's flag.
            'is_free' => $active->isNotEmpty() ? $paid->isEmpty() : (bool) $event->is_free,
        ])->save();
    }

    private function scheduleFingerprint(Event $event): string
    {
        return md5(json_encode(EventSchedule::query()->where('event_id', $event->id)->orderBy('id')
            ->get(['id', 'starts_at', 'ends_at', 'venue', 'online'])->toArray()));
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug(Str::limit($title, 80, '')) ?: 'event';
        do {
            $slug = $base.'-'.strtolower(Codes::random(6));
        } while (Event::withTrashed()->where('slug', $slug)->exists() || app(SlugHistory::class)->reserved('event', $slug));

        return $slug;
    }
}
