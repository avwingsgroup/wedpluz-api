<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Fmt;
use App\Models\CheckIn;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\Refund;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\Admin\AdminPresenter;
use App\Services\Admin\AuditLogger;
use App\Services\Checkout\RefundService;
use App\Services\Notifications\NotificationService;
use App\Support\ListQuery;
use App\Support\Money;
use App\Support\Platform;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Bookings, refunds issued by admins, resends, disputes, QR validations and inventory. */
class AdminBookingsController extends Controller
{
    public function __construct(private readonly AdminPresenter $presenter, private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $list = ListQuery::from($request);
        $query = Order::query()
            ->when($list->searchPattern(), fn ($q, $p) => $q->where(fn ($w) => $w->where('reference', 'ilike', $p)
                ->orWhereRaw("attendee->>'email' ilike ?", [$p])
                ->orWhereHas('user', fn ($u) => $u->where('display_name', 'ilike', $p)->orWhere('email', 'ilike', $p))))
            ->when($list->filter('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($list->filter('eventId'), fn ($q, $e) => $q->where('event_id', $e))
            ->when($list->filter('provider'), fn ($q, $p) => $q->where('provider', $p))
            ->when($list->dateFrom(), fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($list->dateTo(), fn ($q, $d) => $q->where('created_at', '<=', $d));
        $list->applySort($query, ['createdAt' => 'created_at', 'total' => 'total'], 'createdAt');
        $page = $list->paginate($query);

        return response()->json(['data' => $this->presenter->bookings($page->items()), 'meta' => ListQuery::meta($page)]);
    }

    public function show(string $id): JsonResponse
    {
        $order = Order::query()->findOrFail($id);
        $tickets = Ticket::query()->where('order_id', $order->id)->orderBy('created_at')->get();
        $dispute = Dispute::query()->with(['order', 'raisedBy', 'assignee'])->where('order_id', $order->id)->latest()->first();

        $timeline = collect([['at' => $order->created_at, 'label' => 'Order created', 'by' => 'Buyer']]);
        if ($order->paid_at) {
            $timeline->push(['at' => $order->paid_at, 'label' => 'Payment confirmed via '.$order->provider, 'by' => 'System']);
        }
        foreach (Refund::query()->where('order_id', $order->id)->get() as $refund) {
            $timeline->push(['at' => $refund->created_at, 'label' => 'Refund '.$refund->reference.' '.$refund->status->value.' ('.Money::format($refund->amount, $refund->currency).')', 'by' => $refund->requested_by_admin_id ? 'Admin' : 'Buyer']);
        }
        foreach ($tickets->whereNotNull('checked_in_at') as $t) {
            $timeline->push(['at' => $t->checked_in_at, 'label' => 'Ticket '.$t->code.' checked in', 'by' => 'Scanner']);
        }
        if ($order->cancelled_at) {
            $timeline->push(['at' => $order->cancelled_at, 'label' => 'Order cancelled', 'by' => 'Buyer']);
        }

        return $this->ok($this->presenter->bookings([$order])[0] + [
            'tickets' => $tickets->map(fn ($t) => ['id' => $t->id, 'code' => $t->code, 'holderName' => $t->holder_name, 'status' => $t->status->value, 'checkedInAt' => Fmt::date($t->checked_in_at)])->all(),
            'timeline' => $timeline->sortBy('at')->values()->map(fn ($e) => ['at' => Fmt::date($e['at']), 'label' => $e['label'], 'by' => $e['by']])->all(),
            'dispute' => $dispute ? AdminPresenter::dispute($dispute) : null,
        ]);
    }

    public function refund(Request $request, string $id, RefundService $refunds): JsonResponse
    {
        $data = $request->validate(['amount' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $order = Order::query()->findOrFail($id);
        $refund = $refunds->refundByAdmin($order, (int) $data['amount'], $data['reason'], $this->admin());
        $this->audit->log('booking.refunded', 'refunds', $order, $order->reference, null, ['amount' => $data['amount'], 'reason' => $data['reason'], 'refundId' => $refund->id]);

        return $this->ok(AdminPresenter::refund($refund->load(['order.event', 'order.user', 'decidedBy'])), 'Refund issued.');
    }

    public function resend(string $id, NotificationService $notifications): JsonResponse
    {
        $order = Order::query()->with(['event', 'user'])->findOrFail($id);
        if (! $order->isPaid() || $order->user === null) {
            throw ApiException::conflict('Only paid orders can be resent.');
        }
        $notifications->sendDirect('email', (string) ($order->attendee['email'] ?? $order->user->email), 'ticket_purchased', [
            'firstName' => $order->attendee['firstName'] ?? $order->user->first_name,
            'eventTitle' => $order->event->title,
            'eventDate' => $order->event->starts_at?->setTimezone(Platform::timezone())->format('D j M Y, g:i A') ?? '',
            'orderReference' => $order->reference,
            'total' => Money::format($order->total, $order->currency),
            'ticketCount' => Ticket::query()->where('order_id', $order->id)->count(),
            'ticketsUrl' => Platform::webUrl('/tickets'),
        ], 'resend:'.$order->id.':'.now()->timestamp, $order->user);
        $this->audit->log('booking.resent', 'bookings', $order, $order->reference);

        return $this->ok(['sent' => true], 'Confirmation resent.');
    }

    public function disputes(Request $request): JsonResponse
    {
        $list = ListQuery::from($request);
        $query = Dispute::query()->with(['order', 'raisedBy', 'assignee'])
            ->when($list->filter('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($list->searchPattern(), fn ($q, $p) => $q->whereHas('order', fn ($o) => $o->where('reference', 'ilike', $p)));
        $list->applySort($query, ['createdAt' => 'created_at', 'updatedAt' => 'updated_at'], 'createdAt');

        return $this->paginated($list->paginate($query), fn (Dispute $d) => AdminPresenter::dispute($d));
    }

    public function updateDispute(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['status' => ['sometimes', 'in:open,investigating,resolved_refund,resolved_rejected'], 'assignToMe' => ['sometimes', 'boolean'], 'note' => ['nullable', 'string', 'max:2000']]);
        $dispute = Dispute::query()->with(['order', 'raisedBy', 'assignee'])->findOrFail($id);
        $before = ['status' => $dispute->status, 'assignedAdminId' => $dispute->assigned_admin_id];
        $changes = [];
        if (isset($data['status'])) {
            $changes['status'] = $data['status'];
        }
        if (! empty($data['assignToMe'])) {
            $changes['assigned_admin_id'] = $this->admin()->id;
        }
        if (! empty($data['note'])) {
            $changes['notes'] = array_merge($dispute->notes ?? [], [['by' => $this->admin()->id, 'at' => now()->toIso8601String(), 'note' => $data['note']]]);
        }
        $dispute->forceFill($changes)->save();
        $this->audit->log('dispute.updated', 'bookings', $dispute, $dispute->order->reference, $before, $data);

        return $this->ok(AdminPresenter::dispute($dispute->refresh()->load(['order', 'raisedBy', 'assignee'])));
    }

    public function checkIns(Request $request): JsonResponse
    {
        $list = ListQuery::from($request);
        $query = CheckIn::query()->with(['event', 'ticket.holder', 'scanner'])
            ->when($list->filter('eventId'), fn ($q, $e) => $q->where('event_id', $e))
            ->when($list->filter('result'), fn ($q, $r) => $q->where('result', $r))
            ->when($list->searchPattern(), fn ($q, $p) => $q->where('code_scanned', 'ilike', $p))
            ->when($list->dateFrom(), fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($list->dateTo(), fn ($q, $d) => $q->where('created_at', '<=', $d));
        $list->applySort($query, ['checkedInAt' => 'created_at', 'createdAt' => 'created_at'], 'checkedInAt');

        return $this->paginated($list->paginate($query), fn (CheckIn $c) => [
            'id' => $c->id,
            'event' => ['id' => $c->event->id, 'title' => $c->event->title],
            'attendee' => Fmt::user($c->ticket?->holder) ?? ['id' => '', 'displayName' => $c->ticket?->holder_name ?? 'Unknown', 'avatarUrl' => null],
            'ticketCode' => $c->ticket?->code ?? mb_substr($c->code_scanned, 0, 40),
            'checkedInBy' => Fmt::user($c->scanner),
            'method' => $c->method,
            'device' => (string) $c->device,
            'checkedInAt' => Fmt::date($c->created_at),
            'result' => $c->result,
        ]);
    }

    public function inventory(Request $request): JsonResponse
    {
        $list = ListQuery::from($request);
        $query = TicketType::query()->with('event')->whereHas('event')
            ->when($list->filter('eventId'), fn ($q, $e) => $q->where('event_id', $e))
            ->when($list->bool('lowStock'), fn ($q) => $q->whereRaw('quantity - sold <= greatest(5, quantity * 0.1)'))
            ->when($list->searchPattern(), fn ($q, $p) => $q->where(fn ($w) => $w->where('name', 'ilike', $p)->orWhereHas('event', fn ($e) => $e->where('title', 'ilike', $p))));
        $list->applySort($query, ['sold' => 'sold', 'remaining' => '(quantity - sold)', 'sellThrough' => '(sold::float / greatest(quantity, 1))', 'createdAt' => 'created_at'], 'createdAt');

        return $this->paginated($list->paginate($query), fn (TicketType $t) => [
            'id' => $t->id,
            'event' => ['id' => $t->event->id, 'title' => $t->event->title, 'startsAt' => Fmt::date($t->event->starts_at) ?? Fmt::date($t->event->created_at)],
            'ticketType' => $t->name,
            'price' => $t->price,
            'quantity' => $t->quantity,
            'sold' => $t->sold,
            'remaining' => max(0, $t->quantity - $t->sold),
            'salesEndAt' => Fmt::date($t->sales_end_at),
            'sellThrough' => $t->quantity > 0 ? round($t->sold / $t->quantity * 100, 1) : 0,
        ]);
    }
}
