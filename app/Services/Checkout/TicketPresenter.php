<?php

namespace App\Services\Checkout;

use App\Http\Resources\Fmt;
use App\Models\Order;
use App\Models\Refund;
use App\Models\Ticket;
use App\Services\Events\EventPresenter;
use App\Support\Models;
use Illuminate\Support\Facades\DB;

/** Ticket, TicketListItem and TicketDetail shapes (batched). */
class TicketPresenter
{
    private const RELATIONS = ['event', 'ticketType', 'seatReservation', 'order.foodLines', 'transferredToUser'];

    /** @param iterable<Ticket> $tickets */
    public function many(iterable $tickets): array
    {
        $tickets = Models::collect($tickets);
        $tickets->loadMissing(self::RELATIONS);
        $refunds = $this->latestRefunds($tickets->pluck('order_id')->unique()->values()->all());

        return $tickets->map(fn (Ticket $t) => $this->shape($t, $refunds[$t->order_id] ?? null))->values()->all();
    }

    public function one(Ticket $ticket): array
    {
        return $this->many([$ticket])[0];
    }

    /** TicketListItem: one per order, represented by its first ticket. */
    public function listItems(iterable $tickets): array
    {
        $tickets = Models::collect($tickets);
        $base = $this->many($tickets);
        $counts = DB::table('tickets')->whereIn('order_id', $tickets->pluck('order_id'))
            ->selectRaw("order_id, count(*) as total, count(*) filter (where status = 'checked_in') as checked")
            ->groupBy('order_id')->get()->keyBy('order_id');

        return array_map(function (array $item) use ($counts) {
            $c = $counts[$item['orderId']] ?? null;

            return $item + ['ticketCount' => (int) ($c->total ?? 1), 'checkedInCount' => (int) ($c->checked ?? 0)];
        }, $base);
    }

    public function detail(Ticket $ticket): array
    {
        $siblings = Ticket::query()->where('order_id', $ticket->order_id)->where('id', '!=', $ticket->id)->orderBy('created_at')->get();

        return $this->one($ticket) + ['siblings' => $this->many($siblings)];
    }

    private function shape(Ticket $t, ?Refund $refund): array
    {
        $event = $t->event;
        /** @var Order|null $order */
        $order = $t->order;
        $seat = $t->seatReservation;

        return [
            'id' => $t->id,
            'code' => $t->code,
            'orderId' => $t->order_id,
            'eventId' => $t->event_id,
            'event' => [
                'id' => $event->id,
                'slug' => $event->slug,
                'title' => $event->title,
                'city' => $event->city,
                'startsAt' => Fmt::date($event->starts_at) ?? Fmt::date($event->created_at),
                'endsAt' => Fmt::date($event->ends_at),
                'coverUrl' => $event->cover_url ?: EventPresenter::placeholderCover(),
                'attendanceMode' => $event->attendance_mode,
            ],
            'ticketTypeName' => $t->is_additional_guest ? 'Guest' : ($t->ticketType?->name ?? 'Ticket'),
            'holderName' => $t->holder_name,
            'qrPayload' => in_array($t->status->value, ['valid', 'checked_in'], true) ? TicketQr::payload($t) : '',
            'status' => $t->status->value,
            'checkedInAt' => Fmt::date($t->checked_in_at),
            'seat' => $seat ? ['tableLabel' => $seat->table_label, 'seatLabel' => $seat->seat_label] : null,
            'scheduleId' => $t->schedule_id,
            'additionalGuests' => array_values($order?->additional_guests ?? []),
            'foodLines' => $order ? $order->foodLines->map(fn ($l) => [
                'menuItem' => ['id' => $l->menu_item_id, 'name' => $l->name_snapshot, 'price' => $l->unit_price],
                'quantity' => $l->quantity,
                'subtotal' => $l->subtotal,
            ])->all() : [],
            'orderTotal' => $order?->total ?? 0,
            'currency' => $order?->currency ?? $event->currency,
            'refund' => RefundService::summary($refund),
            'transferredTo' => Fmt::user($t->transferredToUser),
            'createdAt' => Fmt::date($t->created_at),
        ];
    }

    /** @param list<string> $orderIds @return array<string, Refund> */
    private function latestRefunds(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        return Refund::query()->whereIn('order_id', $orderIds)->orderBy('created_at')->get()->keyBy('order_id')->all();
    }
}
