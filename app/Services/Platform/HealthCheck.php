<?php

namespace App\Services\Platform;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Dependency health for /health, /health/details and `php artisan wedpluz:health`.
 *
 * Critical (API cannot serve requests → 503): database, redis, cache.
 * Non-critical (API serves, background work stalls → "degraded"): queue
 * workers, scheduler, private storage.
 *
 * Workers and the scheduler write heartbeats (see AppServiceProvider and
 * routes/console.php), so "is anything consuming the queue?" is answerable
 * without inspecting processes.
 */
class HealthCheck
{
    public const WORKER_KEY = 'health:worker:';

    public const SCHEDULER_KEY = 'health:scheduler';

    /** A worker loops at least every block_for (5 s); allow for a long job in between. */
    private const WORKER_STALE_SECONDS = 120;

    private const SCHEDULER_STALE_SECONDS = 150;

    private const CRITICAL = ['database', 'redis', 'cache'];

    private static int $lastBeat = 0;

    /** Called on every worker loop; writes at most every 15 s per process. */
    public static function beatWorker(string $connection): void
    {
        if (time() - self::$lastBeat < 15) {
            return;
        }
        self::$lastBeat = time();
        try {
            Cache::put(self::WORKER_KEY.$connection, time(), 600);
        } catch (Throwable) {
            // Never let health bookkeeping break a worker.
        }
    }

    public static function beatScheduler(): void
    {
        Cache::put(self::SCHEDULER_KEY, time(), 600);
    }

    /** @return array{status:string, checks:array<string,string>} */
    public function summary(): array
    {
        $results = $this->run(false);
        $checks = array_map(fn ($r) => $r['status'], $results);

        return ['status' => $this->overall($checks), 'checks' => $checks];
    }

    /** @return array{status:string, checks:array<string,array>, checkedAt:string} */
    public function details(): array
    {
        $results = $this->run(true);

        return [
            'status' => $this->overall(array_map(fn ($r) => $r['status'], $results)),
            'checks' => $results,
            'environment' => app()->environment(),
            'checkedAt' => now()->toIso8601ZuluString(),
        ];
    }

    public function httpStatus(string $overall): int
    {
        return $overall === 'down' ? 503 : 200;
    }

    /** @param array<string,string> $checks */
    private function overall(array $checks): string
    {
        foreach (self::CRITICAL as $name) {
            if (($checks[$name] ?? 'ok') === 'down') {
                return 'down';
            }
        }

        return in_array(true, array_map(fn ($s) => $s !== 'ok', $checks), true) ? 'degraded' : 'ok';
    }

    /** @return array<string,array<string,mixed>> */
    private function run(bool $detailed): array
    {
        $checks = [
            'database' => fn () => $this->database($detailed),
            'redis' => fn () => $this->redis(),
            'cache' => fn () => $this->cache(),
            'queue' => fn () => $this->queue($detailed),
            'scheduler' => fn () => $this->scheduler(),
        ];
        if ($detailed) {
            $checks['storage'] = fn () => $this->storage();
        }

        $results = [];
        foreach ($checks as $name => $check) {
            $start = hrtime(true);
            try {
                $result = $check();
            } catch (Throwable $e) {
                $result = ['status' => 'down', 'error' => $detailed ? Str::limit($e->getMessage(), 200) : null];
            }
            $result['latencyMs'] = round((hrtime(true) - $start) / 1e6, 1);
            $results[$name] = array_filter($result, fn ($v) => $v !== null);
        }
        if ($detailed) {
            $results['integrations'] = ['status' => 'ok', 'configured' => $this->integrations()];
        }

        return $results;
    }

    private function database(bool $detailed): array
    {
        DB::select('select 1');
        $out = ['status' => 'ok'];
        if ($detailed) {
            $out['pendingMigrations'] = $this->pendingMigrations();
            if ($out['pendingMigrations'] > 0) {
                $out['status'] = 'degraded';
            }
        }

        return $out;
    }

    private function redis(): array
    {
        Redis::connection()->ping();

        return ['status' => 'ok'];
    }

    private function cache(): array
    {
        $key = 'health:probe:'.Str::random(8);
        $value = Str::random(16); // strings round-trip exactly (numbers come back as numeric strings from Redis)
        Cache::put($key, $value, 10);
        $ok = Cache::get($key) === $value;
        Cache::forget($key);

        return ['status' => $ok ? 'ok' : 'down', 'store' => config('cache.default')];
    }

