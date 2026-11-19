<?php

namespace App\Services\Subscriptions;

use App\Enums\SubscriptionStatus;
use App\Exceptions\ApiException;
use App\Http\Resources\Fmt;
use App\Integrations\Payments\PaymentManager;
use App\Integrations\Payments\PaymentRequest;
use App\Integrations\Payments\PaymentVerification;
use App\Integrations\Support\IntegrationException;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Finance\LedgerService;
use App\Services\Notifications\NotificationService;
use App\Services\Wallet\WalletService;
use App\Support\Codes;
use App\Support\Platform;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Premium plans (business-rules.md, "Subscriptions"). Upgrades apply
 * immediately with proration; downgrades and cancellations take effect at
 * renewsAt; past_due keeps features for a 3-day grace period, then reverts to
 * free. Plans activate only on payment confirmation.
 */
class SubscriptionService
{
    public const GRACE_DAYS = 3;

    public function __construct(
        private readonly PaymentManager $payments,
        private readonly LedgerService $ledger,
        private readonly WalletService $wallets,
        private readonly NotificationService $notifications,
    ) {}

    public function current(User $user): ?Subscription
    {
        return Subscription::query()->with(['plan', 'pendingPlan'])->where('user_id', $user->id)
            ->whereIn('status', ['active', 'trialing', 'past_due'])->latest('started_at')->first();
    }

    /** @return array{subscription:array, authorizationUrl:?string} */
    public function subscribe(User $user, string $planId, string $interval, string $method, string $idempotencyKey, string $returnUrl): array
    {
        $plan = Plan::query()->find($planId);
        if ($plan === null) {
            throw ApiException::validation('Unknown plan.', ['planId' => ['The selected plan is invalid.']]);
        }
        $current = $this->current($user);

        if ($plan->tier === 'free') {
            if ($current) {
                $this->cancel($user);
            }

            return ['subscription' => $this->resource($this->current($user), $user), 'authorizationUrl' => null];
        }

        $price = $interval === 'yearly' ? $plan->price_yearly : $plan->price_monthly;
        $currentRank = PlanGate::TIERS[$current?->plan?->tier ?? 'free'] ?? 0;
        $newRank = PlanGate::TIERS[$plan->tier];

        if ($current && $current->plan_id === $plan->id && $current->interval === $interval && $current->status !== SubscriptionStatus::PastDue) {
            if ($current->cancelled_at) {
                $current->forceFill(['cancelled_at' => null, 'pending_plan_id' => null, 'pending_interval' => null])->save();

                return ['subscription' => $this->resource($current->refresh(), $user), 'authorizationUrl' => null];
            }
            throw ApiException::conflict('You are already on this plan.');
        }

        if ($current && $newRank < $currentRank && $current->status !== SubscriptionStatus::PastDue) {
            // Downgrade: takes effect at renewal, no charge now.
            $current->forceFill(['pending_plan_id' => $plan->id, 'pending_interval' => $interval])->save();

            return ['subscription' => $this->resource($current->refresh(), $user), 'authorizationUrl' => null];
        }

        $amount = $price;
        if ($current && $newRank > $currentRank && $current->status === SubscriptionStatus::Active && $current->renews_at?->isFuture()) {
            $amount = max(0, $price - $this->unusedCredit($current));
        }

        $spay = SubscriptionPayment::query()->create([
            'reference' => Codes::reference('SUB', 'subscription_payments'),
            'user_id' => $user->id,
            'subscription_id' => $current?->id,
            'plan_id' => $plan->id,
            'interval' => $interval,
            'payment_method' => $method,
            'amount' => $amount,
            'currency' => $plan->currency,
            'status' => 'pending',
            'idempotency_key' => $idempotencyKey,
        ]);

        if ($amount === 0) {
            $this->activate($spay);

            return ['subscription' => $this->resource($this->current($user), $user), 'authorizationUrl' => null];
        }

        if ($method === 'wallet') {
            $this->payWithWallet($user, $spay);

            return ['subscription' => $this->resource($this->current($user), $user), 'authorizationUrl' => null];
        }

        $provider = $this->payments->resolveProvider($method, $plan->currency);
        $payment = Payment::query()->create([
            'payable_type' => 'subscription_payment', 'payable_id' => $spay->id, 'user_id' => $user->id,
            'provider' => $provider, 'provider_reference' => $spay->reference, 'purpose' => 'subscription',
            'amount' => $amount, 'currency' => $plan->currency, 'status' => 'initialized',
        ]);
        $spay->forceFill(['payment_id' => $payment->id])->save();

        try {
            $result = $this->payments->gateway($provider)->initialize(new PaymentRequest(
                $spay->reference, $amount, $plan->currency, $user->email, $user->display_name, $returnUrl,
                'Wedpluz '.$plan->name.' ('.$interval.')', ['subscriptionPaymentId' => $spay->id, 'purpose' => 'subscription'],
            ));
        } catch (IntegrationException) {
            $spay->forceFill(['status' => 'failed'])->save();
            $payment->forceFill(['status' => 'failed'])->save();
            throw ApiException::paymentFailed('We could not start your subscription payment. Please try again.');
        }
        $payment->forceFill([
            'status' => 'pending', 'provider_reference' => $result->providerReference,
            'provider_transaction_id' => $result->providerTransactionId, 'authorization_url' => $result->authorizationUrl,
        ])->save();

        return ['subscription' => $this->resource($current, $user, $plan, $interval), 'authorizationUrl' => $result->authorizationUrl];
    }

