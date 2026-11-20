<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\VendorProfile;
use App\Services\Events\EventPresenter;
use App\Services\Media\ImageUrl;
use App\Support\Platform;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * SEO endpoints for pages whose URLs only the API knows (outside /api/v1):
 *
 * - GET /sitemaps/index.xml, /sitemaps/events.xml, /sitemaps/vendors.xml (?page=N,
 *   50,000 URLs per file). Public live events plus completed ones for 90 days;
 *   active vendors. Cached for an hour and invalidated when an event or vendor changes.
 * - GET /share/events/{slug}, /share/vendors/{slug}: a small HTML document with
 *   Open Graph / X card tags and JSON-LD for link-preview bots, which do not run
 *   JavaScript. The web host routes only preview bots here. 404 when not public,
 *   410 when deleted, 301 from a renamed record's old slug.
 */
class SeoController extends Controller
{
    public const PER_FILE = 50000;

    public const CACHE_VERSION_KEY = 'seo:sitemap:version';

    public function __construct(private readonly ImageUrl $images) {}

    /** Bump on any event/vendor change so sitemaps regenerate on the next request. */
    public static function invalidate(): void
    {
        Cache::forever(self::CACHE_VERSION_KEY, (int) Cache::get(self::CACHE_VERSION_KEY, 0) + 1);
    }

    // ---------------------------------------------------------------- sitemaps

    public function sitemapIndex(): Response
    {
        $xml = $this->cached('index', function () {
            $api = rtrim((string) config('app.url'), '/');
            $files = [];
            foreach (['events' => $this->eventsQuery()->count(), 'vendors' => $this->vendorsQuery()->count()] as $name => $count) {
                $pages = max(1, (int) ceil($count / self::PER_FILE));
                $lastmod = $name === 'events' ? $this->eventsQuery()->max('updated_at') : $this->vendorsQuery()->max('updated_at');
                for ($p = 1; $p <= $pages; $p++) {
                    $files[] = ['loc' => $api.'/sitemaps/'.$name.'.xml'.($p > 1 ? '?page='.$p : ''), 'lastmod' => $lastmod];
                }
            }

            $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
            foreach ($files as $f) {
                $out .= '  <sitemap><loc>'.$this->x($f['loc']).'</loc>'.($f['lastmod'] ? '<lastmod>'.$this->date($f['lastmod']).'</lastmod>' : '').'</sitemap>'."\n";
            }

            return $out.'</sitemapindex>'."\n";
        });

        return $this->xml($xml);
    }

    public function sitemapEvents(): Response
    {
        $page = max(1, (int) request()->query('page', 1));
        $xml = $this->cached('events:'.$page, fn () => $this->urlset(
            $this->eventsQuery()->orderBy('id')->forPage($page, self::PER_FILE)->get(['id', 'slug', 'cover_url', 'title', 'updated_at']),
            fn (Event $e) => [$this->web('/events/'.$e->slug), $e->updated_at, $e->cover_url, $e->title],
        ));

        return $xml === null ? $this->xml('', 404) : $this->xml($xml);
    }

    public function sitemapVendors(): Response
    {
        $page = max(1, (int) request()->query('page', 1));
        $xml = $this->cached('vendors:'.$page, fn () => $this->urlset(
            $this->vendorsQuery()->orderBy('id')->forPage($page, self::PER_FILE)->get(['id', 'slug', 'avatar_url', 'cover_url', 'business_name', 'updated_at']),
            fn (VendorProfile $v) => [$this->web('/vendors/'.$v->slug), $v->updated_at, $v->cover_url ?: $v->avatar_url, $v->business_name],
        ));

        return $xml === null ? $this->xml('', 404) : $this->xml($xml);
    }

    private function eventsQuery()
    {
        return Event::query()->where('visibility', 'public')
            ->where(fn ($q) => $q->where('status', 'live')
                ->orWhere(fn ($c) => $c->where('status', 'completed')->where(fn ($d) => $d->where('ends_at', '>=', now()->subDays(90))->orWhere(fn ($n) => $n->whereNull('ends_at')->where('completed_at', '>=', now()->subDays(90))))));
    }

    private function vendorsQuery()
    {
        return VendorProfile::query()->where('status', 'active');
    }

    /** @return string|null null when the page is beyond the last one */
    private function urlset($records, callable $map): ?string
    {
        if ($records->isEmpty() && (int) request()->query('page', 1) > 1) {
            return null;
        }
        $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'."\n";
        foreach ($records as $record) {
            [$loc, $lastmod, $image, $title] = $map($record);
            $out .= '  <url><loc>'.$this->x($loc).'</loc>'.($lastmod ? '<lastmod>'.$this->date($lastmod).'</lastmod>' : '');
            if ($image) {
                $out .= '<image:image><image:loc>'.$this->x((string) $this->images->transform($image, 'cover', allowRemote: false)).'</image:loc><image:title>'.$this->x((string) $title).'</image:title></image:image>';
            }
            $out .= '</url>'."\n";
        }

        return $out.'</urlset>'."\n";
    }

    private function cached(string $key, callable $build): ?string
    {
        $version = (int) Cache::get(self::CACHE_VERSION_KEY, 0);

        return Cache::remember('seo:sitemap:'.$version.':'.$key, 3600, $build);
    }

