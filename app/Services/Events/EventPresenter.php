<?php

namespace App\Services\Events;

use App\Enums\TicketStatus;
use App\Http\Resources\Fmt;
use App\Models\Event;
use App\Models\EventSchedule;
use App\Models\PlannerMenu;
use App\Models\SubEvent;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Media\ImageUrl;
use App\Support\Models;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the contract's event shapes — EventSummary, Event, ManagedEvent,
 * ManageEventSummary — with personal flags (isSaved, myTicketId,
 * myRequestStatus) batched per collection to avoid N+1 queries.
 */
class EventPresenter
{
    public const SUMMARY_RELATIONS = ['category', 'organizer', 'promotion'];

    public function __construct(private readonly EventAccess $access) {}

    /**
     * @param  iterable<Event>  $events
     * @return list<array>
     */
    public function summaries(iterable $events, ?User $viewer, bool $withTicketsSold = false): array
    {
        $events = Models::collect($events);
        $events->loadMissing(self::SUMMARY_RELATIONS);
        $saved = $this->savedIds($events, $viewer);
        $promoters = $this->promoterCounts($events);

        return $events->map(fn (Event $e) => $this->summary($e, $saved, $promoters, $withTicketsSold))->values()->all();
    }

    public function summaryOne(Event $event, ?User $viewer): array
    {
        return $this->summaries([$event], $viewer)[0];
    }

    /** @param array<string,bool> $saved @param array<string,int> $promoters */
    private function summary(Event $e, array $saved, array $promoters, bool $withTicketsSold): array
    {
        $data = [
            'id' => $e->id,
            'slug' => $e->slug,
            'title' => $e->title,
            'coverUrl' => $e->cover_url ?: self::placeholderCover(),
            'category' => $e->category ? ['id' => $e->category->id, 'slug' => $e->category->slug, 'name' => $e->category->name] : ['id' => '', 'slug' => 'uncategorized', 'name' => 'Uncategorized'],
            'startsAt' => Fmt::date($e->starts_at) ?? Fmt::date($e->created_at),
            'endsAt' => Fmt::date($e->ends_at),
            'attendanceMode' => $e->attendance_mode,
            'city' => $e->city,
            'isFree' => $e->is_free,
            'minPrice' => $e->min_price,
            'currency' => $e->currency,
            'status' => $e->status->value,
            'isSaved' => $saved[$e->id] ?? false,
            'isFeatured' => $e->is_featured,
            'isPromoted' => (bool) $e->promotion?->enabled,
            'organizer' => Fmt::user($e->organizer),
            'promotion' => $e->promotion?->enabled ? ['commissionPercent' => (float) $e->promotion->commission_percent, 'promotersCount' => $promoters[$e->id] ?? 0] : null,
        ];
        if ($withTicketsSold) {
            $data['ticketsSold'] = $e->tickets_sold;
        }

        return $data;
    }