    private function queue(bool $detailed): array
    {
        $default = (string) config('queue.default');
        if (in_array($default, ['sync', 'deferred', 'background'], true)) {
            return ['status' => 'ok', 'connection' => $default, 'note' => 'Jobs run in-process.'];
        }

        $out = ['status' => 'ok', 'connection' => $default];
        $connections = array_values(array_unique([$default, (string) config('queue.long_connection')]));
        $workers = [];
        foreach ($connections as $connection) {
            $beat = Cache::get(self::WORKER_KEY.$connection);
            $age = $beat ? time() - (int) $beat : null;
            $workers[$connection] = $age;
            if ($age === null || $age > self::WORKER_STALE_SECONDS) {
                $out['status'] = 'stale';
            }
        }

        if ($detailed) {
            $out['workerLastSeenSecondsAgo'] = $workers;
            if (config("queue.connections.$default.driver") === 'redis') {
                $out['queues'] = $this->redisQueueSizes();
            }
            $out['failedJobs'] = DB::table((string) config('queue.failed.table', 'failed_jobs'))->count();
        } elseif ($default === 'redis') {
            // The summary still proves the queue's Redis DB is reachable.
            Redis::connection((string) config('queue.connections.redis.connection', 'default'))->ping();
        }

        return $out;
    }

    /** @return array<string,array{pending:int, delayed:int, reserved:int}> */
    private function redisQueueSizes(): array
    {
        $redis = Redis::connection((string) config('queue.connections.redis.connection', 'default'));
        $sizes = [];
        foreach (['critical', 'notifications', 'broadcasts', 'default', 'low', 'long'] as $queue) {
            $sizes[$queue] = [
                'pending' => (int) $redis->llen('queues:'.$queue),
                'delayed' => (int) $redis->zcard('queues:'.$queue.':delayed'),
                'reserved' => (int) $redis->zcard('queues:'.$queue.':reserved'),
            ];
        }

        return $sizes;
    }

    private function scheduler(): array
    {
        $beat = Cache::get(self::SCHEDULER_KEY);
        $age = $beat ? time() - (int) $beat : null;

        return ['status' => $age !== null && $age <= self::SCHEDULER_STALE_SECONDS ? 'ok' : 'stale', 'lastRunSecondsAgo' => $age];
    }

    private function storage(): array
    {
        $disk = Storage::disk('private');
        $path = 'health/'.Str::random(12).'.txt';
        $disk->put($path, 'ok');
        $ok = $disk->get($path) === 'ok';
        $disk->delete($path);

        return ['status' => $ok ? 'ok' : 'down', 'disk' => (string) config('filesystems.disks.private.driver')];
    }

    private function pendingMigrations(): int
    {
        $ran = DB::table('migrations')->pluck('migration')->all();
        $files = array_map(fn ($f) => pathinfo($f, PATHINFO_FILENAME), glob(database_path('migrations/*.php')) ?: []);

        return count(array_diff($files, $ran));
    }

    /** @return array<string,bool> configured, not reachability (no outbound calls from a probe) */
    private function integrations(): array
    {
        $set = fn (string ...$keys) => array_reduce($keys, fn ($carry, $k) => $carry && filled(config($k)), true);

        return [
            'mail' => config('mail.default') === 'zeptomail' ? $set('services.zeptomail.token') : ! in_array(config('mail.default'), ['log', 'array'], true),
            'cloudinary' => $set('services.cloudinary.cloud_name', 'services.cloudinary.api_key', 'services.cloudinary.api_secret'),
            'paystack' => $set('services.paystack.secret_key'),
            'flutterwave' => $set('services.flutterwave.secret_key'),
            'monnify' => $set('services.monnify.api_key', 'services.monnify.secret_key'),
            'stripe' => $set('services.stripe.secret_key'),
            'paypal' => $set('services.paypal.client_id', 'services.paypal.client_secret'),
            'termii' => $set('services.termii.api_key'),
            'twilio' => $set('services.twilio.account_sid', 'services.twilio.auth_token'),
            'whatsapp' => $set('services.whatsapp.access_token', 'services.whatsapp.phone_number_id'),
            'fcm' => $set('services.fcm.project_id', 'services.fcm.credentials'),
            'prembly' => $set('services.prembly.app_id', 'services.prembly.api_key'),
            'anthropic' => $set('services.anthropic.api_key'),
            'realtime' => config('broadcasting.default') === 'reverb',
        ];
    }
}
