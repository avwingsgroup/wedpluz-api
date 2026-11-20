<?php

use App\Models\Order;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function quotePayload($event, int $qty = 2, array $extra = []): array
{
    $type = TicketType::query()->where('event_id', $event->id)->orderBy('sort_order')->first();

    return array_merge(['eventId' => $event->id, 'lines' => [['ticketTypeId' => $type->id, 'quantity' => $qty]], 'foodLines' => []], $extra);
}

function orderPayload(string $quoteId, string $method = 'paystack'): array
{
    return [
        'quoteId' => $quoteId,
        'attendee' => ['firstName' => 'Tessy', 'lastName' => 'Bello', 'email' => 'tessy@example.com'],
        'additionalGuests' => [],
        'seats' => [],
        'paymentMethod' => $method,
        'returnUrl' => 'https://wedpluz.com/checkout/return',
    ];
}

beforeEach(fn () => $this->configurePaystack());

it('prices the cart on the server and holds inventory', function () {
    $event = $this->liveEvent();
    $this->asCustomer();

    $quote = $this->postJson('/api/v1/checkout/quotes', quotePayload($event, 2))->assertOk()->json('data');

    // 2 × ₦5,000 = ₦10,000; fee = 3% + ₦100 = ₦400.
    expect($quote['subtotal'])->toBe(1000000)
        ->and($quote['serviceFee'])->toBe(40000)
        ->and($quote['total'])->toBe(1040000)
        ->and($quote['quoteId'])->toStartWith('qte_');
    expect((int) DB::table('inventory_holds')->sum('quantity'))->toBe(2);
});

it('never oversells: holds count against remaining stock', function () {
    $event = $this->liveEvent(types: [['name' => 'VIP', 'price' => 1000000, 'quantity' => 3]]);

    $this->asCustomer();
    $this->postJson('/api/v1/checkout/quotes', quotePayload($event, 2))->assertOk();

    $this->asCustomer();
    $this->postJson('/api/v1/checkout/quotes', quotePayload($event, 2))
        ->assertStatus(409)
        ->assertJsonPath('code', 'TICKETS_UNAVAILABLE')
        ->assertJsonPath('message', 'Only 1 VIP ticket is left.');
});

it('rejects organisers buying their own tickets and enforces the per-order limit', function () {
    $organizer = $this->customer(['identity_status' => 'verified']);
    $event = $this->liveEvent($organizer, [['name' => 'Regular', 'price' => 100000, 'quantity' => 50, 'per_order_limit' => 2]]);

    $this->asCustomer($organizer);
    $this->postJson('/api/v1/checkout/quotes', quotePayload($event, 1))->assertStatus(403);

    $this->asCustomer();
    $this->postJson('/api/v1/checkout/quotes', quotePayload($event, 3))->assertStatus(409)->assertJsonPath('code', 'TICKETS_UNAVAILABLE');
});

it('blocks unverified emails from buying tickets', function () {
    $event = $this->liveEvent();
    $this->asCustomer($this->customer(['email_verified_at' => null]));

    $this->postJson('/api/v1/checkout/quotes', quotePayload($event))->assertStatus(403)
        ->assertJsonPath('code', 'EMAIL_UNVERIFIED')
        ->assertJsonPath('message', 'Verify your email to continue.');
});

it('completes free orders immediately and issues one ticket per person', function () {
    $event = $this->liveEvent(types: [['name' => 'Free', 'price' => 0, 'quantity' => 100]]);
    $buyer = $this->asCustomer();
    $quote = $this->postJson('/api/v1/checkout/quotes', quotePayload($event, 2))->json('data');

    $order = $this->withHeader('Idempotency-Key', 'free-1')->postJson('/api/v1/orders', orderPayload($quote['quoteId'], 'free'))
        ->assertCreated()->assertJsonPath('data.order.status', 'paid')->json('data.order');

    expect($order['tickets'])->toHaveCount(2);
    expect($order['tickets'][0]['qrPayload'])->toStartWith('WP1.tik_');
    expect(TicketType::query()->where('event_id', $event->id)->value('sold'))->toBe(2);
    expect(Ticket::query()->where('holder_user_id', $buyer->id)->count())->toBe(2);
});

