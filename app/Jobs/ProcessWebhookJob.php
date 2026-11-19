<?php

namespace App\Jobs;

use App\Models\WebhookEvent;
use App\Services\Payments\WebhookRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Processes one stored webhook event on the `critical` queue. Handlers are
 * idempotent; the row lock prevents two workers handling the same event.
 */
class ProcessWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 6;

    public int $timeout = 120;

    public function __construct(public readonly string $webhookEventId)
    {
        $this->onQueue('critical');
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [10, 30, 120, 600, 1800, 3600];
    }

    public function handle(WebhookRouter $router): void
    {
        $event = DB::transaction(function () {
            $event = WebhookEvent::query()->whereKey($this->webhookEventId)->lockForUpdate()->first();
            if ($event === null || in_array($event->status, ['processed', 'ignored'], true)) {
                return null;
            }
            $event->forceFill(['status' => 'processing', 'attempts' => $event->attempts + 1])->save();

            return $event;
        });
        if ($event === null) {
            return;
        }

        try {
            $router->route($event);
            $event->forceFill(['status' => 'processed', 'processed_at' => now(), 'error' => null])->save();
        } catch (\Throwable $e) {
            $event->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000)])->save();
            throw $e;
        }
    }
}
