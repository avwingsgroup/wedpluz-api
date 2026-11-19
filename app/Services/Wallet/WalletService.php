<?php

namespace App\Services\Wallet;

use App\Enums\TransactionStatus;
use App\Exceptions\ApiException;
use App\Http\Resources\Fmt;
use App\Integrations\Payments\PaymentManager;
use App\Integrations\Payments\PaymentRequest;
use App\Integrations\Payments\PaymentVerification;
use App\Integrations\Payments\TransferRequest;
use App\Integrations\Support\IntegrationException;
use App\Jobs\ExecuteWithdrawalJob;
use App\Models\BankAccount;
use App\Models\Payment;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Auth\OtpService;
use App\Services\Finance\LedgerService;
use App\Services\Notifications\NotificationService;
use App\Services\Payments\PaymentService;
use App\Services\Platform\AlertService;
use App\Services\Platform\SettingsService;
use App\Services\Realtime\Realtime;
use App\Support\Codes;
use App\Support\Money;
use App\Support\Platform;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Wallet (business-rules.md, "Wallet"). Balances derive from the ledger;
 * top-ups credit only after provider confirmation; withdrawals require a
 * verified identity, a correct PIN (5 failures lock withdrawals for 30
 * minutes) and limits, debit the balance into `withdrawals_in_transit`
 * immediately, then settle on transfer success or re-credit on failure.
 */
