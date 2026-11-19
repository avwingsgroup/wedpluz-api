<?php

namespace App\Services\Events;

use App\Exceptions\ApiException;
use App\Models\Event;
use App\Support\ListQuery;
use App\Support\Platform;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Public event search (api-contracts.md §2.3). Guests and other users see only
 * live, public events that have not ended.
 */
class EventDiscovery
{
    public function base(): Builder
    {
        return Event::query()->discoverable()
            ->where(fn ($q) => $q->where('events.ends_at', '>=', now())->orWhere(fn ($q) => $q->whereNull('events.ends_at')->where('events.starts_at', '>=', now()->subHours(12))));
    }

    public function search(Request $request): Builder
    {
        $list = ListQuery::from($request);
        $query = $this->base()->select('events.*');
        $tz = Platform::timezone();

        if ($search = $list->search()) {
            $pattern = $list->searchPattern();
            $query->where(function (Builder $q) use ($search, $pattern) {
                $q->whereRaw("events.search_vector @@ plainto_tsquery('simple', ?)", [$search])
                    ->orWhere('events.title', 'ilike', $pattern)
                    ->orWhere('events.city', 'ilike', $pattern)
                    ->orWhereRaw('events.tags::text ilike ?', [$pattern])
                    ->orWhereHas('organizer', fn ($o) => $o->where('display_name', 'ilike', $pattern))
                    ->orWhereHas('schedules', fn ($s) => $s->whereRaw("venue->>'name' ilike ?", [$pattern]));
            });
        }

        if ($location = trim((string) $request->query('location', ''))) {
            if (preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/', $location, $m)) {
                $km = max(1, min(500, (float) $request->query('distanceKm', 25)));
                $query->whereExists(fn ($s) => $s->from('event_schedules')->whereColumn('event_schedules.event_id', 'events.id')
                    ->whereNotNull('lat')
                    ->whereRaw('6371 * acos(least(1, cos(radians(?)) * cos(radians(lat)) * cos(radians(lng) - radians(?)) + sin(radians(?)) * sin(radians(lat)))) <= ?', [(float) $m[1], (float) $m[2], (float) $m[1], $km]));
            } else {
                $pattern = '%'.addcslashes($location, '%_\\').'%';
                $query->where(fn ($q) => $q->where('events.city', 'ilike', $pattern)
                    ->orWhereExists(fn ($s) => $s->from('event_schedules')->whereColumn('event_schedules.event_id', 'events.id')
                        ->where(fn ($v) => $v->where('city', 'ilike', $pattern)->orWhereRaw("venue->>'state' ilike ?", [$pattern]))));
            }
        }

        if ($slug = $list->filter('categorySlug')) {
            $query->whereHas('category', fn ($c) => $c->where('slug', $slug));
        }
        if ($from = $list->dateFrom()) {
            $query->where(fn ($q) => $q->where('events.ends_at', '>=', $from)->orWhere('events.starts_at', '>=', $from));
        }
        if ($to = $list->dateTo()) {
            $query->where('events.starts_at', '<=', $to);
        }
        if ($mode = $list->filter('attendanceMode')) {
            $query->where('events.attendance_mode', $mode);
        }
        if ($list->bool('freeOnly')) {
            $query->where('events.is_free', true);
        }
        if ($bucket = $list->filter('timeOfDay')) {
            $hour = "extract(hour from events.starts_at at time zone '".str_replace("'", '', $tz)."')";
            match ($bucket) {
                'morning' => $query->whereRaw("$hour >= 5 and $hour < 12"),
                'afternoon' => $query->whereRaw("$hour >= 12 and $hour < 17"),
                'evening' => $query->whereRaw("($hour >= 17 or $hour < 5)"),
                default => throw ApiException::validation('Invalid time of day.', ['timeOfDay' => ['The selected time of day is invalid.']]),
            };
        }

        $sort = (string) $request->query('sort', $list->search() ? 'relevance' : 'date');
        match ($sort) {
            'price' => $query->orderBy('events.is_free', 'desc')->orderBy('events.min_price')->orderBy('events.starts_at'),
            'popularity' => $query->orderByDesc('events.tickets_sold')->orderBy('events.starts_at'),
            'relevance' => $list->search()
                ? $query->orderByRaw("ts_rank(events.search_vector, plainto_tsquery('simple', ?)) desc", [$list->search()])->orderByRaw('similarity(events.title, ?) desc', [$list->search()])->orderBy('events.starts_at')
                : $query->orderBy('events.starts_at'),
            'date' => $query->orderBy('events.starts_at')->orderBy('events.id'),
            default => throw ApiException::validation('Unknown sort field.', ['sort' => ['The selected sort is invalid.']]),
        };

        return $query;
    }

    /** @return list<array{type:string,label:string,value:string,meta?:string}> */
    public function suggestions(string $q, ?string $location): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return $this->trending();
        }
        $pattern = '%'.addcslashes($q, '%_\\').'%';
        $out = [];

        $events = $this->base()->where('title', 'ilike', $pattern)
            ->when($location, fn ($w) => $w->where('city', 'ilike', '%'.addcslashes((string) $location, '%_\\').'%'))
            ->orderByRaw('similarity(title, ?) desc', [$q])->limit(5)->get(['id', 'title', 'slug', 'city']);
        foreach ($events as $e) {
            $out[] = ['type' => 'event', 'label' => $e->title, 'value' => $e->slug, 'meta' => $e->city ?? ''];
        }
        $vendors = DB::table('vendor_profiles')->whereNull('deleted_at')->where('status', 'active')->where('business_name', 'ilike', $pattern)
            ->orderByRaw('similarity(business_name, ?) desc', [$q])->limit(3)->get(['business_name', 'slug', 'location']);
        foreach ($vendors as $v) {
            $out[] = ['type' => 'vendor', 'label' => $v->business_name, 'value' => $v->slug, 'meta' => $v->location];
        }
        $categories = DB::table('event_categories')->where('is_active', true)->where('name', 'ilike', $pattern)->limit(3)->get(['name', 'slug']);
        foreach ($categories as $c) {
            $out[] = ['type' => 'category', 'label' => $c->name, 'value' => $c->slug];
        }
        foreach ($this->locations($q, 2) as $loc) {
            $out[] = ['type' => 'location', 'label' => $loc['label'], 'value' => $loc['value']];
        }

        return array_slice($out, 0, 10);
    }

    /** @return list<array{label:string,value:string}> */
    public function locations(string $q, int $limit = 10): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $pattern = addcslashes($q, '%_\\').'%';
        $cities = DB::table('event_schedules')->whereNotNull('city')->where('city', 'ilike', $pattern)
            ->select('city', DB::raw("max(venue->>'state') as state"), DB::raw('count(*) as n'))
            ->groupBy('city')->orderByDesc('n')->limit($limit)->get();

        return $cities->map(fn ($c) => ['label' => $c->state && strcasecmp($c->state, $c->city) !== 0 ? $c->city.', '.$c->state : $c->city, 'value' => $c->city])->all();
    }

    /** @return list<array{type:string,label:string,value:string}> */
    private function trending(): array
    {
        return DB::table('events')->join('event_categories', 'event_categories.id', '=', 'events.category_id')
            ->where('events.status', 'live')->where('events.visibility', 'public')->whereNull('events.deleted_at')
            ->select('event_categories.name', 'event_categories.slug', DB::raw('sum(events.tickets_sold) as sold'))
            ->groupBy('event_categories.name', 'event_categories.slug')->orderByDesc('sold')->limit(6)->get()
            ->map(fn ($c) => ['type' => 'trending', 'label' => $c->name, 'value' => $c->slug])->all();
    }
}
