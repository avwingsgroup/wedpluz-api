<?php

namespace App\Services\Finance;

use App\Enums\SettlementStatus;
use App\Exceptions\ApiException;
use App\Models\Event;
use App\Models\Order;
use App\Models\PromoterCommission;
use App\Models\Settlement;
use App\Services\Platform\SettingsService;
use App\Services\Wallet\WalletService;
use App\Support\Codes;
use Illuminate\Support\Facades\DB;

/**
 * One settlement per event per organiser, created when the event completes.
 *
 *   netPayable = grossSales − refunds − platformCommission − gatewayFees − promoterCommissions ± adjustments
 *
 * Commission uses each order's snapshot. A settlement becomes `ready`
 * settlementDelayDays after the event ends if there are no open disputes and
 * neither the event nor the organiser is suspended; promoter commissions are
 * then credited to promoters' wallets.
 */
class SettlementService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly SettingsService $settings,
        private readonly WalletService $wallets,
    ) {}

    public function createForEvent(Event $event): Settlement
    {
        $existing = Settlement::query()->where('event_id', $event->id)->where('organizer_user_id', $event->organizer_user_id)->first();
        if ($existing) {
            return $this->recompute($existing);
        }
        $ended = $event->ends_at ?? $event->starts_at ?? now();
        $settlement = Settlement::query()->create([
            'reference' => Codes::reference('ST', 'settlements'),
            'organizer_user_id' => $event->organizer_user_id,
            'event_id' => $event->id,
            'currency' => $event->currency,
            'status' => SettlementStatus::Pending,
            'eligible_at' => ($ended->isFuture() ? now() : $ended)->addDays((int) $this->settings->get('payments.settlementDelayDays', 3)),
        ]);

        return $this->recompute($settlement);
    }

    /** Recalculate the figures of a pending or on-hold settlement from orders, refunds and commissions. */
    public function recompute(Settlement $settlement): Settlement
    {
        if (in_array($settlement->status, [SettlementStatus::Paid], true) || $settlement->payout_id) {
            return $settlement;
        }

        $orders = Order::query()->where('event_id', $settlement->event_id)->whereNotNull('paid_at')->where('total', '>', 0)
            ->get(['id', 'subtotal', 'discount', 'service_fee', 'total', 'commission_rule_snapshot']);
        $orderIds = $orders->pluck('id');

        $organizerRefunds = DB::table('refunds')->whereIn('order_id', $orderIds)->whereIn('status', ['approved', 'processing', 'completed'])
            ->selectRaw('order_id, sum(amount - fee_portion) as organizer_part')->groupBy('order_id')->pluck('organizer_part', 'order_id');

        $gross = 0;
        $refunds = 0;
        $commission = 0;
        foreach ($orders as $order) {
            $revenue = max(0, $order->subtotal - $order->discount);
            $refunded = min($revenue, (int) ($organizerRefunds[$order->id] ?? 0));
            $gross += $revenue;
            $refunds += $refunded;
            $commission += CommissionResolver::commissionFor($revenue - $refunded, $order->commission_rule_snapshot);
        }

        // When fees are not passed to buyers, provider fees on those orders are borne by the organiser.
        $gatewayFees = (int) DB::table('payments')->where('payable_type', 'order')->whereIn('payable_id', $orders->where('service_fee', 0)->pluck('id'))
            ->where('status', 'succeeded')->sum('fee');
        $promoter = (int) PromoterCommission::query()->whereIn('order_id', $orderIds)->whereIn('status', ['pending', 'credited'])->sum('amount');

        $net = $gross - $refunds - $commission - $gatewayFees - $promoter + $settlement->adjustments;

        $settlement->forceFill([
            'gross_sales' => $gross,
            'refunds' => $refunds,
            'platform_commission' => $commission,
            'gateway_fees' => $gatewayFees,
            'promoter_commissions' => $promoter,
            'net_payable' => max(0, $net),
        ])->save();

        return $settlement;
    }

    /** Scheduled: move eligible pending settlements to ready, recording why others wait. */
    public function promoteEligible(): int
    {
        $count = 0;
        Settlement::query()->where('status', SettlementStatus::Pending->value)->where('eligible_at', '<=', now())->whereNull('payout_id')
            ->orderBy('eligible_at')->chunkById(100, function ($settlements) use (&$count) {
                foreach ($settlements as $settlement) {
                    if ($this->markReadyIfClear($settlement)) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    public function markReadyIfClear(Settlement $settlement): bool
    {
        return DB::transaction(function () use ($settlement) {
            $s = Settlement::query()->whereKey($settlement->id)->lockForUpdate()->first();
            if ($s->status !== SettlementStatus::Pending) {
                return false;
            }
            $this->recompute($s);

            $reason = $this->blockingReason($s);
            if ($reason !== null) {
                $s->forceFill(['pending_reason' => $reason])->save();

                return false;
            }

            // Retain commission and gateway fees from organiser revenue; release promoter earnings.
            $payable = 'organizer_payable:'.$s->organizer_user_id;
            $this->ledger->post([
                LedgerService::debit($payable, $s->platform_commission + $s->gateway_fees + $s->promoter_commissions),
                LedgerService::credit('platform:commission', $s->platform_commission),
                LedgerService::credit('platform:fees', $s->gateway_fees),
                LedgerService::credit('promoter_payable', $s->promoter_commissions),
            ], $s->currency, 'settlement', $s->id, 'Settlement '.$s->reference.' deductions');

            $commissions = PromoterCommission::query()->with('enrollment')
                ->whereIn('order_id', Order::query()->where('event_id', $s->event_id)->select('id'))
                ->where('status', 'pending')->lockForUpdate()->get();
            foreach ($commissions as $c) {
                $this->wallets->creditEarning($c->enrollment->user_id, $c->amount, $c->currency, 'Promotion earnings', $c->id);
                $c->forceFill(['status' => 'credited', 'credited_at' => now()])->save();
            }

            $s->forceFill(['status' => SettlementStatus::Ready, 'pending_reason' => null])->save();

            return true;
        });
    }

    public function blockingReason(Settlement $s): ?string
    {
        $event = Event::withTrashed()->find($s->event_id);
        if ($event?->status->value === 'suspended') {
            return 'The event is suspended.';
        }
        if (DB::table('users')->where('id', $s->organizer_user_id)->value('status') === 'suspended') {
            return 'The organiser is suspended.';
        }
        $openDisputes = DB::table('disputes')->whereIn('order_id', Order::query()->where('event_id', $s->event_id)->select('id'))
            ->whereIn('status', ['open', 'investigating'])->exists();

        return $openDisputes ? 'There are open disputes or chargebacks on this event\'s orders.' : null;
    }

    public function hold(Settlement $settlement, string $reason): Settlement
    {
        if (! in_array($settlement->status, [SettlementStatus::Pending, SettlementStatus::Ready], true) || $settlement->payout_id) {
            throw ApiException::conflict('Only pending or ready settlements without a payout can be held.');
        }
        $settlement->forceFill(['status' => SettlementStatus::OnHold, 'hold_reason' => $reason])->save();

        return $settlement;
    }

    public function release(Settlement $settlement): Settlement
    {
        if ($settlement->status !== SettlementStatus::OnHold) {
            throw ApiException::conflict('Only settlements on hold can be released.');
        }
        $settlement->forceFill(['status' => SettlementStatus::Pending, 'hold_reason' => null])->save();
        if ($settlement->eligible_at->isPast()) {
            $this->markReadyIfClear($settlement);
        }

        return $settlement->refresh();
    }
}
