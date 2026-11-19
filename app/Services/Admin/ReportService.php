<?php

namespace App\Services\Admin;

use App\Exceptions\ApiException;
use App\Support\Platform;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Admin reports (api-contracts.md §3.12). Each definition declares a base
 * query, a date column, the dimensions it can be grouped by and its metrics.
 * `run` is synchronous and capped at 10,000 rows; exports go through ExportJob.
 */
class ReportService
{
    public const ROW_CAP = 10000;

    /** @return array<string, array{name:string, description:string, dimensions:list<string>, metrics:array<string,array{0:string,1:string,2:string}>}> */
    public function definitions(): array
    {
        $temporal = ['day', 'week', 'month'];

        return [
            'payments' => ['name' => 'Payments', 'description' => 'Provider transactions, volume and success rate.', 'dimensions' => [...$temporal, 'provider'],
                'metrics' => ['transactions' => ['Transactions', 'number', 'count(*)'], 'volume' => ['Volume', 'money', "coalesce(sum(p.amount) filter (where p.status = 'succeeded'), 0)"], 'successRate' => ['Success rate', 'percent', "round(100.0 * count(*) filter (where p.status = 'succeeded') / greatest(count(*), 1), 1)"], 'fees' => ['Provider fees', 'money', 'coalesce(sum(p.fee), 0)']]],
            'user_acquisition' => ['name' => 'User acquisition', 'description' => 'New sign-ups by channel and location.', 'dimensions' => [...$temporal, 'source', 'city'],
                'metrics' => ['signups' => ['Sign-ups', 'number', 'count(*)'], 'verified' => ['Email verified', 'number', 'count(*) filter (where u.email_verified_at is not null)']]],
            'user_engagement' => ['name' => 'User engagement', 'description' => 'Active users and messages sent.', 'dimensions' => $temporal,
                'metrics' => ['messages' => ['Messages', 'number', 'count(*)'], 'activeSenders' => ['Active senders', 'number', 'count(distinct m.sender_user_id)']]],
            'event_performance' => ['name' => 'Event performance', 'description' => 'Events, tickets and revenue by category or organiser.', 'dimensions' => ['category', 'organizer', 'city', ...$temporal],
                'metrics' => ['events' => ['Events', 'number', 'count(*)'], 'ticketsSold' => ['Tickets sold', 'number', 'coalesce(sum(e.tickets_sold), 0)']]],
            'ticket_sales' => ['name' => 'Ticket sales', 'description' => 'Paid orders and tickets sold.', 'dimensions' => [...$temporal, 'category', 'source'],
                'metrics' => ['orders' => ['Orders', 'number', 'count(*)'], 'tickets' => ['Tickets', 'number', 'coalesce(sum((select sum(quantity) from order_lines ol where ol.order_id = o.id)), 0)'], 'gtv' => ['Gross value', 'money', 'coalesce(sum(o.total), 0)']]],
            'revenue' => ['name' => 'Revenue', 'description' => 'Gross transaction value, fees and refunds.', 'dimensions' => [...$temporal, 'category'],
                'metrics' => ['gtv' => ['Gross value', 'money', 'coalesce(sum(o.total), 0)'], 'serviceFees' => ['Service fees', 'money', 'coalesce(sum(o.service_fee), 0)'], 'refunds' => ['Refunded', 'money', 'coalesce(sum(o.refunded_amount), 0)']]],
            'organizer_performance' => ['name' => 'Organiser performance', 'description' => 'Sales by organiser.', 'dimensions' => ['organizer'],
                'metrics' => ['orders' => ['Orders', 'number', 'count(*)'], 'gross' => ['Gross sales', 'money', 'coalesce(sum(o.subtotal - o.discount), 0)']]],
            'settlements' => ['name' => 'Settlements', 'description' => 'Organiser settlements by status.', 'dimensions' => ['status', 'organizer', ...$temporal],
                'metrics' => ['settlements' => ['Settlements', 'number', 'count(*)'], 'netPayable' => ['Net payable', 'money', 'coalesce(sum(s.net_payable), 0)'], 'commission' => ['Platform commission', 'money', 'coalesce(sum(s.platform_commission), 0)']]],
            'community_engagement' => ['name' => 'Community engagement', 'description' => 'Statuses posted in communities.', 'dimensions' => $temporal,
                'metrics' => ['statuses' => ['Statuses', 'number', 'count(*)'], 'views' => ['Views', 'number', 'coalesce(sum(st.views_count), 0)'], 'likes' => ['Likes', 'number', 'coalesce(sum(st.likes_count), 0)']]],
        ];
    }

    /** ReportDefinition[] */
    public function catalogue(): array
    {
        return collect($this->definitions())->map(fn ($d, $key) => [
            'key' => $key, 'name' => $d['name'], 'description' => $d['description'], 'dimensions' => $d['dimensions'],
            'metrics' => array_keys($d['metrics']), 'exportFormats' => ['csv', 'xlsx', 'pdf'],
        ])->values()->all();
    }

