<?php

namespace App\Services\Admin;

use App\Support\Platform;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates for the executive dashboard, finance summary and gateway health.
 * Results are cached for 10 minutes and warmed by the scheduler, so admin
 * pages never scan transactional tables on every request.
 */
class MetricsService
{
    /** @return array{0:CarbonImmutable, 1:CarbonImmutable} UTC bounds for inclusive local dates */
    public function period(?string $from, ?string $to): array
    {
        $tz = Platform::timezone();
        $end = $to ? CarbonImmutable::parse($to, $tz)->endOfDay() : CarbonImmutable::now($tz)->endOfDay();
        $start = $from ? CarbonImmutable::parse($from, $tz)->startOfDay() : $end->subDays(29)->startOfDay();

        return [$start->utc(), $end->utc()];
    }

    /**
     * KpiValue for a table: value in [from, to], previous in the preceding window of equal length, daily trend.
     *
     * @param  callable(Builder): Builder  $scope
     */
    public function kpi(string $table, string $dateColumn, string $aggregate, CarbonImmutable $from, CarbonImmutable $to, callable $scope): array
    {
        $length = $from->diffInSeconds($to);
        $prevFrom = $from->subSeconds((int) $length + 1);
        $prevTo = $from->subSecond();

        $sum = fn (CarbonImmutable $a, CarbonImmutable $b) => (int) $scope(DB::table($table))->whereBetween($dateColumn, [$a, $b])->selectRaw("coalesce($aggregate, 0) as v")->value('v');
        $value = $sum($from, $to);
        $previous = $sum($prevFrom, $prevTo);

        $tz = str_replace("'", '', Platform::timezone());
        $trend = $scope(DB::table($table))->whereBetween($dateColumn, [$from, $to])
            ->selectRaw("($dateColumn at time zone '$tz')::date as d, coalesce($aggregate, 0) as v")
            ->groupBy('d')->orderBy('d')->get()
            ->map(fn ($r) => ['date' => (string) $r->d, 'value' => (int) $r->v])->all();

        return [
            'value' => $value,
            'previous' => $previous,
            'changePercent' => $previous === 0 ? ($value > 0 ? 100.0 : 0.0) : round(($value - $previous) / abs($previous) * 100, 1),
            'trend' => $trend,
        ];
    }

    /** GatewayHealth per payment provider over the last 7 days, from payments and integration logs. */
    public function gatewayHealth(): array
    {
        $since = now()->subDays(7);
        $out = [];
        foreach (['paystack', 'stripe', 'paypal', 'monnify'] as $provider) {
            $payments = DB::table('payments')->where('provider', $provider)->where('created_at', '>=', $since)
                ->selectRaw("count(*) filter (where status = 'succeeded') ok, count(*) filter (where status in ('failed','mismatch')) bad, coalesce(sum(amount) filter (where status = 'succeeded'), 0) vol")->first();
            $calls = DB::table('integration_logs')->where('provider', $provider)->where('created_at', '>=', $since)
                ->selectRaw('count(*) n, count(*) filter (where success) ok, coalesce(avg(latency_ms), 0) lat')->first();
            $attempts = (int) $payments->ok + (int) $payments->bad;
            $successRate = $attempts > 0 ? round($payments->ok / $attempts * 100, 1) : ((int) $calls->n > 0 ? round($calls->ok / $calls->n * 100, 1) : 100.0);
            $series = DB::table('payments')->where('provider', $provider)->where('created_at', '>=', $since)
                ->selectRaw("created_at::date d, count(*) filter (where status='succeeded') v")->groupBy('d')->orderBy('d')->get()
                ->map(fn ($r) => ['date' => (string) $r->d, 'value' => (int) $r->v])->all();

            $out[] = [
                'provider' => $provider,
                'successRate' => $successRate,
                'avgLatencyMs' => (int) round((float) $calls->lat),
                'volume' => (int) $payments->vol,
                'failures' => (int) $payments->bad,
                'status' => $successRate >= 95 ? 'operational' : ($successRate >= 80 ? 'degraded' : 'down'),
                'series' => $series,
            ];
        }

        return $out;
    }
}
