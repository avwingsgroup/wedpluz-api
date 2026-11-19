<?php

namespace App\Console\Commands;

use App\Jobs\HealthPingJob;
use App\Services\Platform\HealthCheck;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * CLI health report. With --ping-queue it also pushes a probe job through each
 * Redis queue connection and waits for a worker to run it, proving the full
 * dispatch → Redis → worker → handler path.
 */
class HealthCommand extends Command
{
    protected $signature = 'wedpluz:health {--ping-queue : Dispatch a probe job per queue connection and wait for a worker} {--json : Print the raw report}';

    protected $description = 'Check database, Redis, cache, queues, scheduler, storage and integrations';

    public function handle(HealthCheck $health): int
    {
        $report = $health->details();

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderReport($report);
        }

        $pingOk = true;
        if ($this->option('ping-queue')) {
            $pingOk = $this->pingQueues();
        }

        return $report['status'] === 'down' || ! $pingOk ? self::FAILURE : self::SUCCESS;
    }

    private function renderReport(array $report): void
    {
        $this->newLine();
        $this->line('Overall: <'.($report['status'] === 'ok' ? 'info' : ($report['status'] === 'down' ? 'error' : 'comment')).'>'.strtoupper($report['status']).'</>');
        $rows = [];
        foreach ($report['checks'] as $name => $check) {
            if ($name === 'integrations') {
                continue;
            }
            $detail = collect($check)->except(['status', 'latencyMs', 'queues', 'configured'])
                ->map(fn ($v, $k) => $k.'='.(is_array($v) ? json_encode($v) : var_export($v, true)))->implode('  ');
            $rows[] = [$name, $check['status'], ($check['latencyMs'] ?? '').' ms', Str::limit($detail, 110)];
        }
        $this->table(['Check', 'Status', 'Latency', 'Detail'], $rows);

        if (! empty($report['checks']['queue']['queues'])) {
            $this->table(['Queue', 'Pending', 'Delayed', 'Reserved'], collect($report['checks']['queue']['queues'])
                ->map(fn ($q, $name) => [$name, $q['pending'], $q['delayed'], $q['reserved']])->values()->all());
        }
        $configured = $report['checks']['integrations']['configured'] ?? [];
        $this->line('Integrations configured: '.collect($configured)->filter()->keys()->implode(', '));
        $this->line('Not configured: '.(collect($configured)->reject()->keys()->implode(', ') ?: 'none'));
    }

    private function pingQueues(): bool
    {
        $targets = [[config('queue.default'), 'default']];
        if (config('queue.long_connection') !== config('queue.default')) {
            $targets[] = [config('queue.long_connection'), 'long'];
        }

        $ok = true;
        foreach ($targets as [$connection, $queue]) {
            $token = Str::random(16);
            $start = microtime(true);
            HealthPingJob::dispatch($token)->onConnection($connection)->onQueue($queue);

            $done = null;
            while (microtime(true) - $start < 15) {
                if ($done = Cache::get('health:ping:'.$token)) {
                    break;
                }
                usleep(200_000);
            }
            if ($done) {
                $this->info(sprintf('Queue %s/%s: processed by a worker in %d ms', $connection, $queue, ($done - $start) * 1000));
            } else {
                $ok = false;
                $this->error(sprintf('Queue %s/%s: no worker picked the probe up within 15 s. Start one: php artisan queue:work %s --queue=%s', $connection, $queue, $connection, $queue === 'long' ? 'long' : 'critical,notifications,broadcasts,default,low --sleep=1'));
            }
        }

        return $ok;
    }
}
