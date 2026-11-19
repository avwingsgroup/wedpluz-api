<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use App\Services\Checkout\FulfillmentService;
use App\Services\Checkout\RefundService;
use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * After an event is cancelled: full refunds (including the service fee) for
 * every paid order, expire unpaid orders, and notify ticket holders. Safe to
 * re-run: each order is refunded at most once.
 */
class CancelEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct(public readonly string $eventId)
    {
        // Long-running: separate connection whose retry_after exceeds $timeout (config/queue.php).
        $this->onConnection(config('queue.long_connection'))->onQueue('long');
        $this->afterCommit();
    }

    public function handle(RefundService $refunds, NotificationService $notifications, FulfillmentService $fulfillment): void
    {
        $event = Event::withTrashed()->find($this->eventId);
        if ($event === null || $event->status->value !== 'cancelled') {
            return;
        }

        Order::query()->where('event_id', $event->id)->whereIn('status', [OrderStatus::AwaitingPayment->value, OrderStatus::Pending->value])
            ->each(function (Order $order) use ($fulfillment) {
                DB::transaction(function () use ($order, $fulfillment) {
                    $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();
                    if (in_array($locked->status, [OrderStatus::AwaitingPayment, OrderStatus::Pending], true)) {
                        $locked->forceFill(['status' => OrderStatus::Cancelled])->save();
                        $fulfillment->releaseReservations($locked);
                    }
                });
            });

        $holderIds = Order::query()->where('event_id', $event->id)->where('status', OrderStatus::Paid->value)->distinct()->pluck('user_id');

        Order::query()->where('event_id', $event->id)->where('status', OrderStatus::Paid->value)
            ->each(fn (Order $order) => $refunds->refundForEventCancellation($order, 'Event cancelled by the organiser'));

        foreach (User::query()->whereIn('id', $holderIds)->get() as $user) {
            $notifications->notify($user, 'event_cancelled', [
                'tokens' => ['event' => $event->title],
                'data' => ['eventId' => $event->id],
                'dedupe' => 'event-cancelled:'.$event->id.':'.$user->id,
                'email' => ['eventTitle' => $event->title, 'reason' => (string) $event->cancellation_reason],
            ]);
        }
    }
}
