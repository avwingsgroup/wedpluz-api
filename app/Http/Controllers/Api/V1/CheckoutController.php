<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Checkout\CreateOrderRequest;
use App\Http\Requests\Checkout\QuoteRequest;
use App\Http\Requests\Checkout\VerifyPaymentRequest;
use App\Http\Resources\Fmt;
use App\Models\Event;
use App\Models\EventSchedule;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Checkout\CheckoutService;
use App\Services\Checkout\HallLayoutSeats;
use App\Services\Checkout\OrderService;
use App\Services\Checkout\TicketPresenter;
use App\Services\Events\EventAccess;
use App\Services\Events\EventPresenter;
use App\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;

/** Quotes, seat maps, orders, payment verification and receipts. */
class CheckoutController extends Controller
{
    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly EventPresenter $events,
        private readonly TicketPresenter $tickets,
    ) {}

    public function quote(QuoteRequest $request): JsonResponse
    {
        return $this->ok(CheckoutService::resource($this->checkout->quote($this->customer(), $request->validated())));
    }

    public function releaseQuote(string $quoteId): Response
    {
        $this->checkout->release($this->customer(), $quoteId);

        return $this->noContent();
    }

    public function seats(string $eventId, string $scheduleId, EventAccess $access): JsonResponse
    {
        $event = Event::query()->find($eventId);
        if ($event === null || ! $access->canView($this->customer(), $event)) {
            throw ApiException::notFound('Event not found.');
        }
        $schedule = EventSchedule::query()->with('hallLayout')->where('event_id', $event->id)->find($scheduleId);
        $layout = $schedule?->hallLayout ?? $event->hallLayout;
        if ($schedule === null || $layout === null) {
            throw ApiException::notFound('This date has no seat map.');
        }

        return $this->ok(HallLayoutSeats::forSchedule($layout, $schedule->id));
    }

    public function createOrder(CreateOrderRequest $request, OrderService $orders): JsonResponse
    {
        $result = $orders->create($this->customer(), $request->validated(), (string) $request->attributes->get('idempotency_key'));

        return $this->created([
            'order' => $this->order($result['order']),
            'payment' => self::paymentIntent($result['order'], $result['payment']),
        ]);
    }

    public function showOrder(string $id): JsonResponse
    {
        return $this->ok($this->order($this->findOrder($id)));
    }

    public function verifyPayment(VerifyPaymentRequest $request, string $id, PaymentService $payments): JsonResponse
    {
        $order = $payments->verifyOrder($this->findOrder($id), $request->input('simulate'));

        return $this->ok($this->order($order->refresh()));
    }

    public function receipt(string $id): JsonResponse
    {
        $order = $this->findOrder($id);
        if ($order->paid_at === null) {
            throw ApiException::conflict('A receipt is available once the order is paid.');
        }

        return $this->ok(['url' => URL::temporarySignedRoute('documents.receipt', now()->addMinutes(15), ['order' => $order->id])]);
    }

    private function findOrder(string $id): Order
    {
        $order = Order::query()->where('user_id', $this->customer()->id)->find($id);
        if ($order === null) {
            throw ApiException::notFound('Order not found.');
        }

        return $order;
    }

    public function order(Order $order): array
    {
        $order->loadMissing(['event', 'lines', 'foodLines', 'seats', 'tickets']);

        return [
            'id' => $order->id,
            'reference' => $order->reference,
            'event' => $this->events->summaryOne($order->event, $this->optionalCustomer()),
            'status' => $order->status->value,
            'paymentMethod' => $order->payment_method,
            'attendee' => $order->attendee,
            'additionalGuests' => array_values($order->additional_guests ?? []),
            'lines' => $order->lines->map(fn ($l) => [
                'ticketType' => ['id' => $l->ticket_type_id, 'name' => $l->name_snapshot, 'price' => $l->unit_price, 'currency' => $order->currency],
                'quantity' => $l->quantity,
                'subtotal' => $l->subtotal,
            ])->all(),
            'foodLines' => $order->foodLines->map(fn ($l) => [
                'menuItem' => ['id' => $l->menu_item_id, 'name' => $l->name_snapshot, 'price' => $l->unit_price],
                'quantity' => $l->quantity,
                'subtotal' => $l->subtotal,
            ])->all(),
            'seats' => $order->seats->whereIn('status', ['held', 'confirmed'])->map(fn ($s) => [
                'scheduleId' => $s->schedule_id, 'seatId' => $s->seat_id, 'tableLabel' => $s->table_label, 'seatLabel' => $s->seat_label,
            ])->values()->all(),
            'subtotal' => $order->subtotal,
            'serviceFee' => $order->service_fee,
            'discount' => $order->discount,
            'total' => $order->total,
            'currency' => $order->currency,
            'paidAt' => Fmt::date($order->paid_at),
            'createdAt' => Fmt::date($order->created_at),
            'tickets' => $this->tickets->many($order->tickets),
            'receiptUrl' => $order->paid_at ? URL::temporarySignedRoute('documents.receipt', now()->addMinutes(15), ['order' => $order->id]) : null,
        ];
    }

    public static function paymentIntent(Order $order, Payment $payment): array
    {
        $status = match ($payment->status->value) {
            'succeeded' => 'succeeded',
            'failed', 'mismatch' => 'failed',
            'pending' => $payment->authorization_url ? 'requires_action' : 'processing',
            default => 'processing',
        };

        return [
            'orderId' => $order->id,
            'reference' => $order->reference,
            'provider' => in_array($payment->provider, ['paystack', 'stripe', 'paypal', 'monnify', 'flutterwave', 'wallet'], true) ? $payment->provider : 'none',
            'status' => $status,
            'clientParams' => (object) array_map('strval', $payment->client_params ?? []),
            'authorizationUrl' => $payment->authorization_url,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
        ];
    }
}
