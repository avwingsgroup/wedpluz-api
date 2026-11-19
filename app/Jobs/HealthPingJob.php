<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/** Round-trip probe used by `php artisan wedpluz:health --ping-queue`. */
class HealthPingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 10;

    public function __construct(public readonly string $token) {}

    public function handle(): void
    {
        Cache::put('health:ping:'.$this->token, microtime(true), 120);
    }
}