    public function applyVerification(Payment $payment, PaymentVerification $v): void
    {
        $spay = SubscriptionPayment::query()->find($payment->payable_id);
        if ($spay === null || $spay->status !== 'pending') {
            return;
        }
        if ($v->status === PaymentVerification::FAILED) {
            $spay->forceFill(['status' => 'failed'])->save();
            $payment->forceFill(['status' => 'failed', 'failure_reason' => $v->failureReason])->save();

            return;
        }
        if ($v->status !== PaymentVerification::SUCCEEDED) {
            return;
        }
        if (($v->amount !== null && $v->amount !== $payment->amount) || ($v->currency !== null && $v->currency !== $payment->currency)) {
            $payment->forceFill(['status' => 'mismatch', 'reconciliation_status' => 'mismatch'])->save();

            return;
        }

        DB::transaction(function () use ($payment, $spay, $v) {
            $payment->forceFill(['status' => 'succeeded', 'verified_at' => now(), 'fee' => $v->fee, 'provider_transaction_id' => $v->providerTransactionId ?? $payment->provider_transaction_id])->save();
            $this->ledger->post([
                LedgerService::debit('provider:'.$payment->provider, $payment->amount),
                LedgerService::credit('subscriptions:revenue', $payment->amount),
            ], $payment->currency, 'subscription_payment', $spay->id, 'Subscription '.$spay->reference);
            $this->activate($spay);
        });
    }

    /** Activate (or upgrade/renew) after a confirmed payment. Idempotent per payment. */
    public function activate(SubscriptionPayment $spay): Subscription
    {
        return DB::transaction(function () use ($spay) {
            $locked = SubscriptionPayment::query()->whereKey($spay->id)->lockForUpdate()->first();
            $existing = Subscription::query()->where('user_id', $locked->user_id)->whereIn('status', ['active', 'trialing', 'past_due'])->lockForUpdate()->first();
            if ($locked->status === 'successful') {
                return $existing;
            }
            $plan = Plan::query()->findOrFail($locked->plan_id);
            $now = CarbonImmutable::now();
            $renews = $locked->interval === 'yearly' ? $now->addYear() : $now->addMonth();

            if ($existing && $existing->plan_id === $plan->id && $existing->status === SubscriptionStatus::PastDue) {
                $existing->forceFill(['status' => SubscriptionStatus::Active, 'renews_at' => $renews, 'past_due_at' => null])->save();
                $subscription = $existing;
            } else {
                $existing?->forceFill(['status' => SubscriptionStatus::Cancelled, 'cancelled_at' => $now])->save();
                $subscription = Subscription::query()->create([
                    'user_id' => $locked->user_id, 'plan_id' => $plan->id, 'interval' => $locked->interval,
                    'status' => SubscriptionStatus::Active, 'started_at' => $now, 'renews_at' => $renews,
                    'provider' => Payment::query()->whereKey($locked->payment_id)->value('provider') ?? $locked->payment_method,
                ]);
            }

            $locked->forceFill(['status' => 'successful', 'paid_at' => $now, 'subscription_id' => $subscription->id])->save();
            User::query()->whereKey($locked->user_id)->update(['plan' => $plan->tier]);

            return $subscription;
        });
    }

    public function cancel(User $user): ?Subscription
    {
        $current = $this->current($user);
        if ($current === null) {
            throw ApiException::notFound('You have no active subscription.');
        }
        $current->forceFill(['cancelled_at' => now(), 'pending_plan_id' => null, 'pending_interval' => null])->save();

        return $current->refresh();
    }

    /** Scheduled: renewals, downgrades at renewal, past-due grace, expiry. */
    public function processRenewals(): void
    {
        Subscription::query()->with('plan')->whereIn('status', ['active', 'trialing'])->where('renews_at', '<=', now())
            ->each(function (Subscription $s) {
                $user = User::query()->find($s->user_id);
                if ($user === null) {
                    return;
                }
                if ($s->cancelled_at) {
                    $this->expire($s, $user);

                    return;
                }
                $plan = $s->pending_plan_id ? Plan::query()->find($s->pending_plan_id) : $s->plan;
                $interval = $s->pending_interval ?? $s->interval;
                $price = $interval === 'yearly' ? $plan->price_yearly : $plan->price_monthly;

                if ($this->renewFromWallet($user, $s, $plan, $interval, $price)) {
                    return;
                }
                $s->forceFill(['status' => SubscriptionStatus::PastDue, 'past_due_at' => now()])->save();
                $this->notifications->notify($user, 'system', [
                    'title' => 'Renew your plan',
                    'body' => 'Your '.$s->plan->name.' plan is due for renewal. Renew within '.self::GRACE_DAYS.' days to keep your features.',
                    'link' => Platform::webUrl('/pricing'),
                ]);
            });

        Subscription::query()->where('status', 'past_due')->where('past_due_at', '<=', now()->subDays(self::GRACE_DAYS))
            ->each(fn (Subscription $s) => ($u = User::query()->find($s->user_id)) ? $this->expire($s, $u) : null);
    }

