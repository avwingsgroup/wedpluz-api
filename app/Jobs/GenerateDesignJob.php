<?php

namespace App\Jobs;

use App\Integrations\Ai\ImageGenerator;
use App\Integrations\Support\IntegrationException;
use App\Models\PlannerGeneration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateDesignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(public readonly string $generationId)
    {
        // Long-running: separate connection whose retry_after exceeds $timeout (config/queue.php).
        $this->onConnection(config('queue.long_connection'))->onQueue('long');
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(ImageGenerator $generator): void
    {
        $generation = PlannerGeneration::query()->find($this->generationId);
        if ($generation === null || ! in_array($generation->status, ['queued', 'generating'], true)) {
            return;
        }
        $generation->forceFill(['status' => 'generating'])->save();

        try {
            $urls = $generator->generate($generation->type, $generation->prompt);
            $generation->forceFill(['status' => $urls ? 'ready' : 'failed', 'result_urls' => $urls, 'provider' => 'image_generation', 'cost_units' => count($urls), 'error' => $urls ? null : 'No designs returned'])->save();
        } catch (IntegrationException $e) {
            if ($e->transient && $this->attempts() < $this->tries) {
                throw $e;
            }
            $generation->forceFill(['status' => 'failed', 'error' => $e->getMessage()])->save();
        }
    }
}