    /** Full Event detail. */
    public function detail(Event $event, ?User $viewer): array
    {
        $event->loadMissing([...self::SUMMARY_RELATIONS, 'schedules', 'subEvents', 'ticketTypes', 'plannerMenu', 'community']);
        $summary = $this->summaryOne($event, $viewer);
        $role = $viewer ? $this->access->role($viewer, $event) : null;
        $canSeeRevenue = $viewer && $this->access->can($viewer, $event, 'view_revenue');

        $myTicketId = null;
        $myRequest = 'none';
        if ($viewer) {
            $myTicketId = DB::table('tickets')->where('event_id', $event->id)->where('holder_user_id', $viewer->id)
                ->whereIn('status', [TicketStatus::Valid->value, TicketStatus::CheckedIn->value])->orderBy('created_at')->value('id');
            $myRequest = DB::table('event_requests')->where('event_id', $event->id)->where('user_id', $viewer->id)
                ->orderByDesc('created_at')->value('status') ?? 'none';
        }
        $canSeeOnline = $role !== null || $myTicketId !== null;

        $organizer = $event->organizer;
        $info = $event->organizer_info ?? [];
        $organizerEvents = DB::table('events')->where('organizer_user_id', $event->organizer_user_id)->whereNull('deleted_at')
            ->whereIn('status', ['live', 'completed'])->count();

        $stats = $this->stats($event);
        if (! $canSeeRevenue) {
            $stats['revenue'] = 0;
        }

        return $summary + [
            'description' => (string) $event->description,
            'visibility' => $event->visibility,
            'approvalRequired' => $event->approval_required,
            'guestLimit' => $event->guest_limit,
            'allowGuestInvites' => $event->allow_guest_invites,
            'maxAdditionalGuests' => $event->max_additional_guests,
            'isAdultOnly' => $event->is_adult_only,
            'organizer' => array_merge(Fmt::user($organizer) ?? [], [
                'displayName' => $info['displayName'] ?? $organizer?->display_name,
                'bio' => $info['bio'] ?? $organizer?->bio,
                'eventsCount' => $organizerEvents,
            ]),
            'schedules' => $event->schedules->map(fn (EventSchedule $s) => self::schedule($s, $canSeeOnline))->all(),
            'subEvents' => $event->subEvents->map(fn (SubEvent $s) => self::subEvent($s))->all(),
            'ticketTypes' => $event->ticketTypes->map(fn (TicketType $t) => self::ticketType($t))->all(),
            'menu' => self::menu($event->plannerMenu, $event->currency),
            'promotion' => self::promotionSettings($event, $summary['promotion']['promotersCount'] ?? $this->promoterCounts(collect([$event]))[$event->id] ?? 0),
            'stats' => $stats,
            'gallery' => array_values($event->gallery ?? []),
            'tags' => array_values($event->tags ?? []),
            'communityId' => $event->community?->id,
            'myRequestStatus' => $myRequest,
            'myTicketId' => $myTicketId,
            'createdAt' => Fmt::date($event->created_at),
            'updatedAt' => Fmt::date($event->updated_at),
            'publishedAt' => Fmt::date($event->published_at),
            'ticketsSold' => $event->tickets_sold,
        ];
    }

    /** ManagedEvent = Event + manage. */
    public function managed(Event $event, User $viewer): array
    {
        $detail = $this->detail($event, $viewer);
        $detail['manage'] = $this->manageSummaries([$event], $viewer)[0];

        return $detail;
    }

    /**
     * @param  iterable<Event>  $events
     * @return list<array>
     */
    public function manageSummaries(iterable $events, User $viewer): array
    {
        $events = Models::collect($events);
        $summaries = $this->summaries($events, $viewer, true);
        $collaborators = DB::table('event_managers')->whereIn('event_id', $events->pluck('id'))->whereIn('status', ['pending', 'accepted'])
            ->selectRaw('event_id, count(*) as c')->groupBy('event_id')->pluck('c', 'event_id');
        $vendors = DB::table('event_vendor_assignments')->whereIn('event_id', $events->pluck('id'))->whereIn('status', ['pending', 'accepted'])
            ->selectRaw('event_id, count(*) as c')->groupBy('event_id')->pluck('c', 'event_id');
        $communities = DB::table('communities')->whereIn('event_id', $events->pluck('id'))->pluck('id', 'event_id');

        return $events->values()->map(function (Event $event, int $i) use ($summaries, $viewer, $collaborators, $vendors, $communities) {
            $stats = $this->stats($event);
            if (! $this->access->can($viewer, $event, 'view_revenue')) {
                $stats['revenue'] = 0;
            }

            return $summaries[$i] + [
                'myRole' => $this->access->role($viewer, $event) ?? 'vendor',
                'stats' => $stats,
                'collaboratorsCount' => (int) ($collaborators[$event->id] ?? 0) + (int) ($vendors[$event->id] ?? 0),
                'communityId' => $communities[$event->id] ?? null,
            ];
        })->all();
    }

    /** EventStats. */
    public function stats(Event $event): array
    {
        $tickets = DB::table('tickets')->where('event_id', $event->id)
            ->selectRaw("count(*) filter (where status in ('valid','checked_in')) as registered, count(*) filter (where status = 'checked_in') as checked_in")
            ->first();
        $revenue = (int) DB::table('orders')->where('event_id', $event->id)->where('status', 'paid')
            ->sum(DB::raw('subtotal - discount - refunded_amount'));
        $capacity = $event->guest_limit ?? (int) DB::table('ticket_types')->where('event_id', $event->id)->whereNull('deleted_at')->where('is_active', true)->sum('quantity');

        return [
            'registered' => (int) ($tickets->registered ?? 0),
            'checkedIn' => (int) ($tickets->checked_in ?? 0),
            'capacity' => $capacity > 0 ? $capacity : null,
            'pendingRequests' => DB::table('event_requests')->where('event_id', $event->id)->where('status', 'pending')->count(),
            'revenue' => max(0, $revenue),
            'ticketsSold' => $event->tickets_sold,
        ];
    }

