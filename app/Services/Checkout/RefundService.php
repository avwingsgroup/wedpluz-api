<?php

namespace App\Services\Checkout;

use App\Enums\OrderStatus;
use App\Enums\RefundStatus;
use App\Enums\TicketStatus;
use App\Exceptions\ApiException;
use App\Http\Resources\Fmt;
use App\Integrations\Payments\PaymentManager;
use App\Integrations\Support\IntegrationException;
use App\Models\AdminUser;
use App\Models\Community;
use App\Models\Event;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Communities\CommunityService;
use App\Services\Finance\LedgerService;
use App\Services\Notifications\NotificationService;
use App\Services\Platform\AlertService;
use App\Services\Platform\SettingsService;
use App\Services\Realtime\Realtime;
use App\Services\Wallet\WalletService;
use App\Support\Codes;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Refunds and cancellations (business-rules.md, "Refunds and cancellations").
 *
 * - Buyer cancellation cancels the whole order. More than refundWindowHours
 *   before the start: the ticket subtotal and food are refunded, the service
 *   fee is not. Inside the window: cancellation without refund unless the
 *   organiser made the event always refundable. Free orders: no refund.
 * - Organiser/admin-cancelled events: full refund including the service fee.
 * - Admin refunds may be partial; cumulative refunds never exceed the captured amount.
 * - Refunds go back through the original provider; wallet payments refund to the wallet.
 * The frontend displays `Ticket.refund` exactly as returned.
 */
class RefundService
{
    public function __construct(
        private readonly PaymentManager $payments,
        private readonly LedgerService $ledger,
        private readonly Inventory $inventory,
        private readonly SettingsService $settings,
        private readonly NotificationService $notifications,
        private readonly AlertService $alerts,
        private readonly CommunityService $communities,
    ) {}

    /** POST /tickets/{id}/cancel */
    public function cancelByBuyer(Ticket $ticket, User $user, string $reason, ?string $note): Ticket
    {
        $order = Order::query()->with('event')->findOrFail($ticket->order_id);
        if ($order->user_id !== $user->id) {
            throw ApiException::forbidden('Only the buyer can cancel this booking.');
        }

        $refund = DB::transaction(function () use ($order, $reason, $note) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();
            if ($locked->status !== OrderStatus::Paid) {
                throw ApiException::conflict('This booking cannot be cancelled.');
            }
            if (Ticket::query()->where('order_id', $locked->id)->where('status', TicketStatus::CheckedIn->value)->exists()) {
                throw ApiException::conflict('Checked-in tickets cannot be cancelled.');
            }
            $event = $order->event;
            if ($event->starts_at && $event->starts_at->isPast()) {
                throw ApiException::conflict('This event has already started.');
            }

            $amount = $this->buyerRefundableAmount($locked, $event);
            $locked->forceFill(['cancellation_reason' => $reason, 'cancellation_note' => $note, 'cancelled_at' => now()])->save();
            $this->voidOrder($locked);

            if ($amount <= 0) {
                $locked->forceFill(['status' => OrderStatus::Cancelled])->save();

                return null;
            }

            $needsApproval = $amount >= (int) config('wedpluz.refunds.approval_threshold', 50000000);

            return $this->createRefund($locked, $amount, 0, 'Buyer cancellation: '.str_replace('_', ' ', $reason), $needsApproval ? RefundStatus::Requested : RefundStatus::Approved, requestedByUser: $locked->user_id, ticketId: null);
        });

        if ($refund && $refund->status === RefundStatus::Approved) {
            $this->execute($refund);
        }
        $this->afterCancellation($order->refresh(), $refund);