    private function xml(string $body, int $status = 200): Response
    {
        return response($body, $status, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => $status === 200 ? 'public, max-age=3600' : 'no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    // -------------------------------------------------------- link previews

    public function shareEvent(string $slug): Response|RedirectResponse
    {
        $event = Event::withTrashed()->with(['schedules', 'organizer:id,display_name', 'category:id,name'])->where('slug', $slug)->first();
        if ($event === null) {
            return $this->movedTo('event', $slug, Event::class) ?? $this->notFound(404);
        }
        if ($event->trashed()) {
            return $this->notFound(410);
        }
        if (! self::publiclyVisible('event', $event)) {
            return $this->notFound(404);
        }

        $url = $this->web('/events/'.$event->slug);
        $description = $this->summary($event->description) ?: 'Get tickets for '.$event->title.' on Wedpluz.';
        $image = $this->ogImage($event->cover_url ?: EventPresenter::placeholderCover());
        $schedule = $event->schedules->sortBy('starts_at')->first();
        $venue = (array) ($schedule?->venue ?? []);
        $online = ($schedule?->attendance_mode ?? $event->attendance_mode) === 'online';
        $tz = Platform::timezone();

        $jsonLd = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Event',
            'name' => $event->title,
            'description' => $description,
            'url' => $url,
            'image' => [$image],
            'startDate' => $event->starts_at?->setTimezone($tz)->toIso8601String(),
            'endDate' => $event->ends_at?->setTimezone($tz)->toIso8601String(),
            'eventStatus' => 'https://schema.org/EventScheduled',
            'eventAttendanceMode' => 'https://schema.org/'.($online ? 'OnlineEventAttendanceMode' : 'OfflineEventAttendanceMode'),
            'location' => $online
                ? ['@type' => 'VirtualLocation', 'url' => $url]
                : array_filter(['@type' => 'Place', 'name' => $venue['name'] ?? $event->city, 'address' => array_filter([
                    '@type' => 'PostalAddress', 'streetAddress' => $venue['address'] ?? null, 'addressLocality' => $venue['city'] ?? $event->city,
                    'addressRegion' => $venue['state'] ?? null, 'addressCountry' => $venue['countryCode'] ?? 'NG',
                ])]),
            'organizer' => $event->organizer ? ['@type' => 'Person', 'name' => $event->organizer->display_name] : null,
            'offers' => [
                '@type' => 'Offer', 'url' => $url, 'price' => number_format(((int) $event->min_price) / 100, 2, '.', ''),
                'priceCurrency' => $event->currency ?: 'NGN', 'availability' => 'https://schema.org/'.($event->status->value === 'live' ? 'InStock' : 'SoldOut'),
            ],
        ]);

        $when = $event->starts_at?->setTimezone($tz)->format('D j M Y, g:i A');
        $where = $online ? 'Online' : trim(($venue['name'] ?? '').(isset($venue['city']) ? ', '.$venue['city'] : ''), ', ');

        return $this->sharePage($event->title, $description, $url, $image, $jsonLd, array_filter([$when, $where ?: $event->city]), 'Get tickets on Wedpluz');
    }

    public function shareVendor(string $slug): Response|RedirectResponse
    {
        $vendor = VendorProfile::withTrashed()->with('category:id,name')->where('slug', $slug)->first();
        if ($vendor === null) {
            return $this->movedTo('vendor', $slug, VendorProfile::class) ?? $this->notFound(404);
        }
        if ($vendor->trashed()) {
            return $this->notFound(410);
        }
        if (! self::publiclyVisible('vendor', $vendor)) {
            return $this->notFound(404);
        }

        $url = $this->web('/vendors/'.$vendor->slug);
        $description = $this->summary($vendor->description) ?: $vendor->business_name.' on Wedpluz.';
        $image = $this->ogImage($vendor->cover_url ?: ($vendor->avatar_url ?: EventPresenter::placeholderCover()));
        $jsonLd = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            'name' => $vendor->business_name,
            'description' => $description,
            'url' => $url,
            'image' => [$image],
            'address' => ['@type' => 'PostalAddress', 'addressLocality' => $vendor->location, 'addressCountry' => 'NG'],
            'aggregateRating' => $vendor->ratings_count > 0
                ? ['@type' => 'AggregateRating', 'ratingValue' => round((float) $vendor->rating_avg, 1), 'reviewCount' => (int) $vendor->ratings_count]
                : null,
        ]);

        return $this->sharePage($vendor->business_name, $description, $url, $image, $jsonLd, array_filter([$vendor->category?->name, $vendor->location]), 'View on Wedpluz');
    }

    private function sharePage(string $title, string $description, string $url, string $image, array $jsonLd, array $facts, string $cta): Response
    {
        return response()->view('seo.share', compact('title', 'description', 'url', 'image', 'jsonLd', 'facts', 'cta'), 200, [
            'Cache-Control' => 'public, max-age=600',
            // The canonical page lives on the web origin; this copy is for preview bots only.
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    private function notFound(int $status): Response
    {
        return response()->view('seo.share-missing', ['status' => $status, 'home' => $this->web('/')], $status, ['Cache-Control' => 'public, max-age=300', 'X-Robots-Tag' => 'noindex']);
    }

    private function ogImage(string $url): string
    {
        return (string) $this->images->transform($url, 'og', allowRemote: false);
    }

    private function summary(?string $html): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '</li>'], ' ', (string) $html)), ENT_QUOTES | ENT_HTML5)));

        return Str::limit($text, 200);
    }

    private function web(string $path): string
    {
        return rtrim((string) config('wedpluz.web_origin'), '/').$path;
    }

    private function x(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function date($value): string
    {
        return Carbon::parse($value)->toAtomString();
    }
}