    public static function schedule(EventSchedule $s, bool $canSeeOnline = true): array
    {
        $online = $s->online;
        if ($online !== null && ! $canSeeOnline) {
            $online['link'] = '';
        }

        return [
            'id' => $s->id,
            'label' => $s->label,
            'startsAt' => Fmt::date($s->starts_at),
            'endsAt' => Fmt::date($s->ends_at),
            'attendanceMode' => $s->attendance_mode,
            'venue' => $s->venue,
            'online' => $online,
            'hallLayoutId' => $s->hall_layout_id,
            'menuId' => $s->menu_id,
        ];
    }

    public static function subEvent(SubEvent $s): array
    {
        return [
            'id' => $s->id,
            'title' => $s->title,
            'description' => (string) $s->description,
            'coverUrl' => $s->cover_url,
            'scheduleId' => $s->schedule_id,
            'startsAt' => Fmt::date($s->starts_at),
            'endsAt' => Fmt::date($s->ends_at),
            'requiresApproval' => $s->requires_approval,
            'isIncludedInBundle' => $s->is_included_in_bundle,
        ];
    }

    public static function ticketType(TicketType $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'description' => $t->description,
            'price' => $t->price,
            'currency' => $t->currency,
            'quantity' => $t->quantity,
            'sold' => $t->sold,
            'salesStartAt' => Fmt::date($t->sales_start_at),
            'salesEndAt' => Fmt::date($t->sales_end_at),
            'perOrderLimit' => $t->per_order_limit,
            'scheduleId' => $t->schedule_id,
            'seatSelection' => $t->seat_selection,
            'menuSelection' => $t->menu_selection,
            'isEarlyBird' => $t->is_early_bird,
            'isActive' => $t->is_active,
        ];
    }

    /** Event.menu (MenuItem[]) flattened from the linked planner menu. */
    public static function menu(?PlannerMenu $menu, string $currency): array
    {
        if ($menu === null) {
            return [];
        }
        $items = [];
        foreach ($menu->sections ?? [] as $section) {
            foreach ($section['items'] ?? [] as $item) {
                $items[] = [
                    'id' => (string) $item['id'],
                    'name' => (string) $item['name'],
                    'description' => $item['description'] ?? null,
                    'price' => (int) ($item['price'] ?? 0),
                    'currency' => $menu->currency ?: $currency,
                    'imageUrl' => $item['imageUrl'] ?? null,
                    'category' => (string) ($section['title'] ?? 'Menu'),
                    'isAvailable' => (bool) ($item['isAvailable'] ?? true),
                ];
            }
        }

        return $items;
    }

    public static function promotionSettings(Event $event, int $promotersCount): array
    {
        $p = $event->promotion;

        return [
            'enabled' => (bool) $p?->enabled,
            'commissionPercent' => (float) ($p?->commission_percent ?? 10),
            'targetSignups' => $p?->target_signups,
            'promoterLimit' => $p?->promoter_limit,
            'promotersCount' => $promotersCount,
        ];
    }

    /** Cover for events without one: env override, else the asset from `wedpluz:cloudinary-assets`. */
    public static function placeholderCover(): string
    {
        return (string) (config('wedpluz.placeholder_cover')
            ?: app(ImageUrl::class)->forPublicId('static/event-placeholder', 'cover')
            ?: rtrim((string) config('app.url'), '/').'/images/event-placeholder.jpg');
    }

    /** @return array<string,bool> */
    private function savedIds(Collection $events, ?User $viewer): array
    {
        if ($viewer === null || $events->isEmpty()) {
            return [];
        }

        return DB::table('saved_events')->where('user_id', $viewer->id)->whereIn('event_id', $events->pluck('id'))
            ->pluck('event_id')->mapWithKeys(fn ($id) => [$id => true])->all();
    }

    /** @return array<string,int> */
    private function promoterCounts(Collection $events): array
    {
        $ids = $events->filter(fn (Event $e) => $e->promotion?->enabled)->pluck('id');
        if ($ids->isEmpty()) {
            return [];
        }

        return DB::table('promoter_enrollments')->whereIn('event_id', $ids)->where('status', 'active')
            ->selectRaw('event_id, count(*) as c')->groupBy('event_id')->pluck('c', 'event_id')->map(fn ($c) => (int) $c)->all();
    }
}
