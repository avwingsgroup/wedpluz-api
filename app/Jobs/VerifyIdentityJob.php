<?php

namespace App\Jobs;

use App\Integrations\Support\IntegrationException;
use App\Models\IdentityVerification;
use App\Services\Platform\AlertService;
use App\Services\Users\IdentityVerificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Runs the provider identity check off the request path, retrying transient failures. */
class VerifyIdentityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 90;

    public function __construct(public readonly string $verificationId)
    {
        $this->onQueue('default');
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [30, 120, 600, 1800, 3600];
    }

    public function handle(IdentityVerificationService $service): void
    {
        $verification = IdentityVerification::query()->find($this->verificationId);
        if ($verification === null) {
            return;
        }

        try {
            $service->runCheck($verification);
        } catch (IntegrationException $e) {
            if ($e->transient && $this->attempts() < $this->tries) {
                throw $e;
            }
            app(AlertService::class)->raise('warning', 'Identity verification stuck',
                'Verification '.$verification->id.' could not be completed with '.$e->provider.': '.$e->getMessage(), null, 'idv-stuck:'.$verification->id);
        }
    }
}
