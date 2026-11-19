<?php

use App\Jobs\CancelEventJob;
use App\Jobs\DeliverNotificationJob;
use App\Services\Platform\HealthCheck;
use Illuminate\Support\Facades\Cache;

it('reports dependency status publicly without internals', function () {
    HealthCheck::beatScheduler();

    $response = $this->getJson('/api/v1/health')->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('checks.database', 'ok')
        ->assertJsonPath('checks.redis', 'ok')
        ->assertJsonPath('checks.cache', 'ok')
        ->assertJsonPath('checks.queue', 'ok') // sync in tests
        ->assertHeader('Cache-Control', 'no-store, private');

    expect($response->json('checks'))->not->toHaveKey('integrations')
        ->and(json_encode($response->json()))->not->toContain('latency');
});

it('is degraded (still 200) when the scheduler stops beating', function () {
    Cache::forget(HealthCheck::SCHEDULER_KEY);

    $this->getJson('/api/v1/health')->assertOk()
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('checks.scheduler', 'stale');
});

it('flags stale queue workers when jobs run on redis', function () {
    config(['queue.default' => 'redis', 'queue.long_connection' => 'redis-long']);
    Cache::forget(HealthCheck::WORKER_KEY.'redis');
    HealthCheck::beatScheduler();

    $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('checks.queue', 'stale');

    Cache::put(HealthCheck::WORKER_KEY.'redis', time());
    Cache::put(HealthCheck::WORKER_KEY.'redis-long', time());
    $this->getJson('/api/v1/health')->assertJsonPath('checks.queue', 'ok')->assertJsonPath('status', 'ok');
});

it('protects detailed health with a token', function () {
    config(['wedpluz.health.token' => 'secret-token']);

    $this->getJson('/api/v1/health/details')->assertNotFound();
    $this->withHeader('X-Health-Token', 'wrong')->getJson('/api/v1/health/details')->assertNotFound();
    $this->withHeader('X-Health-Token', 'secret-token')->getJson('/api/v1/health/details')->assertOk()
        ->assertJsonStructure(['status', 'checks' => ['database' => ['status', 'latencyMs', 'pendingMigrations'], 'queue', 'storage', 'integrations' => ['configured']], 'checkedAt']);
});

it('sends long-running jobs to the long connection and fast jobs to the default one', function () {
    config(['queue.long_connection' => 'redis-long']);

    $long = new CancelEventJob('evt_x');
    expect($long->connection)->toBe('redis-long')->and($long->queue)->toBe('long');
    expect((new DeliverNotificationJob('ntd_x', ['body' => 'x']))->connection)->toBeNull();
});
