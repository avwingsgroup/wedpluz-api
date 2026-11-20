<?php

namespace App\Services\Checkout;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Http\Middleware\RequestContext;
use App\Integrations\Payments\PaymentManager;
use App\Integrations\Payments\PaymentRequest;
use App\Integrations\Support\IntegrationException;
use App\Models\CheckoutQuote;
use App\Models\Event;
use App\Models\EventSchedule;
use App\Models\Order;
use App\Models\OrderFoodLine;
use App\Models\OrderLine;
use App\Models\Payment;
use App\Models\SeatReservation;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Finance\LedgerService;
use App\Services\Wallet\WalletService;
use App\Support\Codes;
use App\Support\DemoAccounts;
use App\Support\Sql;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * POST /orders: converts a valid, unexpired quote owned by the caller into an
 * order and a payment intent (idempotent via the Idempotency-Key middleware).
 * Seats are reserved atomically (partial unique index) or the call fails
 * with 409 SEAT_TAKEN. Free orders complete immediately; wallet orders debit
 * the ledger inside the order transaction; provider orders return a hosted
 * checkout URL and are confirmed only after server-side verification.
 */
class OrderService
{
    public function __construct(
        private readonly PaymentManager $payments,
        private readonly FulfillmentService $fulfillment,
        private readonly LedgerService $ledger,
        private readonly WalletService $wallets,
    ) {}

    /**
     * @param  array{quoteId:string, attendee:array, additionalGuests?:array, seats?:array, paymentMethod:string, returnUrl:string}  $data
     * @return array{order:Order, payment:Payment}
     */
    public function create(User $user, array $data, string $idempotencyKey): array
    {
        $quote = CheckoutQuote::query()->with('event')->find($data['quoteId']);
        if ($quote === null || $quote->user_id !== $user->id) {
            throw ApiException::validation('Invalid checkout session.', ['quoteId' => ['The selected quote is invalid.']]);
        }
        if ($quote->consumed_at !== null) {
            throw ApiException::conflict('This checkout was already completed. Start a new checkout.');
        }
        if ($quote->released_at !== null || $quote->expires_at->isPast()) {
            throw ApiException::conflict('Your reserved tickets have expired. Please review your order.');
        }

        $event = $quote->event;
        $guests = array_values($data['additionalGuests'] ?? []);
        $this->validateGuests($event, $quote, $guests);
        $method = $quote->total === 0 ? 'free' : $data['paymentMethod'];
        if ($method === 'free' && $quote->total > 0) {
            throw ApiException::validation('Choose a payment method.', ['paymentMethod' => ['Choose a payment method.']]);
        }
        $provider = match ($method) {
            'free' => 'none',
            'wallet' => 'wallet',
            default => $this->payments->resolveProvider($method, $quote->currency),
        };

        [$order, $payment] = DB::transaction(function () use ($user, $data, $quote, $event, $guests, $method, $provider, $idempotencyKey) {
            $lockedQuote = CheckoutQuote::query()->whereKey($quote->id)->lockForUpdate()->first();
            if ($lockedQuote->consumed_at !== null) {
                throw ApiException::conflict('This checkout was already completed. Start a new checkout.');
            }

            $order = Order::query()->create([
                'reference' => Codes::reference('WP', 'orders'),
                'user_id' => $user->id,
                'event_id' => $event->id,
                'quote_id' => $quote->id,
                'status' => OrderStatus::AwaitingPayment,
                'payment_method' => $method,
                'provider' => $provider,
                'attendee' => [
                    'firstName' => $data['attendee']['firstName'],
                    'lastName' => $data['attendee']['lastName'],
                    'email' => mb_strtolower($data['attendee']['email']),
                    'phone' => $data['attendee']['phone'] ?? null,
                ],
                'additional_guests' => array_map(fn ($g) => array_filter([
                    'firstName' => $g['firstName'], 'lastName' => $g['lastName'], 'email' => isset($g['email']) ? mb_strtolower($g['email']) : null,
                ], fn ($v) => $v !== null), $guests),
                'subtotal' => $quote->subtotal,
                'ticket_subtotal' => $quote->ticket_subtotal,
                'service_fee' => $quote->service_fee,
                'discount' => $quote->discount,
                'total' => $quote->total,
                'currency' => $quote->currency,
                'promo_code_id' => $quote->promo_code_id,
                'commission_rule_snapshot' => FulfillmentService::commissionSnapshot($event),
                'promoter_enrollment_id' => $quote->referral_enrollment_id,
                'idempotency_key' => $idempotencyKey,
                'return_url' => $data['returnUrl'],
                'source' => RequestContext::source(),
                'expires_at' => $quote->expires_at,
            ]);

            foreach ($quote->lines as $line) {
                OrderLine::query()->create([
                    'order_id' => $order->id,
                    'ticket_type_id' => $line['ticketType']['id'],
                    'name_snapshot' => $line['ticketType']['name'],
                    'unit_price' => $line['ticketType']['price'],
                    'quantity' => $line['quantity'],
                    'subtotal' => $line['subtotal'],
                ]);
            }
            foreach ($quote->food_lines as $line) {
                OrderFoodLine::query()->create([
                    'order_id' => $order->id,
                    'menu_item_id' => $line['menuItem']['id'],
                    'name_snapshot' => $line['menuItem']['name'],
                    'unit_price' => $line['menuItem']['price'],
                    'quantity' => $line['quantity'],
                    'subtotal' => $line['subtotal'],
                ]);
            }

            $this->reserveSeats($order, $quote, $data['seats'] ?? []);

            // The quote's hold now belongs to the order until it is paid or expires.
            DB::table('inventory_holds')->where('quote_id', $quote->id)->whereNull('released_at')->update(['order_id' => $order->id]);
            $lockedQuote->forceFill(['consumed_at' => now()])->save();

            $payment = Payment::query()->create([
                'payable_type' => 'order',
                'payable_id' => $order->id,
                'user_id' => $user->id,
                'provider' => $provider === 'none' ? 'internal' : $provider,
                'provider_reference' => $order->reference,
                'purpose' => 'ticket_sale',
                'amount' => $order->total,
                'currency' => $order->currency,
                'status' => PaymentStatus::Initialized,
            ]);

            if ($method === 'wallet') {
                $this->payWithWallet($user, $order, $payment);
            }

            return [$order, $payment];
        });

        if ($method === 'free') {
            $this->fulfillment->confirm($order, $payment);
        } elseif ($method === 'wallet') {
            $this->fulfillment->confirm($order, $payment);
        } else {
            $this->initializeProvider($user, $order, $payment, $method, $event);
        }

        return ['order' => $order->refresh(), 'payment' => $payment->refresh()];
    }

