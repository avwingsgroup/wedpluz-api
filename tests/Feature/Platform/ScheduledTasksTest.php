<?php

use App\Models\Order;
use App\Models\TicketType;
use App\Services\Platform\ScheduledTasks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

it('expires abandoned checkouts and releases their inventory', function () {
    $this->configurePaystack();
    $event = $this->liveEvent(types: [['name' => 'VIP', 'price' => 100000, 'quantity' => 2]]);
    $type = TicketType::query()->where('event_id', $event->id)->first();
    $this->asCustomer();
    $quote = $this->postJson('/api/v1/checkout/quotes', ['eventId' => $event->id, 'lines' => [['ticketTypeId' => $type->id, 'quantity' => 2]]])->json('data');
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/x']]),
        'api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'abandoned']]),
    ]);
    $orderId = $this->withHeader('Idempotency-Key', 'exp')->postJson('/api/v1/orders', [
        'quoteId' => $quote['quoteId'], 'attendee' => ['firstName' => 'A', 'lastName' => 'B', 'email' => 'a@b.co'],
        'paymentMethod' => 'paystack', 'returnUrl' => 'https://wedpluz.com/checkout/return',
    ])->json('data.order.id');

    // Sold out while held.
    $this->asCustomer();
    $this->postJson('/api/v1/checkout/quotes', ['eventId' => $event->id, 'lines' => [['ticketTypeId' => $type->id, 'quantity' => 1]]])->assertStatus(409);

    Order::query()->whereKey($orderId)->update(['expires_at' => now()->subMinute()]);
    DB::table('inventory_holds')->update(['expires_at' => now()->subMinute()]);
    expect(app(ScheduledTasks::class)->expireOrders())->toBe(1);
    expect(Order::query()->find($orderId)->status->value)->toBe('expired');

    $this->postJson('/api/v1/checkout/quotes', ['eventId' => $event->id, 'lines' => [['ticketTypeId' => $type->id, 'quantity' => 2]]])->assertOk();
});

it('completes ended events and creates their settlement', function () {
    $event = $this->liveEvent();
    $event->forceFill(['ends_at' => now()->subHour()])->save();

    expect(app(ScheduledTasks::class)->completeEvents())->toBe(1);
    expect($event->refresh()->status->value)->toBe('completed')
        ->and(DB::table('settlements')->where('event_id', $event->id)->exists())->toBeTrue();
});
