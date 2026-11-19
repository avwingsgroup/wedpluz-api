<?php

namespace App\Services\Finance;

use App\Enums\PayoutStatus;
use App\Enums\SettlementStatus;
use App\Exceptions\ApiException;
use App\Integrations\Payments\PaymentManager;
use App\Integrations\Payments\TransferRequest;
use App\Integrations\Support\IntegrationException;
use App\Jobs\ExecutePayoutJob;
use App\Models\AdminUser;
use App\Models\BankAccount;
use App\Models\Payout;
use App\Models\Settlement;
use App\Models\User;
use App\Services\Admin\TwoFactorService;
use App\Services\Notifications\NotificationService;
use App\Services\Platform\AlertService;
use App\Support\Codes;
use App\Support\Money;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Organiser payouts with four-eyes control (business-rules.md):
 *  1. finance.settlements creates a payout from ready settlements of ONE organiser;
 *  2. a DIFFERENT admin with finance.payouts.approve approves with a fresh 2FA code;
 *  3. the bank transfer completes (paid; settlements paid) or fails (settlements back to ready).
 * Payouts go only to the organiser's verified bank account, after a 48-hour
 * cool-down when bank details changed.
 */
class PayoutService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly PaymentManager $payments,
        private readonly TwoFactorService $twoFactor,
        private readonly AlertService $alerts,
        private readonly NotificationService $notifications,
    ) {}

    /** @param list<string> $settlementIds */
    public function create(array $settlementIds, AdminUser $admin): Payout
    {
        return DB::transaction(function () use ($settlementIds, $admin) {
            $settlements = Settlement::query()->whereIn('id', array_unique($settlementIds))->lockForUpdate()->get();
            if ($settlements->count() !== count(array_unique($settlementIds))) {
                throw ApiException::validation('Some settlements were not found.', ['settlementIds' => ['Select valid settlements.']]);
            }
            if ($settlements->pluck('organizer_user_id')->unique()->count() !== 1) {
                throw ApiException::validation('A payout can only include settlements of one organiser.', ['settlementIds' => ['Select settlements from a single organiser.']]);
            }
            if ($settlements->contains(fn ($s) => $s->status !== SettlementStatus::Ready || $s->payout_id !== null)) {
                throw ApiException::conflict('Only ready settlements that are not already in a payout can be paid out.');
            }
            if ($settlements->pluck('currency')->unique()->count() !== 1) {
                throw ApiException::validation('Settlements use different currencies.', ['settlementIds' => ['Select settlements in one currency.']]);
            }

            $organizerId = $settlements->first()->organizer_user_id;
            $bank = BankAccount::query()->where('user_id', $organizerId)->whereNotNull('verified_at')->orderByDesc('is_default')->orderBy('created_at')->first();
            if ($bank === null) {
                throw ApiException::conflict('The organiser has no verified bank account.');
            }
            $coolDown = (int) config('wedpluz.payouts.bank_change_cooldown_hours', 48);
            $latestBankChange = BankAccount::withTrashed()->where('user_id', $organizerId)->max('updated_at');
            if ($latestBankChange && now()->subHours($coolDown)->lt($latestBankChange)) {
                throw ApiException::conflict('The organiser changed bank details recently. Payouts resume after the '.$coolDown.'-hour cool-down.');
            }

            $amount = (int) $settlements->sum('net_payable');
            if ($amount <= 0) {
                throw ApiException::validation('There is nothing to pay out.', ['settlementIds' => ['The selected settlements have no payable balance.']]);
            }

            $payout = Payout::query()->create([
                'reference' => Codes::reference('PO', 'payouts'),
                'organizer_user_id' => $organizerId,
                'bank_account_snapshot' => [
                    'bankAccountId' => $bank->id,
                    'bankCode' => $bank->bank_code,
                    'bankName' => $bank->bank_name,
                    'accountNumber' => Crypt::encryptString($bank->account_number_encrypted),
                    'accountLast4' => $bank->account_last4,
                    'accountName' => $bank->account_name,
                    'recipientCode' => $bank->provider_recipient_code,
                ],
                'amount' => $amount,
                'currency' => $settlements->first()->currency,
                'provider' => (string) config('wedpluz.payouts.provider', 'paystack'),
                'status' => PayoutStatus::Requested,
                'requested_by_admin_id' => $admin->id,
            ]);
            $payout->settlements()->attach($settlements->pluck('id')->all());
            Settlement::query()->whereIn('id', $settlements->pluck('id'))->update(['payout_id' => $payout->id]);

            return $payout;
        });
    }

    public function approve(Payout $payout, AdminUser $admin, string $otp): Payout
    {
        if ($payout->requested_by_admin_id === $admin->id) {
            throw ApiException::forbidden('A payout must be approved by a different administrator than the one who created it.');
        }
        if (! $this->twoFactor->verifyFor($admin, $otp)) {
            throw ApiException::otpInvalid('Invalid authentication code.', 'otp');
        }

        DB::transaction(function () use ($payout, $admin) {
            $locked = Payout::query()->whereKey($payout->id)->lockForUpdate()->first();
            if ($locked->status !== PayoutStatus::Requested) {
                throw ApiException::conflict('Only requested payouts can be approved.');
            }
            $locked->forceFill(['status' => PayoutStatus::Processing, 'approved_by_admin_id' => $admin->id, 'approved_at' => now()])->save();
            $this->ledger->post([
                LedgerService::debit('organizer_payable:'.$locked->organizer_user_id, $locked->amount),
                LedgerService::credit('payouts_in_transit', $locked->amount),
            ], $locked->currency, 'payout', $locked->id, 'Payout '.$locked->reference);
        });

        ExecutePayoutJob::dispatch($payout->id);

        return $payout->refresh();
    }

    public function reject(Payout $payout, AdminUser $admin, string $reason): Payout
    {
        DB::transaction(function () use ($payout, $reason) {
            $locked = Payout::query()->whereKey($payout->id)->lockForUpdate()->first();
            if ($locked->status !== PayoutStatus::Requested) {
                throw ApiException::conflict('Only requested payouts can be rejected.');
            }
            $locked->forceFill(['status' => PayoutStatus::Rejected, 'rejection_reason' => $reason, 'failure_reason' => $reason, 'approved_by_admin_id' => null])->save();
            Settlement::query()->where('payout_id', $locked->id)->update(['payout_id' => null]);
        });

        return $payout->refresh();
    }

    /** Called by ExecutePayoutJob. */
    public function send(Payout $payout): void
    {
        if ($payout->status !== PayoutStatus::Processing || $payout->provider_reference) {
            return;
        }
        $snapshot = $payout->bank_account_snapshot;
        $result = $this->payments->transferGateway($payout->provider)->transfer(new TransferRequest(
            $payout->reference, $payout->amount, $payout->currency, $snapshot['bankCode'],
            Crypt::decryptString($snapshot['accountNumber']), $snapshot['accountName'], 'Wedpluz payout '.$payout->reference, $snapshot['recipientCode'] ?? null,
        ));
        $payout->forceFill(['provider_reference' => $result->providerReference])->save();

        if ($result->status === 'successful') {
            $this->transferResult($payout->reference, 'successful');
        } elseif ($result->status === 'failed') {
            $this->transferResult($payout->reference, 'failed', (string) $result->failureReason);
        }
    }

    /** Transfer outcome from a webhook or synchronous response. Idempotent. */
    public function transferResult(string $reference, string $status, string $reason = ''): void
    {
        $paid = DB::transaction(function () use ($reference, $status, $reason) {
            $payout = Payout::query()->where('reference', $reference)->lockForUpdate()->first();
            if ($payout === null || $payout->status !== PayoutStatus::Processing) {
                return null;
            }

            if ($status === 'successful') {
                $this->ledger->post([
                    LedgerService::debit('payouts_in_transit', $payout->amount),
                    LedgerService::credit('provider:'.$payout->provider, $payout->amount),
                ], $payout->currency, 'payout', $payout->id, 'Payout settled '.$payout->reference);
                $payout->forceFill(['status' => PayoutStatus::Paid, 'completed_at' => now()])->save();
                Settlement::query()->where('payout_id', $payout->id)->update(['status' => SettlementStatus::Paid->value, 'paid_at' => now()]);

                return $payout;
            }

            $this->ledger->post([
                LedgerService::debit('payouts_in_transit', $payout->amount),
                LedgerService::credit('organizer_payable:'.$payout->organizer_user_id, $payout->amount),
            ], $payout->currency, 'payout', $payout->id, 'Payout failed '.$payout->reference);
            $failure = $reason ?: 'The bank transfer did not complete.';
            $payout->forceFill(['status' => PayoutStatus::Failed, 'failure_reason' => $failure, 'completed_at' => now()])->save();
            Settlement::query()->where('payout_id', $payout->id)->update(['status' => SettlementStatus::Ready->value, 'payout_id' => null, 'failure_reason' => $failure]);
            $this->alerts->raise('warning', 'Payout failed', 'Payout '.$payout->reference.' failed: '.$failure, '/finance/payouts', 'payout-failed:'.$payout->id);

            return null;
        });

        if ($paid) {
            $organizer = User::query()->find($paid->organizer_user_id);
            if ($organizer) {
                $this->notifications->notify($organizer, 'system', [
                    'title' => 'Payout sent',
                    'body' => 'Your payout of '.Money::format($paid->amount, $paid->currency).' has been sent to your bank account.',
                    'data' => ['payoutId' => $paid->id],
                ]);
            }
        }
    }

    public function handleSendFailure(Payout $payout, IntegrationException $e): void
    {
        $this->transferResult($payout->reference, 'failed', $e->getMessage());
    }
}