    private function initializeProvider(User $user, Order $order, Payment $payment, string $method, Event $event): void
    {
        try {
            $result = $this->payments->gateway($payment->provider)->initialize(new PaymentRequest(
                reference: $order->reference,
                amount: $order->total,
                currency: $order->currency,
                email: $order->attendee['email'] ?? $user->email,
                customerName: trim(($order->attendee['firstName'] ?? '').' '.($order->attendee['lastName'] ?? '')) ?: $user->display_name,
                returnUrl: $this->returnUrl($order),
                description: 'Tickets for '.$event->title,
                metadata: ['orderId' => $order->id, 'eventId' => $event->id, 'purpose' => 'ticket_sale'],
                method: $method,
            ));
        } catch (IntegrationException $e) {
            Log::error('payment.initialize_failed', ['order' => $order->id, 'provider' => $payment->provider, 'error' => $e->getMessage()]);
            $this->fulfillment->markFailed($order, $payment, 'Could not start payment: '.$e->getMessage());
            throw ApiException::paymentFailed('We could not start your payment with '.ucfirst($payment->provider).'. Please try another method.');
        }

        $payment->forceFill([
            'status' => PaymentStatus::Pending,
            'provider_reference' => $result->providerReference,
            'provider_transaction_id' => $result->providerTransactionId,
            'authorization_url' => $result->authorizationUrl,
            'client_params' => $result->clientParams,
        ])->save();
    }

    private function payWithWallet(User $user, Order $order, Payment $payment): void
    {
        $wallet = Wallet::query()->where('user_id', $user->id)->lockForUpdate()->first();
        if ($wallet === null || ! $wallet->is_active) {
            throw ApiException::validation('Activate your wallet to pay with it.', ['paymentMethod' => ['Your wallet is not active.']]);
        }
        if ($wallet->currency !== $order->currency) {
            throw ApiException::validation('Your wallet currency does not match this event.', ['paymentMethod' => ['Wallet currency mismatch.']]);
        }
        if ($wallet->cached_balance < $order->total) {
            throw ApiException::insufficientFunds('Insufficient wallet balance. Top up or choose another payment method.');
        }

        $organizerId = $order->event()->value('organizer_user_id');
        if (DemoAccounts::blocksMoneyOut($user->email) && ! DemoAccounts::isDemoUserId($organizerId)) {
            // Demo wallet money would otherwise be settled and paid out to a real organiser.
            throw ApiException::forbidden('Demo wallets can only pay for demo events. Choose another payment method.');
        }
        $organizerShare = max(0, $order->subtotal - $order->discount);
        $this->ledger->post([
            LedgerService::debit($wallet->availableAccount(), $order->total),
            LedgerService::credit('organizer_payable:'.$organizerId, $organizerShare),
            LedgerService::credit('platform:fees', $order->total - $organizerShare),
        ], $order->currency, 'order', $order->id, 'Ticket purchase '.$order->reference);

        $this->wallets->record($wallet, 'ticket_payment', 'debit', $order->total, 'successful', 'Tickets for '.$order->event()->value('title'), [
            'related_type' => 'order', 'related_id' => $order->id, 'provider' => 'wallet', 'counterparty' => 'Wedpluz',
        ]);
        $payment->forceFill(['status' => PaymentStatus::Pending, 'provider_transaction_id' => $order->reference])->save();
    }