        return $ticket->refresh();
    }

    /** Full refund including service fee for organiser/admin-cancelled events. */
    public function refundForEventCancellation(Order $order, string $reason): ?Refund
    {
        $refund = DB::transaction(function () use ($order, $reason) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();
            if ($locked->status !== OrderStatus::Paid) {
                return null;
            }
            $locked->forceFill(['cancelled_at' => now(), 'cancellation_reason' => 'event_cancelled'])->save();
            $this->voidOrder($locked);
            $remaining = $this->capturedRemaining($locked);
            if ($remaining <= 0) {
                $locked->forceFill(['status' => OrderStatus::Cancelled])->save();

                return null;
            }

            return $this->createRefund($locked, $remaining, min($remaining, $locked->service_fee), $reason, RefundStatus::Approved);
        });

        if ($refund) {
            $this->execute($refund);
        }

        return $refund;
    }

    /** Payment captured but stock gone: refund in full. */
    public function refundOversold(Order $order): void
    {
        $refund = DB::transaction(function () use ($order) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();
            $remaining = $this->capturedRemaining($locked);

            return $remaining > 0 ? $this->createRefund($locked, $remaining, min($remaining, $locked->service_fee), 'Tickets sold out before payment completed', RefundStatus::Approved) : null;
        });
        if ($refund) {
            $this->execute($refund);
        }
    }

    /** POST /admin/bookings/{id}/refund — full or partial. */
    public function refundByAdmin(Order $order, int $amount, string $reason, AdminUser $admin): Refund
    {
        $refund = DB::transaction(function () use ($order, $amount, $reason, $admin) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();
            if (! in_array($locked->status, [OrderStatus::Paid, OrderStatus::RefundProcessing, OrderStatus::Cancelled], true)) {
                throw ApiException::conflict('Only paid orders can be refunded.');
            }
            $remaining = $this->capturedRemaining($locked);
            if ($amount > $remaining) {
                throw ApiException::validation('The refund exceeds the refundable amount.', ['amount' => ['At most '.Money::format($remaining, $locked->currency).' can be refunded.']]);
            }
            $organizerRemaining = max(0, ($locked->subtotal - $locked->discount) - (int) Refund::query()->where('order_id', $locked->id)->whereNot('status', RefundStatus::Rejected->value)->sum(DB::raw('amount - fee_portion')));
            $feePortion = max(0, $amount - $organizerRemaining);

            $refund = $this->createRefund($locked, $amount, $feePortion, $reason, RefundStatus::Approved, requestedByAdmin: $admin->id);
            $refund->forceFill(['decided_by_admin_id' => $admin->id, 'decided_at' => now()])->save();

            if ($amount === $remaining && $locked->status === OrderStatus::Paid) {
                $this->voidOrder($locked);
            }

            return $refund;
        });

        $this->execute($refund);

        return $refund->refresh();
    }

    public function approve(Refund $refund, AdminUser $admin): Refund
    {
        DB::transaction(function () use ($refund, $admin) {
            $locked = Refund::query()->whereKey($refund->id)->lockForUpdate()->first();
            if ($locked->status !== RefundStatus::Requested) {
                throw ApiException::conflict('Only requested refunds can be approved.');
            }
            $locked->forceFill(['status' => RefundStatus::Approved, 'decided_by_admin_id' => $admin->id, 'decided_at' => now()])->save();
        });
        $this->execute($refund->refresh());

        return $refund->refresh();
    }

    public function reject(Refund $refund, AdminUser $admin): Refund
    {
        DB::transaction(function () use ($refund, $admin) {
            $locked = Refund::query()->whereKey($refund->id)->lockForUpdate()->first();
            if ($locked->status !== RefundStatus::Requested) {
                throw ApiException::conflict('Only requested refunds can be rejected.');
            }
            $locked->forceFill(['status' => RefundStatus::Rejected, 'decided_by_admin_id' => $admin->id, 'decided_at' => now()])->save();
            $this->unreserve($locked);
            Order::query()->whereKey($locked->order_id)->where('status', OrderStatus::RefundProcessing->value)->update(['status' => OrderStatus::Cancelled->value]);
        });
        $this->notifyRefund($refund->refresh(), 'rejected');

        return $refund;
    }

    /** Send an approved refund to the original provider (or credit the wallet). */
    public function execute(Refund $refund): void
    {
        $refund = Refund::query()->findOrFail($refund->id);
        if ($refund->status !== RefundStatus::Approved) {
            return;
        }
        $payment = Payment::query()->find($refund->payment_id);
        if ($payment === null) {
            $this->flag($refund, 'Original payment not found.');

            return;
        }

        if ($payment->provider === 'wallet') {
            DB::transaction(function () use ($refund, $payment) {
                $wallet = Wallet::query()->where('user_id', $payment->user_id)->lockForUpdate()->firstOrFail();
                $order = Order::query()->findOrFail($refund->order_id);
                $organizerId = Event::withTrashed()->whereKey($order->event_id)->value('organizer_user_id');
                $this->ledger->post([
                    LedgerService::debit('organizer_payable:'.$organizerId, $refund->amount - $refund->fee_portion),
                    LedgerService::debit('platform:fees', $refund->fee_portion),
                    LedgerService::credit($wallet->availableAccount(), $refund->amount),
                ], $refund->currency, 'refund', $refund->id, 'Refund '.$refund->reference);
                app(WalletService::class)->record($wallet, 'refund', 'credit', $refund->amount, 'successful', 'Refund for '.$order->reference, ['related_type' => 'refund', 'related_id' => $refund->id, 'provider' => 'wallet']);
                $refund->forceFill(['status' => RefundStatus::Completed, 'completed_at' => now(), 'provider_reference' => $refund->reference])->save();
                $this->settleOrderStatus($order);
            });
            $this->notifyRefund($refund->refresh(), 'completed');

            return;
        }

        $refund->forceFill(['status' => RefundStatus::Processing])->save();
        try {
            $result = $this->payments->gateway($payment->provider)->refund($payment, $refund->amount, $refund->reference, $refund->reason);
        } catch (IntegrationException $e) {
            $this->flag($refund, $e->getMessage());

            return;
        }

        if ($result->status === 'failed') {
            $this->flag($refund, (string) $result->failureReason);

            return;
        }
        $refund->forceFill(['provider_reference' => $result->providerReference])->save();
        if ($result->status === 'completed') {
            $this->complete($refund);
        } else {
            $this->notifyRefund($refund->refresh(), 'processing');
        }
    }

    /** Provider webhook result for a refund. Idempotent. */
    public function providerResult(string $provider, string $reference, bool $success, array $data = []): void
    {
        $refund = Refund::query()->where('provider', $provider)
            ->where(fn ($q) => $q->where('provider_reference', $reference)->orWhere('reference', $reference))->first();
        if ($refund === null) {
            Log::notice('refund.webhook_unmatched', ['provider' => $provider, 'reference' => $reference]);

            return;
        }
        if ($success) {
            $this->complete($refund);
        } else {
            $this->flag($refund, (string) ($data['failure_reason'] ?? 'Provider reported the refund failed.'));
        }
    }

    private function complete(Refund $refund): void
    {
        $done = DB::transaction(function () use ($refund) {
            $locked = Refund::query()->whereKey($refund->id)->lockForUpdate()->first();
            if ($locked->status === RefundStatus::Completed) {
                return false;
            }
            $order = Order::query()->findOrFail($locked->order_id);
            $organizerId = Event::withTrashed()->whereKey($order->event_id)->value('organizer_user_id');
            $this->ledger->post([
                LedgerService::debit('organizer_payable:'.$organizerId, $locked->amount - $locked->fee_portion),
                LedgerService::debit('platform:fees', $locked->fee_portion),
                LedgerService::credit('provider:'.$locked->provider, $locked->amount),
            ], $locked->currency, 'refund', $locked->id, 'Refund '.$locked->reference);
            $locked->forceFill(['status' => RefundStatus::Completed, 'completed_at' => now(), 'needs_attention' => false, 'failure_reason' => null])->save();
            $this->settleOrderStatus($order);

            return true;
        });
        if ($done) {
            $this->notifyRefund($refund->refresh(), 'completed');
        }
    }

    private function flag(Refund $refund, string $reason): void
    {
        $refund->forceFill(['status' => RefundStatus::Processing, 'needs_attention' => true, 'failure_reason' => mb_substr($reason, 0, 500)])->save();
        $this->alerts->raise('warning', 'Refund needs attention', 'Refund '.$refund->reference.' could not be completed: '.$reason, '/finance/refunds?search='.$refund->reference, 'refund-failed:'.$refund->id);
    }

    private function createRefund(Order $order, int $amount, int $feePortion, string $reason, RefundStatus $status, ?string $requestedByUser = null, ?string $requestedByAdmin = null, ?string $ticketId = null): Refund
    {
        $payment = Payment::query()->where('payable_type', 'order')->where('payable_id', $order->id)->where('status', 'succeeded')->lockForUpdate()->latest('created_at')->first();
        if ($payment === null) {
            throw ApiException::conflict('No captured payment to refund.');
        }
        if ($payment->refunded_amount + $amount > $payment->amount) {
            throw ApiException::conflict('Refunds cannot exceed the captured amount.');
        }

        $refund = Refund::query()->create([
            'reference' => Codes::reference('RF', 'refunds'),
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'amount' => $amount,
            'fee_portion' => min($feePortion, $amount),
            'currency' => $order->currency,
            'reason' => mb_substr($reason, 0, 1000),
            'status' => $status,
            'provider' => $payment->provider,
            'requested_by_user_id' => $requestedByUser,
            'requested_by_admin_id' => $requestedByAdmin,
            'ticket_id' => $ticketId,
        ]);
        // Reserve the amount immediately so concurrent refunds cannot exceed the capture.
        $payment->increment('refunded_amount', $amount);
        $order->increment('refunded_amount', $amount);
        if ($order->status === OrderStatus::Paid) {
            $order->forceFill(['status' => OrderStatus::RefundProcessing])->save();
        }

        return $refund;
    }

    private function unreserve(Refund $refund): void
    {
        Payment::query()->whereKey($refund->payment_id)->decrement('refunded_amount', $refund->amount);
        Order::query()->whereKey($refund->order_id)->decrement('refunded_amount', $refund->amount);
    }

    /** Cancel tickets, release stock and seats, reverse unsettled promoter commission. */
    private function voidOrder(Order $order): void
    {
        $lines = $order->lines()->get();
        foreach ($lines as $line) {
            $this->inventory->decrementSold($line->ticket_type_id, $line->quantity);
        }
        DB::table('events')->where('id', $order->event_id)->update(['tickets_sold' => DB::raw('greatest(0, tickets_sold - '.(int) $lines->sum('quantity').')')]);
        Ticket::query()->where('order_id', $order->id)->whereIn('status', [TicketStatus::Valid->value])->update(['status' => TicketStatus::Cancelled->value, 'updated_at' => now()]);
        DB::table('seat_reservations')->where('order_id', $order->id)->whereIn('status', ['held', 'confirmed'])->update(['status' => 'released', 'updated_at' => now()]);
        DB::table('promoter_commissions')->where('order_id', $order->id)->where('status', 'pending')->update(['status' => 'reversed', 'updated_at' => now()]);
    }

    private function settleOrderStatus(Order $order): void
    {
        $order->refresh();
        $openRefunds = Refund::query()->where('order_id', $order->id)->whereIn('status', ['requested', 'approved', 'processing'])->exists();
        if (! $openRefunds && $order->status === OrderStatus::RefundProcessing) {
            $order->forceFill(['status' => $order->refunded_amount >= $order->total ? OrderStatus::Refunded : OrderStatus::Cancelled])->save();
        }
    }

    private function buyerRefundableAmount(Order $order, Event $event): int
    {
        if ($order->total <= 0) {
            return 0;
        }
        $windowHours = (int) $this->settings->get('payments.refundWindowHours', 48);
        $outsideWindow = $event->starts_at === null || $event->starts_at->gt(now()->addHours($windowHours));
        if (! $outsideWindow && ! $event->always_refundable) {
            return 0;
        }

        return max(0, min($order->subtotal - $order->discount, $this->capturedRemaining($order)));
    }

    private function capturedRemaining(Order $order): int
    {
        $payment = Payment::query()->where('payable_type', 'order')->where('payable_id', $order->id)->where('status', 'succeeded')->latest('created_at')->first();

        return $payment ? $payment->refundableAmount() : 0;
    }

    private function afterCancellation(Order $order, ?Refund $refund): void
    {
        $order->loadMissing(['event', 'user']);
        $community = Community::query()->where('event_id', $order->event_id)->first();
        if ($community && ! Ticket::query()->where('event_id', $order->event_id)->where('holder_user_id', $order->user_id)->whereIn('status', ['valid', 'checked_in'])->exists()) {
            $member = $this->communities->membership($community, $order->user);
            if ($member && $member->role->value === 'member') {
                $this->communities->removeMember($community, $order->user_id);
            }
        }

        if ($order->user) {
            $refundText = match (true) {
                $refund === null => 'This booking was not eligible for a refund.',
                $refund->status === RefundStatus::Requested => 'Your refund of '.Money::format($refund->amount, $refund->currency).' is awaiting approval.',
                default => 'A refund of '.Money::format($refund->amount, $refund->currency).' is on its way.',
            };
            $this->notifications->notify($order->user, 'ticket_cancelled', [
                'tokens' => ['event' => $order->event->title],
                'data' => ['orderId' => $order->id],
                'email' => ['eventTitle' => $order->event->title, 'refundText' => $refundText],
            ]);
        }
        foreach (Ticket::query()->where('order_id', $order->id)->get() as $ticket) {
            Realtime::toUser($order->user_id, 'ticket.updated', ['id' => $ticket->id, 'status' => $ticket->status->value]);
        }
    }

    private function notifyRefund(Refund $refund, string $status): void
    {
        $order = Order::query()->with(['user', 'event'])->find($refund->order_id);
        if ($order?->user === null) {
            return;
        }
        $this->notifications->notify($order->user, 'refund_updated', [
            'tokens' => ['event' => $order->event->title, 'status' => $status],
            'data' => ['refundId' => $refund->id, 'orderId' => $order->id],
            'dedupe' => 'refund:'.$refund->id.':'.$status,
            'email' => ['eventTitle' => $order->event->title, 'amount' => Money::format($refund->amount, $refund->currency), 'status' => $status],
        ]);
        Realtime::toUser($order->user_id, 'refund.updated', self::summary($refund));
    }

    public static function summary(?Refund $r): ?array
    {
        return $r === null ? null : [
            'id' => $r->id,
            'status' => $r->status->value,
            'amount' => $r->amount,
            'currency' => $r->currency,
            'reason' => $r->reason,
            'requestedAt' => Fmt::date($r->created_at),
            'completedAt' => Fmt::date($r->completed_at),
        ];
    }
}
