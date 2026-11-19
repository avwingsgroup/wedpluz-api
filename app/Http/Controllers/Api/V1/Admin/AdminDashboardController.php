<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\SystemAlert;
use App\Services\Admin\AdminPresenter;
use App\Services\Admin\MetricsService;
use App\Services\Events\EventPresenter;
use App\Services\Platform\AlertService;
use App\Support\Platform;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Executive dashboard. Aggregates are cached for 10 minutes (warmed by the
 * scheduler) so requests never scan transactional tables directly; alerts and
 * recent activity are always fresh.
 */
class AdminDashboardController extends Controller
{
    public function __invoke(Request $request, MetricsService $metrics, EventPresenter $events): JsonResponse
    {
        [$from, $to] = $metrics->period($request->query('from'), $request->query('to'));
        $data = Cache::remember('admin:dashboard:'.$from->timestamp.':'.$to->timestamp, 600, fn () => $this->aggregate($metrics, $events, $from, $to));

        $data['recentActivity'] = AuditLog::query()->with('admin')->latest('created_at')->limit(10)->get()->map(fn ($l) => AdminPresenter::audit($l))->all();
        $data['alerts'] = $this->alerts();

        return $this->ok($data);
    }

    public function aggregate(MetricsService $metrics, EventPresenter $events, $from, $to): array
    {
        $tz = str_replace("'", '', Platform::timezone());
        $now = now();
        $paidOrders = fn ($q) => $q->whereNotNull('paid_at');
        $topEvents = Event::query()->whereIn('id', DB::table('orders')->whereNotNull('paid_at')->whereBetween('paid_at', [$from, $to])
            ->groupBy('event_id')->orderByRaw('sum(total) desc')->limit(5)->pluck('event_id'))->get();
        $summaries = collect($events->summaries($topEvents, null, true))->keyBy('id');
        $eventRevenue = DB::table('orders')->whereNotNull('paid_at')->whereBetween('paid_at', [$from, $to])->whereIn('event_id', $topEvents->pluck('id'))
            ->selectRaw('event_id, sum(subtotal - discount) r, sum((select sum(quantity) from order_lines ol where ol.order_id = orders.id)) t')->groupBy('event_id')->get()->keyBy('event_id');

        return [
            'period' => ['from' => $from->setTimezone($tz)->toDateString(), 'to' => $to->setTimezone($tz)->toDateString()],
            'users' => [
                'total' => DB::table('users')->whereNull('anonymized_at')->count(),
                'active' => DB::table('users')->whereNull('anonymized_at')->where('last_seen_at', '>=', $now->copy()->subDays(30))->count(),
                'inactive' => DB::table('users')->whereNull('anonymized_at')->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $now->copy()->subDays(30)))->count(),
                'newInPeriod' => $metrics->kpi('users', 'created_at', 'count(*)', $from, $to, fn ($q) => $q->whereNull('anonymized_at')),
                'verified' => DB::table('users')->where('identity_status', 'verified')->count(),
            ],
            'events' => [
                'total' => DB::table('events')->whereNull('deleted_at')->count(),
                'upcoming' => DB::table('events')->whereNull('deleted_at')->where('status', 'live')->where('starts_at', '>', $now)->count(),
                'ongoing' => DB::table('events')->whereNull('deleted_at')->where('status', 'live')->where('starts_at', '<=', $now)->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))->count(),
                'completed' => DB::table('events')->whereNull('deleted_at')->where('status', 'completed')->count(),
                'pendingReview' => DB::table('events')->whereNull('deleted_at')->where('status', 'pending_review')->count(),
                'createdInPeriod' => $metrics->kpi('events', 'created_at', 'count(*)', $from, $to, fn ($q) => $q->whereNull('deleted_at')),
            ],
            'tickets' => [
                'sold' => $metrics->kpi('tickets', 'created_at', 'count(*)', $from, $to, fn ($q) => $q->where('is_additional_guest', false)),
                'bookings' => $metrics->kpi('orders', 'paid_at', 'count(*)', $from, $to, $paidOrders),
                'checkIns' => DB::table('tickets')->whereBetween('checked_in_at', [$from, $to])->count(),
            ],
            'finance' => [
                'gtv' => $metrics->kpi('orders', 'paid_at', 'sum(total)', $from, $to, $paidOrders),
                'platformRevenue' => $metrics->kpi('orders', 'paid_at', 'sum(service_fee)', $from, $to, $paidOrders),
                'pendingSettlements' => (int) DB::table('settlements')->whereIn('status', ['pending', 'ready', 'on_hold'])->sum('net_payable'),
                'refundsAmount' => (int) DB::table('refunds')->where('status', 'completed')->whereBetween('completed_at', [$from, $to])->sum('amount'),
                'refundsCount' => DB::table('refunds')->whereBetween('created_at', [$from, $to])->count(),
                'currency' => 'NGN',
            ],
            'community' => [
                'communities' => DB::table('communities')->where('status', 'active')->count(),
                'messagesInPeriod' => DB::table('messages')->whereBetween('created_at', [$from, $to])->count(),
                'postsInPeriod' => DB::table('status_posts')->whereBetween('created_at', [$from, $to])->count(),
                'reportsOpen' => DB::table('content_reports')->whereIn('status', ['open', 'reviewing'])->count(),
            ],
            'topEvents' => $topEvents->map(fn ($e) => [
                'event' => $summaries[$e->id],
                'ticketsSold' => (int) ($eventRevenue[$e->id]->t ?? 0),
                'revenue' => (int) ($eventRevenue[$e->id]->r ?? 0),
            ])->sortByDesc('revenue')->values()->all(),
            'acquisitionBySource' => collect(['web', 'android', 'ios'])->map(fn ($s) => [
                'source' => $s,
                'users' => DB::table('users')->where('signup_source', $s)->whereBetween('created_at', [$from, $to])->count(),
            ])->all(),
            'gatewayHealth' => array_map(fn ($g) => array_diff_key($g, ['series' => true]), $metrics->gatewayHealth()),
        ];
    }

    private function alerts(): array
    {
        $alerts = SystemAlert::query()->whereNull('resolved_at')->latest()->limit(10)->get()->map(fn ($a) => AlertService::resource($a))->all();
        $stale = DB::table('events')->where('status', 'pending_review')->where('submitted_at', '<', now()->subDay())->count();
        if ($stale > 0) {
            array_unshift($alerts, [
                'id' => 'alr_review_sla', 'severity' => 'warning', 'title' => 'Events awaiting review',
                'description' => $stale.' event'.($stale === 1 ? ' has' : 's have').' been awaiting review for more than 24h.',
                'link' => '/events?status=pending_review', 'createdAt' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            ]);
        }

        return $alerts;
    }
}