    /** @param list<array{scheduleId:string, seatId:string, tableLabel:string, seatLabel:string}> $seats */
    private function reserveSeats(Order $order, CheckoutQuote $quote, array $seats): void
    {
        $seatedCount = (int) collect($quote->lines)->where('seatSelection', true)->sum('quantity');
        if ($seatedCount === 0) {
            return;
        }
        if (count($seats) !== $seatedCount) {
            throw ApiException::validation('Choose a seat for each seated ticket.', ['seats' => ['Select exactly '.$seatedCount.' seat'.($seatedCount === 1 ? '' : 's').'.']]);
        }

        $seatedTypes = collect($quote->lines)->where('seatSelection', true)->pluck('ticketType.id')->all();
        $schedules = EventSchedule::query()->with('hallLayout')->where('event_id', $order->event_id)->get()->keyBy('id');
        $seen = [];
        foreach (array_values($seats) as $i => $seat) {
            $schedule = $schedules->get($seat['scheduleId']);
            $layoutSeat = $schedule?->hallLayout ? HallLayoutSeats::find($schedule->hallLayout, $seat['seatId']) : null;
            if ($layoutSeat === null) {
                throw ApiException::validation('Invalid seat.', ["seats.$i.seatId" => ['This seat does not exist.']]);
            }
            if (! empty($layoutSeat['ticketTypeId']) && ! in_array($layoutSeat['ticketTypeId'], $seatedTypes, true)) {
                throw ApiException::validation('This seat is for a different ticket type.', ["seats.$i.seatId" => ['This seat is reserved for another ticket type.']]);
            }
            if (($layoutSeat['status'] ?? 'available') === 'reserved') {
                throw ApiException::seatTaken('Seat '.$layoutSeat['label'].' is not available.');
            }
            $key = $seat['scheduleId'].'|'.$seat['seatId'];
            if (isset($seen[$key])) {
                throw ApiException::validation('Each seat can be chosen once.', ["seats.$i.seatId" => ['Duplicate seat.']]);
            }
            $seen[$key] = true;

            $created = Sql::insertOrNull(fn () => SeatReservation::query()->create([
                'order_id' => $order->id,
                'schedule_id' => $seat['scheduleId'],
                'seat_id' => $seat['seatId'],
                'table_label' => $layoutSeat['tableLabel'],
                'seat_label' => $layoutSeat['label'],
                'status' => 'held',
            ]));
            if ($created === null) {
                throw ApiException::seatTaken('Seat '.$layoutSeat['tableLabel'].'-'.$layoutSeat['label'].' was just taken. Please choose another.');
            }
        }
    }

    private function validateGuests(Event $event, CheckoutQuote $quote, array $guests): void
    {
        if ($guests === []) {
            return;
        }
        if (! $event->allow_guest_invites) {
            throw ApiException::validation('This event does not allow additional guests.', ['additionalGuests' => ['Additional guests are not allowed.']]);
        }
        if (count($guests) > $event->max_additional_guests) {
            throw ApiException::validation('Too many guests.', ['additionalGuests' => ['You can add up to '.$event->max_additional_guests.' guest'.($event->max_additional_guests === 1 ? '' : 's').'.']]);
        }
        if ($event->guest_limit !== null) {
            $admitted = app(Inventory::class)->admittedCount($event->id);
            if ($admitted + count($guests) > $event->guest_limit) {
                throw ApiException::ticketsUnavailable('This event has reached its guest limit.', ['additionalGuests' => ['Not enough places for your guests.']]);
            }
        }
    }

    private function returnUrl(Order $order): string
    {
        $base = $order->return_url ?: rtrim((string) config('wedpluz.web_origin'), '/').'/checkout/return';
        $sep = str_contains($base, '?') ? '&' : '?';

        return str_contains($base, 'order=') ? $base : $base.$sep.'order='.urlencode($order->id);
    }
}
