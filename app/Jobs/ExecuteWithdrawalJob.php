<?php

namespace App\Jobs;

use App\Integrations\Support\IntegrationException;
use App\Models\WalletTransaction;
use App\Services\Platform\AlertService;
use App\Services\Wallet\WalletService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends a wallet withdrawal to the bank. Transient provider errors retry; a
 * permanent rejection reverses the withdrawal back into the wallet. The
 * transfer reference (WD-...) makes provider-side retries idempotent.
 */
class ExecuteWithdrawalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(public readonly string $transactionId)
    {
        $this->onQueue('critical');
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [30, 120, 600, 1800, 3600];
    }

    public function handle(WalletService $wallets): void
    {
        $tx = WalletTransaction::query()->find($this->transactionId);
        if ($tx === null) {
            return;
        }

        try {
            $wallets->sendWithdrawal($tx);
        } catch (IntegrationException $e) {
            if ($e->transient && $this->attempts() < $this->tries) {
                throw $e;
            }
            if ($e->transient) {
                app(AlertService::class)->raise('critical', 'Withdrawal stuck', 'Withdrawal '.$tx->reference.' could not be sent: '.$e->getMessage(), '/finance/transactions?search='.$tx->reference, 'withdrawal-stuck:'.$tx->id);

                return;
            }
            $wallets->withdrawalResult($tx->reference, 'failed', $e->getMessage());
        }
    }
}
