<?php

use App\Models\Event;
use App\Models\EventManager;
use App\Models\Order;
use App\Models\Refund;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Checkout\RefundService;
use App\Services\Checkout\TicketQr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** Buy tickets for $buyer through the real quote/order/verify flow. */
function buyTickets($test, Event $event, User $buyer, int $qty = 1): Order
{
    $test->configurePaystack();
    $type = TicketType::query()->where('event_id', $event->id)->first();
    $test->asCustomer($buyer);
    $quote = $test->postJson('/api/v1/checkout/quotes', ['eventId' => $event->id, 'lines' => [['ticketTypeId' => $type->id, 'quantity' => $qty]]])->json('data');
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/x']]),
        'api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'success', 'amount' => $quote['total'], 'currency' => 'NGN', 'id' => 55]]),
        'api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['id' => 'rf_991', 'status' => 'pending']]),
    ]);
    $orderId = $test->withHeader('Idempotency-Key', uniqid('k', true))->postJson('/api/v1/orders', [
        'quoteId' => $quote['quoteId'],
        'attendee' => ['firstName' => 'Ada', 'lastName' => 'Obi', 'email' => 'ada@example.com'],
        'paymentMethod' => 'paystack',
        'returnUrl' => 'https://wedpluz.com/checkout/return',
    ])->json('data.order.id');
    $test->postJson("/api/v1/orders/$orderId/verify-payment")->assertJsonPath('data.status', 'paid');

    return Order::query()->findOrFail($orderId);
}

it('checks a ticket in exactly once and reports reasons in priority order', function () {
    $organizer = $this->customer(['identity_status' => 'verified']);
    $event = $this->liveEvent($organizer);
    $other = $this->liveEvent($organizer);
    $ticket = Ticket::query()->where('order_id', buyTickets($this, $event, $this->customer())->id)->firstOrFail();

    $this->asCustomer($organizer);
    $qr = TicketQr::payload($ticket);

    $this->postJson("/api/v1/organizer/events/{$event->id}/check-ins", ['code' => 'WP1.tik_01JZZZZZZZZZZZZZZZZZZZZZZZ.*.forgedsignature000', 'method' => 'scan'])
        ->assertOk()->assertJsonPath('data.reason', 'not_found');
    $this->postJson("/api/v1/organizer/events/{$other->id}/check-ins", ['code' => $qr, 'method' => 'scan'])
        ->assertOk()->assertJsonPath('data.reason', 'wrong_event')->assertJsonPath('data.ticket', null);
    $this->postJson("/api/v1/organizer/events/{$event->id}/check-ins", ['code' => $qr, 'method' => 'scan'])
        ->assertOk()->assertJsonPath('data.valid', true)->assertJsonPath('data.reason', 'ok');
    $this->postJson("/api/v1/organizer/events/{$event->id}/check-ins", ['code' => strtolower($ticket->code), 'method' => 'manual'])
        ->assertOk()->assertJsonPath('data.valid', false)->assertJsonPath('data.reason', 'already_checked_in');

    expect(DB::table('check_ins')->where('event_id', $event->id)->count())->toBe(3)
        ->and($ticket->refresh()->status->value)->toBe('checked_in');
});

it('only lets the organiser or a manager with scan_tickets validate tickets', function () {
    $organizer = $this->customer(['identity_status' => 'verified']);
    $event = $this->liveEvent($organizer);
    $manager = $this->customer();
    EventManager::query()->create(['event_id' => $event->id, 'user_id' => $manager->id, 'access' => 'partial', 'permissions' => ['manage_tasks'], 'status' => 'accepted', 'invited_at' => now()]);

    $this->asCustomer($manager);
    $this->postJson("/api/v1/organizer/events/{$event->id}/check-ins", ['code' => 'ABCD-EFG-HJ', 'method' => 'manual'])->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');

    $this->asCustomer();
    $this->postJson("/api/v1/organizer/events/{$event->id}/check-ins", ['code' => 'ABCD-EFG-HJ', 'method' => 'manual'])->assertStatus(404);
});

it('refunds the ticket subtotal (not the fee) when the buyer cancels outside the window', function () {
    $event = $this->liveEvent(attributes: ['starts_at' => now()->addDays(10)]);
    $buyer = $this->customer();
    $order = buyTickets($this, $event, $buyer, 2);
    $ticket = Ticket::query()->where('order_id', $order->id)->first();

    $this->asCustomer($buyer);
    $this->postJson("/api/v1/tickets/{$ticket->id}/cancel", ['reason' => 'scheduling_conflict'])
        ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.refund.amount', 1000000);

    $refund = Refund::query()->firstOrFail();
    expect($refund->status->value)->toBe('processing')
        ->and($refund->fee_portion)->toBe(0)
        ->and(TicketType::query()->where('event_id', $event->id)->value('sold'))->toBe(0)
        ->and($order->refresh()->status->value)->toBe('refund_processing');

    // Provider confirms: refund completes and the order is refunded/cancelled.
    app(RefundService::class)->providerResult('paystack', 'rf_991', true);
    expect($refund->refresh()->status->value)->toBe('completed');

    $this->postJson("/api/v1/tickets/{$ticket->id}/cancel", ['reason' => 'personal'])->assertStatus(409);
});

it('cancels without refund inside the refund window', function () {
    $event = $this->liveEvent(attributes: ['starts_at' => now()->addHours(12)]);
    $buyer = $this->customer();
    $order = buyTickets($this, $event, $buyer);
    $ticket = Ticket::query()->where('order_id', $order->id)->first();

    $this->asCustomer($buyer);
    $this->postJson("/api/v1/tickets/{$ticket->id}/cancel", ['reason' => 'personal'])->assertOk()->assertJsonPath('data.refund', null);

    expect($order->refresh()->status->value)->toBe('cancelled')->and(Refund::query()->count())->toBe(0);
});

it('refuses to cancel a checked-in ticket', function () {
    $event = $this->liveEvent();
    $buyer = $this->customer();
    $ticket = Ticket::query()->where('order_id', buyTickets($this, $event, $buyer)->id)->first();
    $ticket->forceFill(['status' => 'checked_in', 'checked_in_at' => now()])->save();

    $this->asCustomer($buyer);
    $this->postJson("/api/v1/tickets/{$ticket->id}/cancel", ['reason' => 'personal'])
        ->assertStatus(409)->assertJsonPath('message', 'Checked-in tickets cannot be cancelled.');
});