class WalletService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly PaymentManager $payments,
        private readonly OtpService $otp,
        private readonly SettingsService $settings,
        private readonly NotificationService $notifications,
    ) {}

    public function forUser(User $user): Wallet
    {
        return Wallet::query()->firstOrCreate(['user_id' => $user->id], [
            'currency' => config('wedpluz.default_currency', 'NGN'),
            'daily_withdrawal_limit' => (int) config('wedpluz.wallet.daily_withdrawal_limit', 50000000),
        ]);
    }

    public function activate(User $user, string $otpCode): Wallet
    {
        $wallet = $this->forUser($user);
        if ($wallet->is_active) {
            return $wallet;
        }
        $this->otp->verify($user->email, 'wallet_setup', $otpCode, true, 'otpCode');
        $wallet->forceFill(['is_active' => true, 'activated_at' => now()])->save();

        return $wallet;
    }

    /** @param array{pin?:string, pinConfirmation?:string, otpCode?:string, currentPin?:string} $data */
    public function setPin(User $user, array $data): void
    {
        $wallet = $this->forUser($user);
        if (! $wallet->is_active) {
            throw ApiException::validation('Activate your wallet first.', ['pin' => ['Activate your wallet first.']]);
        }
        if (empty($data['pin']) || ! preg_match('/^\d{4}$/', (string) $data['pin'])) {
            throw ApiException::validation('Your PIN must be 4 digits.', ['pin' => ['Your PIN must be 4 digits.']]);
        }
        if (($data['pinConfirmation'] ?? null) !== $data['pin']) {
            throw ApiException::validation('The PINs do not match.', ['pinConfirmation' => ['The PINs do not match.']]);
        }

        if ($wallet->pin_hash !== null) {
            if (! empty($data['currentPin'])) {
                $this->checkPin($wallet, (string) $data['currentPin'], 'currentPin');
            } elseif (! empty($data['otpCode'])) {
                $this->otp->verify($user->email, 'wallet_setup', (string) $data['otpCode'], true, 'otpCode');
            } else {
                throw ApiException::validation('Enter your current PIN or a verification code.', ['currentPin' => ['Enter your current PIN or request a code.']]);
            }
        }

        $wallet->forceFill(['pin_hash' => Hash::make((string) $data['pin']), 'pin_failed_attempts' => 0, 'pin_locked_until' => null])->save();
    }

    /** @return array{transaction:WalletTransaction, authorizationUrl:?string} */
    public function topUp(User $user, int $amount, string $method, string $returnUrl, string $idempotencyKey): array
    {
        $wallet = $this->requireActive($user);
        if ($amount < 10000) {
            throw ApiException::validation('The minimum top-up is ₦100.', ['amount' => ['The minimum top-up is '.Money::format(10000, $wallet->currency).'.']]);
        }
        $provider = $this->payments->resolveProvider(in_array($method, ['card', 'bank_transfer'], true) ? 'card' : $method, $wallet->currency, 'method');

        [$tx, $payment] = DB::transaction(function () use ($wallet, $amount, $provider, $idempotencyKey) {
            $tx = $this->record($wallet, 'top_up', 'credit', $amount, 'pending', 'Wallet top-up', ['provider' => $provider, 'idempotency_key' => $idempotencyKey]);
            $payment = Payment::query()->create([
                'payable_type' => 'wallet_transaction',
                'payable_id' => $tx->id,
                'user_id' => $wallet->user_id,
                'provider' => $provider,
                'provider_reference' => $tx->reference,
                'purpose' => 'top_up',
                'amount' => $amount,
                'currency' => $wallet->currency,
                'status' => 'initialized',
            ]);

            return [$tx, $payment];
        });

        try {
            $result = $this->payments->gateway($provider)->initialize(new PaymentRequest(
                $tx->reference, $amount, $wallet->currency, $user->email, $user->display_name,
                $returnUrl, 'Wedpluz wallet top-up', ['walletTransactionId' => $tx->id, 'purpose' => 'top_up'],
            ));
        } catch (IntegrationException $e) {
            $tx->forceFill(['status' => TransactionStatus::Failed, 'failure_reason' => 'Could not start payment'])->save();
            $payment->forceFill(['status' => 'failed', 'failure_reason' => $e->getMessage()])->save();
            throw ApiException::paymentFailed('We could not start your top-up. Please try again.');
        }

        $payment->forceFill([
            'status' => 'pending',
            'provider_reference' => $result->providerReference,
            'provider_transaction_id' => $result->providerTransactionId,
            'authorization_url' => $result->authorizationUrl,
        ])->save();
        $tx->forceFill(['provider_reference' => $result->providerReference])->save();

        return ['transaction' => $tx->refresh(), 'authorizationUrl' => $result->authorizationUrl];
    }

    public function verifyTopUp(User $user, string $transactionId): WalletTransaction
    {
        $wallet = $this->forUser($user);
        $tx = WalletTransaction::query()->where('wallet_id', $wallet->id)->where('type', 'top_up')->findOrFail($transactionId);
        if ($tx->status !== TransactionStatus::Pending) {
            return $tx;
        }
        $payment = Payment::query()->where('payable_type', 'wallet_transaction')->where('payable_id', $tx->id)->firstOrFail();
        $verification = app(PaymentService::class)->verify($payment);
        $this->applyTopUpVerification($payment, $verification);

        return $tx->refresh();
    }

    /** Idempotent: credit once on provider confirmation. */
    public function applyTopUpVerification(Payment $payment, PaymentVerification $v): void
    {
        if ($v->status === PaymentVerification::PENDING) {
            return;
        }
        $credited = DB::transaction(function () use ($payment, $v) {
            $tx = WalletTransaction::query()->whereKey($payment->payable_id)->lockForUpdate()->first();
            if ($tx === null || $tx->status !== TransactionStatus::Pending) {
                return null;
            }
            if ($v->status === PaymentVerification::FAILED) {
                $tx->forceFill(['status' => TransactionStatus::Failed, 'failure_reason' => $v->failureReason])->save();
                $payment->forceFill(['status' => 'failed', 'failure_reason' => $v->failureReason])->save();

                return null;
            }
            if (($v->amount !== null && $v->amount !== $payment->amount) || ($v->currency !== null && $v->currency !== $payment->currency)) {
                $payment->forceFill(['status' => 'mismatch', 'reconciliation_status' => 'mismatch'])->save();
                app(AlertService::class)->raise('critical', 'Top-up amount mismatch', 'Wallet top-up '.$tx->reference.' does not match the provider amount.', null, 'topup-mismatch:'.$tx->id);

                return null;
            }

            $wallet = Wallet::query()->whereKey($tx->wallet_id)->lockForUpdate()->first();
            $this->ledger->post([
                LedgerService::debit('provider:'.$payment->provider, $payment->amount),
                LedgerService::credit($wallet->availableAccount(), $payment->amount),
            ], $payment->currency, 'wallet_transaction', $tx->id, 'Top-up '.$tx->reference);
            $tx->forceFill(['status' => TransactionStatus::Successful, 'completed_at' => now(), 'fee' => $v->fee])->save();
            $payment->forceFill(['status' => 'succeeded', 'verified_at' => now(), 'provider_transaction_id' => $v->providerTransactionId ?? $payment->provider_transaction_id, 'fee' => $v->fee])->save();

            return [$wallet->refresh(), $tx];
        });

        if ($credited) {
            [$wallet, $tx] = $credited;
            $this->notifyMovement($wallet, $tx, 'wallet_credit', 'Wallet top-up');
        }
    }

    /** POST /wallet/withdrawals */
    public function withdraw(User $user, int $amount, string $bankAccountId, string $pin, string $idempotencyKey): WalletTransaction
    {
        if (! $user->isIdentityVerified()) {
            throw ApiException::identityUnverified('Verify your identity before withdrawing.');
        }
        $wallet = $this->requireActive($user);
        if ($wallet->pin_hash === null) {
            throw ApiException::validation('Set your wallet PIN first.', ['pin' => ['Set your wallet PIN first.']]);
        }
        $this->checkPin($wallet, $pin);

        $min = (int) $this->settings->get('payments.minWithdrawal', 100000);
        $max = (int) $this->settings->get('payments.maxWithdrawal', 500000000);
        if ($amount < $min) {
            throw ApiException::validation('Amount below the minimum withdrawal.', ['amount' => ['The minimum withdrawal is '.Money::format($min, $wallet->currency).'.']]);
        }
        if ($amount > $max) {
            throw ApiException::validation('Amount above the maximum withdrawal.', ['amount' => ['The maximum withdrawal is '.Money::format($max, $wallet->currency).'.']]);
        }

        $bank = BankAccount::query()->where('user_id', $user->id)->find($bankAccountId);
        if ($bank === null) {
            throw ApiException::validation('Choose one of your bank accounts.', ['bankAccountId' => ['The selected bank account is invalid.']]);
        }

        $tx = DB::transaction(function () use ($wallet, $amount, $bank, $idempotencyKey) {
            $locked = Wallet::query()->whereKey($wallet->id)->lockForUpdate()->first();
            $todayStart = now()->setTimezone(Platform::timezone())->startOfDay()->utc();
            $withdrawnToday = (int) WalletTransaction::query()->where('wallet_id', $locked->id)->where('type', 'withdrawal')
                ->whereIn('status', ['pending', 'processing', 'successful'])->where('created_at', '>=', $todayStart)->sum('amount');
            $remainingDaily = max(0, $locked->daily_withdrawal_limit - $withdrawnToday);
            if ($amount > $remainingDaily) {
                throw ApiException::validation('Daily withdrawal limit reached.', ['amount' => ['You can withdraw up to '.Money::format($remainingDaily, $locked->currency).' more today.']]);
            }
            if ($amount > $locked->cached_balance) {
                throw ApiException::insufficientFunds();
            }

            $tx = $this->record($locked, 'withdrawal', 'debit', $amount, 'processing', 'Withdrawal to '.$bank->bank_name.' ****'.$bank->account_last4, [
                'bank_account_id' => $bank->id,
                'counterparty' => $bank->account_name,
                'provider' => config('wedpluz.wallet.payout_provider', 'paystack'),
                'idempotency_key' => $idempotencyKey,
            ]);
            $this->ledger->post([
                LedgerService::debit($locked->availableAccount(), $amount),
                LedgerService::credit('withdrawals_in_transit', $amount),
            ], $locked->currency, 'wallet_transaction', $tx->id, 'Withdrawal '.$tx->reference);

            return $tx;
        });

        ExecuteWithdrawalJob::dispatch($tx->id);
        $this->notifyMovement($wallet->refresh(), $tx, 'wallet_debit', 'Withdrawal');

        return $tx->refresh();
    }

    /** Called by ExecuteWithdrawalJob: send the bank transfer. */
    public function sendWithdrawal(WalletTransaction $tx): void
    {
        if ($tx->status !== TransactionStatus::Processing || $tx->provider_reference) {
            return;
        }
        $bank = BankAccount::withTrashed()->findOrFail($tx->bank_account_id);
        $gateway = $this->payments->transferGateway($tx->provider);

        $result = $gateway->transfer(new TransferRequest(
            $tx->reference, $tx->amount, $tx->currency, $bank->bank_code, $bank->account_number_encrypted, $bank->account_name,
            'Wedpluz wallet withdrawal', $bank->provider_recipient_code,
        ));

        if ($result->recipientCode && ! $bank->provider_recipient_code) {
            $bank->forceFill(['provider_recipient_code' => $result->recipientCode])->save();
        }
        $tx->forceFill(['provider_reference' => $result->providerReference])->save();

        if ($result->status === 'successful') {
            $this->withdrawalResult($tx->reference, 'successful');
        } elseif ($result->status === 'failed') {
            $this->withdrawalResult($tx->reference, 'failed', (string) $result->failureReason);
        }
    }

    /** Transfer outcome (webhook or synchronous). Idempotent. */
    public function withdrawalResult(string $reference, string $status, string $reason = ''): void
    {
        $outcome = DB::transaction(function () use ($reference, $status, $reason) {
            $tx = WalletTransaction::query()->where('reference', $reference)->where('type', 'withdrawal')->lockForUpdate()->first();
            if ($tx === null) {
                return null;
            }
            $wallet = Wallet::query()->whereKey($tx->wallet_id)->lockForUpdate()->first();

            if ($status === 'successful') {
                if ($tx->status !== TransactionStatus::Processing) {
                    return null;
                }
                $this->ledger->post([
                    LedgerService::debit('withdrawals_in_transit', $tx->amount),
                    LedgerService::credit('provider:'.$tx->provider, $tx->amount),
                ], $tx->currency, 'wallet_transaction', $tx->id, 'Withdrawal settled '.$tx->reference);
                $tx->forceFill(['status' => TransactionStatus::Successful, 'completed_at' => now()])->save();

                return [$wallet, $tx, null];
            }

            // failed or reversed: re-credit the wallet (also after a previous success for reversals).
            if (in_array($tx->status, [TransactionStatus::Reversed, TransactionStatus::Failed], true)) {
                return null;
            }
            $source = $tx->status === TransactionStatus::Successful ? 'provider:'.$tx->provider : 'withdrawals_in_transit';
            $this->ledger->post([
                LedgerService::debit($source, $tx->amount),
                LedgerService::credit($wallet->availableAccount(), $tx->amount),
            ], $tx->currency, 'wallet_transaction', $tx->id, 'Withdrawal reversed '.$tx->reference);
            $tx->forceFill(['status' => TransactionStatus::Reversed, 'failure_reason' => $reason ?: 'The bank transfer did not complete.', 'completed_at' => now()])->save();
            $reversal = $this->record($wallet, 'reversal', 'credit', $tx->amount, 'successful', 'Reversal of withdrawal '.$tx->reference, ['related_type' => 'wallet_transaction', 'related_id' => $tx->id]);

            return [$wallet, $tx, $reversal];
        });

        if ($outcome) {
            [$wallet, $tx, $reversal] = $outcome;
            if ($reversal) {
                $this->notifyMovement($wallet->refresh(), $reversal, 'wallet_credit', 'Withdrawal reversed');
            } else {
                Realtime::toUser($wallet->user_id, 'wallet.updated', $this->walletUpdate($wallet->refresh(), $tx));
            }
        }
    }

    /** Credit settled promotion earnings to a promoter's wallet. */
    public function creditEarning(string $userId, int $amount, string $currency, string $description, string $relatedId): void
    {
        if ($amount <= 0) {
            return;
        }
        $wallet = Wallet::query()->firstOrCreate(['user_id' => $userId], ['currency' => $currency, 'daily_withdrawal_limit' => (int) config('wedpluz.wallet.daily_withdrawal_limit')]);
        $wallet = Wallet::query()->whereKey($wallet->id)->lockForUpdate()->first();
        $this->ledger->post([
            LedgerService::debit('promoter_payable', $amount),
            LedgerService::credit($wallet->availableAccount(), $amount),
        ], $currency, 'promoter_commission', $relatedId, $description);
        $this->record($wallet, 'promotion_earning', 'credit', $amount, 'successful', $description, ['related_type' => 'promoter_commission', 'related_id' => $relatedId]);
    }

    public function record(Wallet $wallet, string $type, string $direction, int $amount, string $status, string $description, array $extra = []): WalletTransaction
    {
        return WalletTransaction::query()->create([
            'reference' => Codes::reference(match ($type) {
                'top_up' => 'TOP', 'withdrawal' => 'WD', 'refund' => 'RFW', default => 'WTX'
            }, 'wallet_transactions'),
            'wallet_id' => $wallet->id,
            'type' => $type,
            'direction' => $direction,
            'amount' => $amount,
            'fee' => 0,
            'currency' => $wallet->currency,
            'status' => $status,
            'description' => mb_substr($description, 0, 255),
            'counterparty' => $extra['counterparty'] ?? null,
            'provider' => $extra['provider'] ?? null,
            'bank_account_id' => $extra['bank_account_id'] ?? null,
            'related_type' => $extra['related_type'] ?? null,
            'related_id' => $extra['related_id'] ?? null,
            'idempotency_key' => $extra['idempotency_key'] ?? null,
            'metadata' => $extra['metadata'] ?? null,
            'completed_at' => $status === 'successful' ? now() : null,
        ]);
    }

    private function requireActive(User $user): Wallet
    {
        $wallet = $this->forUser($user);
        if (! $wallet->is_active) {
            throw ApiException::validation('Activate your wallet first.', ['wallet' => ['Your wallet is not active.']]);
        }

        return $wallet;
    }

    /** PIN check with persistent failure counting and a 30-minute lock after 5 failures. */
    private function checkPin(Wallet $wallet, string $pin, string $field = 'pin'): void
    {
        $max = (int) config('wedpluz.wallet.pin_max_attempts', 5);
        $result = DB::transaction(function () use ($wallet, $pin, $max) {
            $locked = Wallet::query()->whereKey($wallet->id)->lockForUpdate()->first();
            if ($locked->pin_locked_until && $locked->pin_locked_until->isFuture()) {
                return ['locked', $locked->pin_locked_until];
            }
            if (Hash::check($pin, (string) $locked->pin_hash)) {
                $locked->forceFill(['pin_failed_attempts' => 0, 'pin_locked_until' => null])->save();

                return ['ok', null];
            }
            $attempts = $locked->pin_failed_attempts + 1;
            $lockUntil = $attempts >= $max ? now()->addMinutes((int) config('wedpluz.wallet.pin_lock_minutes', 30)) : null;
            $locked->forceFill(['pin_failed_attempts' => $lockUntil ? 0 : $attempts, 'pin_locked_until' => $lockUntil])->save();

            return [$lockUntil ? 'locked' : 'invalid', $lockUntil ?? ($max - $attempts)];
        });

        if ($result[0] === 'locked') {
            $minutes = max(1, (int) ceil(now()->diffInSeconds($result[1]) / 60));
            throw new ApiException('Too many incorrect PIN attempts. Withdrawals are locked for '.$minutes.' minute'.($minutes === 1 ? '' : 's').'.', 'PIN_INVALID', 422, [$field => ['Withdrawals are temporarily locked.']]);
        }
        if ($result[0] === 'invalid') {
            throw new ApiException('Incorrect wallet PIN. '.$result[1].' attempt'.($result[1] === 1 ? '' : 's').' left.', 'PIN_INVALID', 422, [$field => ['Incorrect PIN.']]);
        }
    }

    private function notifyMovement(Wallet $wallet, WalletTransaction $tx, string $type, string $label): void
    {
        $user = User::query()->find($wallet->user_id);
        if ($user) {
            $this->notifications->notify($user, $type, [
                'tokens' => ['amount' => Money::format($tx->amount, $tx->currency)],
                'data' => ['transactionId' => $tx->id],
                'dedupe' => 'wallet:'.$tx->id.':'.$tx->status->value,
                'email' => ['description' => $label, 'amount' => Money::format($tx->amount, $tx->currency), 'balance' => Money::format($wallet->cached_balance, $wallet->currency)],
            ]);
        }
        Realtime::toUser($wallet->user_id, 'wallet.updated', $this->walletUpdate($wallet, $tx));
    }

    private function walletUpdate(Wallet $wallet, WalletTransaction $tx): array
    {
        return ['balance' => $wallet->cached_balance, 'pendingBalance' => $wallet->cached_pending_balance, 'transaction' => self::transaction($tx)];
    }

    public static function resource(Wallet $wallet): array
    {
        $default = BankAccount::query()->where('user_id', $wallet->user_id)->orderByDesc('is_default')->orderBy('created_at')->first();

        return [
            'id' => $wallet->id,
            'balance' => $wallet->cached_balance,
            'pendingBalance' => $wallet->cached_pending_balance,
            'currency' => $wallet->currency,
            'isActive' => $wallet->is_active,
            'pinSet' => $wallet->pin_hash !== null,
            'bankAccount' => $default ? self::bankAccount($default) : null,
            'dailyWithdrawalLimit' => $wallet->daily_withdrawal_limit,
        ];
    }

    public static function transaction(WalletTransaction $t): array
    {
        return [
            'id' => $t->id,
            'reference' => $t->reference,
            'type' => $t->type->value,
            'direction' => $t->direction,
            'amount' => $t->amount,
            'fee' => $t->fee,
            'currency' => $t->currency,
            'status' => $t->status->value,
            'description' => $t->description,
            'counterparty' => $t->counterparty,
            'provider' => $t->provider,
            'createdAt' => Fmt::date($t->created_at),
            'completedAt' => Fmt::date($t->completed_at),
            'metadata' => (object) array_map('strval', array_filter($t->metadata ?? [], 'is_scalar')),
        ];
    }

    public static function bankAccount(BankAccount $b): array
    {
        return [
            'id' => $b->id,
            'bankCode' => $b->bank_code,
            'bankName' => $b->bank_name,
            'accountNumber' => '******'.$b->account_last4,
            'accountName' => $b->account_name,
            'isDefault' => $b->is_default,
        ];
    }
}
