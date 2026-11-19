<?php

namespace App\Services\Checkout;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Resources\Fmt;
use App\Integrations\Payments\PaymentVerification;
use App\Models\Community;
use App\Models\Event;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PromoterCommission;
use App\Models\Ticket;
use App\Services\Communities\CommunityService;
use App\Services\Finance\CommissionResolver;
use App\Services\Finance\LedgerService;
use App\Services\Notifications\NotificationService;
use App\Services\Platform\AlertService;
use App\Services\Realtime\Realtime;
use App\Support\Codes;
use App\Support\Money;
use App\Support\Platform;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Confirms a paid order exactly once (business-rules.md, "Payments").
 *
 * In one database transaction with the order row locked:
 *   mark paid -> increment sold (conditional) -> issue tickets -> ledger -> promoter attribution.
 * After commit: email, push, realtime. Verify-payment and webhooks race; the
 * second caller finds the order already paid and does nothing. Amount or
 * currency mismatches mark the payment `mismatch`, raise an alert and issue
 * no tickets.
 */
class FulfillmentService
{
    public function __construct(
        private readonly Inventory $inventory,
        private readonly LedgerService $ledger,
        private readonly NotificationService $notifications,
        private readonly AlertService $alerts,
        private readonly CommunityService $communities,
    ) {}

    /** Apply a provider verification result to the payment and its order. */
    public function applyVerification(Payment $payment, PaymentVerification $verification): Order
    {
        $order = Order::query()->findOrFail($payment->payable_id);

        if ($verification->status === PaymentVerification::PENDING) {
            return $order;
        }
        if ($verification->status === PaymentVerification::FAILED) {
            $this->markFailed($order, $payment, $verification->failureReason ?? 'Payment failed');

            return $order->refresh();
        }

        $mismatch = $verification->amount !== null && $verification->amount !== $payment->amount
            || ($verification->currency !== null && strtoupper($verification->currency) !== $payment->currency);
        if ($mismatch) {
            $this->markMismatch($order, $payment, $verification);

            return $order->refresh();
        }

        return $this->confirm($order, $payment, $verification);
    }

    public function confirm(Order $order, Payment $payment, ?PaymentVerification $verification = null): Order
    {
        try {
            $outcome = $this->confirmTransaction($order, $payment, $verification);
        } catch (OversoldException) {
            // Everything in the transaction rolled back. Record the captured payment, then refund.
            DB::transaction(function () use ($order, $payment, $verification) {
                Payment::query()->whereKey($payment->id)->update([
                    'status' => PaymentStatus::Succeeded->value,
                    'provider_transaction_id' => $verification?->providerTransactionId ?? $payment->provider_transaction_id,
                    'verified_at' => now(),
                    'updated_at' => now(),
                ]);
                $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();
                $locked->forceFill(['status' => OrderStatus::Failed])->save();
                $this->releaseReservations($locked);
            });
            $outcome = 'oversold';
        }

        return $this->afterOutcome($order->refresh(), $outcome);
    }