it('runs the paid flow: hosted checkout, server-side verification, tickets issued once', function () {
    $event = $this->liveEvent();
    $this->asCustomer();
    $quote = $this->postJson('/api/v1/checkout/quotes', quotePayload($event, 2))->json('data');

    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/abc', 'access_code' => 'abc', 'reference' => 'x']]),
        'api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'success', 'amount' => 1040000, 'currency' => 'NGN', 'id' => 991, 'fees' => 15600]]),
    ]);

    $created = $this->withHeader('Idempotency-Key', 'order-1')->postJson('/api/v1/orders', orderPayload($quote['quoteId']))
        ->assertCreated()
        ->assertJsonPath('data.order.status', 'awaiting_payment')
        ->assertJsonPath('data.payment.provider', 'paystack')
        ->assertJsonPath('data.payment.authorizationUrl', 'https://checkout.paystack.com/abc')
        ->json('data');

    $orderId = $created['order']['id'];
    $this->postJson("/api/v1/orders/$orderId/verify-payment", [])->assertOk()->assertJsonPath('data.status', 'paid');
    // A second verify (or a late webhook) is a no-op.
    $this->postJson("/api/v1/orders/$orderId/verify-payment", [])->assertOk()->assertJsonPath('data.status', 'paid');

    expect(Ticket::query()->where('order_id', $orderId)->count())->toBe(2);
    expect(TicketType::query()->where('event_id', $event->id)->value('sold'))->toBe(2);
    // Ledger postings balance.
    $balance = DB::table('ledger_entries')->selectRaw("sum(case when direction='debit' then amount else -amount end) as d")->value('d');
    expect((int) $balance)->toBe(0);
});

it('replays an idempotent order request and rejects a reused key with a different payload', function () {
    $event = $this->liveEvent();
    $this->asCustomer();
    $quote = $this->postJson('/api/v1/checkout/quotes', quotePayload($event, 1))->json('data');
    Http::fake(['api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/x', 'reference' => 'x']])]);

    $first = $this->withHeader('Idempotency-Key', 'same-key')->postJson('/api/v1/orders', orderPayload($quote['quoteId']))->assertCreated()->json('data.order.id');
    $replay = $this->withHeader('Idempotency-Key', 'same-key')->postJson('/api/v1/orders', orderPayload($quote['quoteId']))->assertCreated()->json('data.order.id');

    expect($replay)->toBe($first);
    expect(Order::query()->count())->toBe(1);

    $different = orderPayload($quote['quoteId']);
    $different['attendee']['firstName'] = 'Someone';
    $this->withHeader('Idempotency-Key', 'same-key')->postJson('/api/v1/orders', $different)->assertStatus(409)->assertJsonPath('code', 'CONFLICT');
});

it('does not issue tickets when the provider amount does not match', function () {
    $event = $this->liveEvent();
    $this->asCustomer();
    $quote = $this->postJson('/api/v1/checkout/quotes', quotePayload($event, 1))->json('data');
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/x', 'reference' => 'x']]),
        'api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'success', 'amount' => 100, 'currency' => 'NGN', 'id' => 1]]),
    ]);
    $orderId = $this->withHeader('Idempotency-Key', 'mm-1')->postJson('/api/v1/orders', orderPayload($quote['quoteId']))->json('data.order.id');

    $this->postJson("/api/v1/orders/$orderId/verify-payment")->assertOk()->assertJsonPath('data.status', 'awaiting_payment');

    expect(Ticket::query()->count())->toBe(0);
    expect(Payment::query()->first()->status->value)->toBe('mismatch');
    expect(DB::table('system_alerts')->where('title', 'Payment amount mismatch')->exists())->toBeTrue();
});

it('verifies webhook signatures, deduplicates events and fulfils the order', function () {
    $event = $this->liveEvent();
    $this->asCustomer();
    $quote = $this->postJson('/api/v1/checkout/quotes', quotePayload($event, 1))->json('data');
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/x', 'reference' => 'x']]),
        'api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'success', 'amount' => 525000, 'currency' => 'NGN', 'id' => 7]]),
    ]);
    $order = Order::query()->findOrFail($this->withHeader('Idempotency-Key', 'wh-1')->postJson('/api/v1/orders', orderPayload($quote['quoteId']))->json('data.order.id'));

    $body = json_encode(['event' => 'charge.success', 'data' => ['id' => 7, 'reference' => $order->reference, 'status' => 'success', 'amount' => 525000]]);
    $signature = hash_hmac('sha512', $body, 'sk_test_secret');

    $this->call('POST', '/api/v1/webhooks/paystack', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => 'bad'], $body)->assertStatus(401);
    $this->call('POST', '/api/v1/webhooks/paystack', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => $signature], $body)->assertOk();
    $this->call('POST', '/api/v1/webhooks/paystack', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => $signature], $body)->assertOk();

    expect(WebhookEvent::query()->where('provider', 'paystack')->count())->toBe(1);
    expect($order->refresh()->status->value)->toBe('paid');
    expect(Ticket::query()->where('order_id', $order->id)->count())->toBe(1);
});

it('expires a quote and refuses to create an order from it', function () {
    $event = $this->liveEvent();
    $this->asCustomer();
    $quote = $this->postJson('/api/v1/checkout/quotes', quotePayload($event, 1))->json('data');
    DB::table('checkout_quotes')->update(['expires_at' => now()->subMinute()]);

    $this->withHeader('Idempotency-Key', 'exp-1')->postJson('/api/v1/orders', orderPayload($quote['quoteId']))
        ->assertStatus(409)->assertJsonPath('message', 'Your reserved tickets have expired. Please review your order.');
});
