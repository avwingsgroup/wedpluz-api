<?php

namespace App\Jobs;

use App\Integrations\Support\IntegrationException;
use App\Models\Payout;
use App\Services\Finance\PayoutService;
use App\Services\Platform\AlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Sends an approved organiser payout to the bank; transient errors retry. */
class ExecutePayoutJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(public readonly string $payoutId)
    {
        $this->onQueue('critical');
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [30, 120, 600, 1800, 3600];
    }

    public function handle(PayoutService $payouts): void
    {
        $payout = Payout::query()->find($this->payoutId);
        if ($payout === null) {
            return;
        }

        try {
            $payouts->send($payout);
        } catch (IntegrationException $e) {
            if ($e->transient && $this->attempts() < $this->tries) {
                throw $e;
            }
            if ($e->transient) {
                app(AlertService::class)->raise('critical', 'Payout stuck', 'Payout '.$payout->reference.' could not be sent: '.$e->getMessage(), '/finance/payouts', 'payout-stuck:'.$payout->id);

                return;
            }
            $payouts->handleSendFailure($payout, $e);
        }
    }
}