    private function renewFromWallet(User $user, Subscription $s, Plan $plan, string $interval, int $price): bool
    {
        $wallet = Wallet::query()->where('user_id', $user->id)->first();
        if ($price <= 0 || ! $wallet?->is_active || $wallet->currency !== $plan->currency || $wallet->cached_balance < $price) {
            return false;
        }
        $spay = SubscriptionPayment::query()->create([
            'reference' => Codes::reference('SUB', 'subscription_payments'), 'user_id' => $user->id, 'subscription_id' => $s->id,
            'plan_id' => $plan->id, 'interval' => $interval, 'payment_method' => 'wallet', 'amount' => $price, 'currency' => $plan->currency, 'status' => 'pending',
        ]);
        try {
            $this->payWithWallet($user, $spay);
        } catch (ApiException) {
            $spay->forceFill(['status' => 'failed'])->save();

            return false;
        }

        return true;
    }

    private function payWithWallet(User $user, SubscriptionPayment $spay): void
    {
        DB::transaction(function () use ($user, $spay) {
            $wallet = Wallet::query()->where('user_id', $user->id)->lockForUpdate()->first();
            if ($wallet === null || ! $wallet->is_active) {
                throw ApiException::validation('Activate your wallet to pay with it.', ['paymentMethod' => ['Your wallet is not active.']]);
            }
            if ($wallet->cached_balance < $spay->amount) {
                throw ApiException::insufficientFunds();
            }
            $this->ledger->post([
                LedgerService::debit($wallet->availableAccount(), $spay->amount),
                LedgerService::credit('subscriptions:revenue', $spay->amount),
            ], $spay->currency, 'subscription_payment', $spay->id, 'Subscription '.$spay->reference);
            $this->wallets->record($wallet, 'subscription', 'debit', $spay->amount, 'successful', 'Wedpluz plan subscription', ['related_type' => 'subscription_payment', 'related_id' => $spay->id, 'provider' => 'wallet']);
            $this->activate($spay);
        });
    }

    private function expire(Subscription $s, User $user): void
    {
        DB::transaction(function () use ($s, $user) {
            $s->forceFill(['status' => SubscriptionStatus::Cancelled, 'cancelled_at' => $s->cancelled_at ?? now()])->save();
            $user->forceFill(['plan' => 'free'])->save();
        });
    }

    private function unusedCredit(Subscription $s): int
    {
        $paid = (int) SubscriptionPayment::query()->where('subscription_id', $s->id)->where('status', 'successful')->latest('paid_at')->value('amount');
        $total = max(1, $s->started_at->diffInSeconds($s->renews_at));
        $left = max(0, now()->diffInSeconds($s->renews_at, false));

        return (int) round($paid * $left / $total);
    }

    /** Subscription resource; users with no subscription are on the free plan. */
    public function resource(?Subscription $s, User $user, ?Plan $pendingPlan = null, ?string $pendingInterval = null): array
    {
        if ($s === null) {
            $free = Plan::query()->where('tier', 'free')->first();

            return [
                'id' => 'subn_free_'.$user->id,
                'plan' => $free ? self::plan($free) : ['id' => 'plan_free', 'tier' => 'free', 'name' => 'Free', 'tagline' => 'For everyone', 'priceMonthly' => 0, 'priceYearly' => 0, 'currency' => 'NGN', 'features' => [], 'inheritsFrom' => null, 'isPopular' => false],
                'interval' => 'monthly',
                'status' => 'active',
                'startedAt' => Fmt::date($user->created_at),
                'renewsAt' => null,
                'cancelledAt' => null,
            ];
        }

        return [
            'id' => $s->id,
            'plan' => self::plan($s->plan),
            'interval' => $s->interval,
            'status' => $s->status->value,
            'startedAt' => Fmt::date($s->started_at),
            'renewsAt' => Fmt::date($s->renews_at),
            'cancelledAt' => Fmt::date($s->cancelled_at),
        ];
    }

    public static function plan(Plan $p): array
    {
        return [
            'id' => $p->id,
            'tier' => $p->tier,
            'name' => $p->name,
            'tagline' => $p->tagline,
            'priceMonthly' => $p->price_monthly,
            'priceYearly' => $p->price_yearly,
            'currency' => $p->currency,
            'features' => array_values($p->features ?? []),
            'inheritsFrom' => $p->inherits_from,
            'isPopular' => $p->is_popular,
        ];
    }
}