    private function confirmTransaction(Order $order, Payment $payment, ?PaymentVerification $verification): string
    {
        return DB::transaction(function () use ($order, $payment, $verification) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();
            $lockedPayment = Payment::query()->whereKey($payment->id)->lockForUpdate()->first();

            if ($locked->status === OrderStatus::Paid) {
                return 'already_paid';
            }
            if (! in_array($locked->status, [OrderStatus::AwaitingPayment, OrderStatus::Pending, OrderStatus::Expired, OrderStatus::Failed], true)) {
                return 'not_payable';
            }

            $lockedPayment->forceFill([
                'status' => PaymentStatus::Succeeded,
                'provider_transaction_id' => $verification?->providerTransactionId ?? $lockedPayment->provider_transaction_id,
                'fee' => $verification?->fee ?? $lockedPayment->fee,
                'raw_payload' => array_merge($lockedPayment->raw_payload ?? [], $verification?->raw ?? []),
                'verified_at' => now(),
            ])->save();

            // Stock: conditional increments, never read-then-write.
            $lines = $locked->lines()->get();
            foreach ($lines as $line) {
                if (! $this->inventory->incrementSold($line->ticket_type_id, $line->quantity)) {
                    throw new OversoldException;
                }
            }
            DB::table('events')->where('id', $locked->event_id)->increment('tickets_sold', (int) $lines->sum('quantity'));

            $locked->forceFill(['status' => OrderStatus::Paid, 'paid_at' => now()])->save();
            DB::table('inventory_holds')->where('order_id', $locked->id)->whereNull('converted_at')->update(['converted_at' => now()]);
            DB::table('seat_reservations')->where('order_id', $locked->id)->where('status', 'held')->update(['status' => 'confirmed', 'updated_at' => now()]);
            if ($locked->promo_code_id) {
                DB::table('promo_codes')->where('id', $locked->promo_code_id)->increment('used_count');
            }

            $this->issueTickets($locked);
            $this->postLedger($locked, $lockedPayment);
            $this->attributePromoter($locked);

            return 'confirmed';
        });
    }

    private function afterOutcome(Order $order, string $outcome): Order
    {
        if ($outcome === 'oversold') {
            // Hold expired and stock was taken while the buyer paid: refund automatically.
            $this->alerts->raise('critical', 'Paid order could not be fulfilled', 'Order '.$order->reference.' was paid but tickets sold out. An automatic refund was started.', '/bookings/'.$order->id, 'oversold:'.$order->id);
            app(RefundService::class)->refundOversold($order);
        } elseif ($outcome === 'confirmed') {
            $this->afterConfirmed($order);
        } elseif ($outcome === 'not_payable') {
            Log::warning('order.payment_for_unpayable_order', ['order' => $order->id, 'status' => $order->status->value]);
            $this->alerts->raise('warning', 'Payment received for a closed order', 'Order '.$order->reference.' ('.$order->status->value.') received a successful payment. Review and refund if needed.', '/bookings/'.$order->id, 'closed-paid:'.$order->id);
        }

        return $order;
    }

    public function markFailed(Order $order, Payment $payment, string $reason): void
    {
        DB::transaction(function () use ($order, $payment, $reason) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();
            if ($locked->status !== OrderStatus::AwaitingPayment && $locked->status !== OrderStatus::Pending) {
                return;
            }
            Payment::query()->whereKey($payment->id)->where('status', '!=', PaymentStatus::Succeeded->value)
                ->update(['status' => PaymentStatus::Failed->value, 'failure_reason' => mb_substr($reason, 0, 500), 'updated_at' => now()]);
            $locked->forceFill(['status' => OrderStatus::Failed])->save();
            $this->releaseReservations($locked);
        });
        Realtime::toUser($order->user_id, 'order.updated', ['id' => $order->id, 'status' => 'failed', 'reference' => $order->reference]);
    }

    private function markMismatch(Order $order, Payment $payment, PaymentVerification $v): void
    {
        DB::transaction(function () use ($payment, $v) {
            Payment::query()->whereKey($payment->id)->update([
                'status' => PaymentStatus::Mismatch->value,
                'reconciliation_status' => 'mismatch',
                'failure_reason' => 'Amount or currency mismatch: expected '.$payment->amount.' '.$payment->currency.', provider reported '.$v->amount.' '.$v->currency,
                'updated_at' => now(),
            ]);
        });
        $this->alerts->raise('critical', 'Payment amount mismatch', 'Order '.$order->reference.' payment does not match the expected amount. No tickets were issued.', '/finance/transactions?search='.$order->reference, 'mismatch:'.$payment->id);
    }

    /** Release holds and seats for an order that will not be paid. */
    public function releaseReservations(Order $order): void
    {
        DB::table('inventory_holds')->where('order_id', $order->id)->whereNull('converted_at')->whereNull('released_at')->update(['released_at' => now()]);
        DB::table('seat_reservations')->where('order_id', $order->id)->whereIn('status', ['held', 'confirmed'])->update(['status' => 'released', 'updated_at' => now()]);
    }

    /** One ticket per admitted person: each purchased ticket plus each additional guest. */
    private function issueTickets(Order $order): void
    {
        if (Ticket::query()->where('order_id', $order->id)->exists()) {
            return;
        }
        $attendee = $order->attendee;
        $holderName = trim(($attendee['firstName'] ?? '').' '.($attendee['lastName'] ?? ''));
        $seats = DB::table('seat_reservations')->where('order_id', $order->id)->whereIn('status', ['held', 'confirmed'])->orderBy('created_at')->get()->groupBy('schedule_id');
        $seatQueues = $seats->map(fn ($s) => $s->values()->all())->all();
        $firstTypeId = null;
        $firstScheduleId = null;

        foreach ($order->lines()->with('ticketType')->get() as $line) {
            $type = $line->ticketType;
            $firstTypeId ??= $line->ticket_type_id;
            $firstScheduleId ??= $type?->schedule_id;
            for ($i = 0; $i < $line->quantity; $i++) {
                $seat = null;
                if ($type?->seat_selection) {
                    $key = $type->schedule_id ?? array_key_first($seatQueues);
                    $seat = $key !== null && ! empty($seatQueues[$key]) ? array_shift($seatQueues[$key]) : null;
                }
                Ticket::query()->create([
                    'code' => Codes::ticketCode(),
                    'order_id' => $order->id,
                    'event_id' => $order->event_id,
                    'schedule_id' => $type?->schedule_id ?? $seat?->schedule_id,
                    'ticket_type_id' => $line->ticket_type_id,
                    'holder_user_id' => $order->user_id,
                    'holder_name' => $holderName ?: 'Guest',
                    'holder_email' => $attendee['email'] ?? null,
                    'status' => 'valid',
                    'seat_reservation_id' => $seat?->id,
                ]);
            }
        }

        foreach ($order->additional_guests ?? [] as $guest) {
            Ticket::query()->create([
                'code' => Codes::ticketCode(),
                'order_id' => $order->id,
                'event_id' => $order->event_id,
                'schedule_id' => $firstScheduleId,
                'ticket_type_id' => $firstTypeId,
                'holder_user_id' => $order->user_id,
                'holder_name' => trim(($guest['firstName'] ?? '').' '.($guest['lastName'] ?? '')) ?: 'Guest',
                'holder_email' => $guest['email'] ?? null,
                'is_additional_guest' => true,
                'status' => 'valid',
            ]);
        }
    }

    private function postLedger(Order $order, Payment $payment): void
    {
        if ($order->total <= 0) {
            return;
        }
        $organizerId = Event::withTrashed()->whereKey($order->event_id)->value('organizer_user_id');
        $organizerShare = max(0, $order->subtotal - $order->discount);
        $source = $payment->provider === 'wallet'
            ? 'wallet:'.DB::table('wallets')->where('user_id', $order->user_id)->value('id')
            : 'provider:'.$payment->provider;

        // Wallet orders debit the wallet inside the order transaction (OrderService);
        // here we only record provider-paid revenue.
        if ($payment->provider === 'wallet') {
            return;
        }

        $this->ledger->post([
            LedgerService::debit($source, $order->total),
            LedgerService::credit('organizer_payable:'.$organizerId, $organizerShare),
            LedgerService::credit('platform:fees', $order->total - $organizerShare),
        ], $order->currency, 'order', $order->id, 'Ticket sale '.$order->reference);
    }

    /** Promoter commission = % × ticket subtotal (excl. fee and food), pending until settlement. */
    private function attributePromoter(Order $order): void
    {
        if (! $order->promoter_enrollment_id || $order->ticket_subtotal <= 0) {
            return;
        }
        $percent = (float) DB::table('event_promotions')->where('event_id', $order->event_id)->where('enabled', true)->value('commission_percent');
        if ($percent <= 0) {
            return;
        }
        $base = max(0, $order->ticket_subtotal - min($order->discount, $order->ticket_subtotal));
        PromoterCommission::query()->firstOrCreate(['order_id' => $order->id], [
            'enrollment_id' => $order->promoter_enrollment_id,
            'amount' => Money::percentOf($base, $percent),
            'currency' => $order->currency,
            'status' => 'pending',
        ]);
    }

    private function afterConfirmed(Order $order): void
    {
        $order->loadMissing(['event', 'user']);
        $event = $order->event;
        $user = $order->user;

        $community = Community::query()->where('event_id', $event->id)->first();
        if ($community && ! $community->permission('requireApprovalToJoin')) {
            $this->communities->addMember($community, $order->user_id);
        }

        $count = Ticket::query()->where('order_id', $order->id)->count();
        if ($user) {
            $this->notifications->notify($user, 'ticket_purchased', [
                'tokens' => ['event' => $event->title],
                'data' => ['orderId' => $order->id, 'eventId' => $event->id],
                'link' => Platform::webUrl('/tickets'),
                'dedupe' => 'order-confirmed:'.$order->id,
                'email' => [
                    'eventTitle' => $event->title,
                    'eventDate' => $event->starts_at?->setTimezone(Platform::timezone())->format('D j M Y, g:i A') ?? '',
                    'orderReference' => $order->reference,
                    'total' => Money::format($order->total, $order->currency),
                    'ticketCount' => $count,
                    'ticketsUrl' => Platform::webUrl('/tickets'),
                ],
            ]);
        }
        Realtime::toUser($order->user_id, 'order.updated', ['id' => $order->id, 'status' => 'paid', 'reference' => $order->reference]);
    }

    public static function commissionSnapshot(Event $event): array
    {
        return app(CommissionResolver::class)->forEvent($event) + ['capturedAt' => Fmt::date(now())];
    }
}