    /** ReportResult */
    public function run(string $key, string $from, string $to, ?string $groupBy, array $filters = []): array
    {
        $def = $this->definitions()[$key] ?? throw ApiException::notFound('Unknown report.');
        $groupBy ??= $def['dimensions'][0];
        if (! in_array($groupBy, $def['dimensions'], true)) {
            throw ApiException::validation('This report cannot be grouped that way.', ['groupBy' => ['Choose one of: '.implode(', ', $def['dimensions']).'.']]);
        }
        [$fromUtc, $toUtc] = app(MetricsService::class)->period($from, $to);
        [$query, $dateColumn] = $this->base($key);
        $query->whereBetween($dateColumn, [$fromUtc, $toUtc]);
        foreach (['eventId' => 'event_id', 'organizerId' => 'organizer_user_id', 'provider' => 'provider'] as $f => $col) {
            if (! empty($filters[$f]) && in_array($col, $this->filterable($key), true)) {
                $query->where($this->alias($key).'.'.$col, $filters[$f]);
            }
        }

        $dimension = $this->dimension($key, $groupBy, $dateColumn);
        $selects = ["$dimension as dimension"];
        foreach ($def['metrics'] as $metric => [, , $expr]) {
            $selects[] = "$expr as \"$metric\"";
        }
        $rows = $query->selectRaw(implode(', ', $selects))->groupByRaw($dimension)->orderByRaw($dimension)->limit(self::ROW_CAP)->get();

        $columns = [['key' => 'dimension', 'label' => ucfirst($groupBy), 'type' => in_array($groupBy, ['day', 'week', 'month'], true) ? 'date' : 'string']];
        foreach ($def['metrics'] as $metric => [$label, $type]) {
            $columns[] = ['key' => $metric, 'label' => $label, 'type' => $type];
        }
        $data = $rows->map(fn ($r) => collect((array) $r)->map(fn ($v, $k) => $k === 'dimension' ? ($v === null ? 'Unknown' : (string) $v) : ($v === null ? null : $v + 0))->all())->all();

        $totals = [];
        foreach ($def['metrics'] as $metric => [, $type]) {
            $values = array_column($data, $metric);
            $totals[$metric] = $type === 'percent' ? (count($values) ? round(array_sum($values) / count($values), 1) : 0) : array_sum($values);
        }
        $series = in_array($groupBy, ['day', 'week', 'month'], true)
            ? collect($def['metrics'])->map(fn ($m, $metric) => ['name' => $m[0], 'points' => array_map(fn ($r) => ['date' => $r['dimension'], 'value' => (int) round((float) ($r[$metric] ?? 0))], $data)])->values()->all()
            : [];

        return ['key' => $key, 'period' => ['from' => $from, 'to' => $to], 'groupBy' => $groupBy, 'columns' => $columns, 'rows' => $data, 'totals' => $totals, 'series' => $series];
    }

    /** @return array{0:Builder, 1:string} */
    private function base(string $key): array
    {
        return match ($key) {
            'payments' => [DB::table('payments as p'), 'p.created_at'],
            'user_acquisition' => [DB::table('users as u')->whereNull('u.anonymized_at'), 'u.created_at'],
            'user_engagement' => [DB::table('messages as m'), 'm.created_at'],
            'event_performance' => [DB::table('events as e')->leftJoin('event_categories as c', 'c.id', '=', 'e.category_id')->leftJoin('users as org', 'org.id', '=', 'e.organizer_user_id')->whereNull('e.deleted_at')->whereNotIn('e.status', ['draft']), 'e.starts_at'],
            'ticket_sales', 'revenue', 'organizer_performance' => [DB::table('orders as o')->join('events as e', 'e.id', '=', 'o.event_id')->leftJoin('event_categories as c', 'c.id', '=', 'e.category_id')->leftJoin('users as org', 'org.id', '=', 'e.organizer_user_id')->whereNotNull('o.paid_at'), 'o.paid_at'],
            'settlements' => [DB::table('settlements as s')->leftJoin('users as org', 'org.id', '=', 's.organizer_user_id'), 's.eligible_at'],
            'community_engagement' => [DB::table('status_posts as st'), 'st.created_at'],
        };
    }

    private function dimension(string $key, string $groupBy, string $dateColumn): string
    {
        $tz = str_replace("'", '', Platform::timezone());

        return match ($groupBy) {
            'day' => "to_char(($dateColumn at time zone '$tz')::date, 'YYYY-MM-DD')",
            'week' => "to_char(date_trunc('week', $dateColumn at time zone '$tz'), 'YYYY-MM-DD')",
            'month' => "to_char(date_trunc('month', $dateColumn at time zone '$tz'), 'YYYY-MM')",
            'provider' => 'p.provider',
            'source' => $key === 'user_acquisition' ? 'u.signup_source' : 'o.source',
            'city' => $key === 'user_acquisition' ? 'u.city' : 'e.city',
            'category' => 'c.name',
            'organizer' => 'org.display_name',
            'status' => 's.status',
        };
    }

    private function alias(string $key): string
    {
        return ['payments' => 'p', 'event_performance' => 'e', 'settlements' => 's'][$key] ?? 'o';
    }

    /** @return list<string> */
    private function filterable(string $key): array
    {
        return match ($key) {
            'payments' => ['provider'],
            'ticket_sales', 'revenue', 'organizer_performance' => ['event_id'],
            'settlements' => ['event_id', 'organizer_user_id'],
            'event_performance' => ['organizer_user_id'],
            default => [],
        };
    }
}
