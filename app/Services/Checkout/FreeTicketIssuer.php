<?php

namespace App\Services\Checkout;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Event;
use App\Models\EventRequest;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Payment;
use App\Models\TicketType;
use App\Models\User;
use App\Support\Codes;
use Illuminate\Support\Facades\DB;

/**
 * Approving an attendance request for a free event issues a ticket directly
 * (business-rules.md proposed default): a zero-total order on the first active
 * free ticket type, confirmed through the normal fulfilment path.
 */
class FreeTicketIssuer
{
    public function __construct(private readonly FulfillmentService $fulfillment) {}

    public function issueForApprovedRequest(Event $event, EventRequest $request): ?Order
    {
        $type = TicketType::query()->where('event_id', $event->id)->where('is_active', true)->where('price', 0)->orderBy('sort_order')->first();
        $user = User::query()->find($request->user_id);
        if ($type === null || $user === null) {
            return null;
        }
        $alreadyHas = DB::table('tickets')->where('event_id', $event->id)->where('holder_user_id', $user->id)->whereIn('status', ['valid', 'checked_in'])->exists();
        if ($alreadyHas) {
            return null;
        }

        $order = Order::query()->create([
            'reference' => Codes::reference('WP', 'orders'),
            'user_id' => $user->id,
            'event_id' => $event->id,
            'status' => OrderStatus::Pending,
            'payment_method' => 'free',
            'provider' => 'none',
            'attendee' => ['firstName' => $request->first_name, 'lastName' => $request->last_name, 'email' => $user->email],
            'additional_guests' => [],
            'subtotal' => 0, 'ticket_subtotal' => 0, 'service_fee' => 0, 'discount' => 0, 'total' => 0,
            'currency' => $event->currency,
            'source' => 'web',
        ]);
        OrderLine::query()->create([
            'order_id' => $order->id, 'ticket_type_id' => $type->id, 'name_snapshot' => $type->name,
            'unit_price' => 0, 'quantity' => 1, 'subtotal' => 0,
        ]);
        $payment = Payment::query()->create([
            'payable_type' => 'order', 'payable_id' => $order->id, 'user_id' => $user->id,
            'provider' => 'internal', 'provider_reference' => $order->reference, 'purpose' => 'ticket_sale',
            'amount' => 0, 'currency' => $event->currency, 'status' => PaymentStatus::Pending,
        ]);

        return $this->fulfillment->confirm($order, $payment);
    }
}
