<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tickets\CancelTicketRequest;
use App\Http\Requests\Tickets\TransferTicketRequest;
use App\Models\Ticket;
use App\Services\Checkout\RefundService;
use App\Services\Checkout\TicketPresenter;
use App\Services\Checkout\TicketTransferService;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/** My tickets: list (one item per order), detail with QR, cancel, transfer, download. */
class TicketController extends Controller
{
    public function __construct(private readonly TicketPresenter $presenter) {}

    public function index(Request $request): JsonResponse
    {
        $list = ListQuery::from($request);
        $tab = (string) $request->query('tab', 'upcoming');
        if (! in_array($tab, ['upcoming', 'past', 'cancelled'], true)) {
            throw ApiException::validation('Invalid tab.', ['tab' => ['The selected tab is invalid.']]);
        }
        $userId = $this->customer()->id;

        // One representative ticket per order: the first non-guest ticket.
        $representative = DB::table('tickets')->selectRaw('distinct on (order_id) id')
            ->where('holder_user_id', $userId)->orderBy('order_id')->orderBy('is_additional_guest')->orderBy('created_at');

        $query = Ticket::query()->select('tickets.*')
            ->join('events', 'events.id', '=', 'tickets.event_id')
            ->whereIn('tickets.id', $representative)
            ->when($list->searchPattern(), fn ($q, $p) => $q->where('events.title', 'ilike', $p));

        match ($tab) {
            'upcoming' => $query->whereIn('tickets.status', ['valid', 'checked_in'])
                ->whereNotIn('events.status', ['cancelled'])
                ->whereRaw('coalesce(events.ends_at, events.starts_at) >= now()')->orderBy('events.starts_at'),
            'past' => $query->whereIn('tickets.status', ['valid', 'checked_in'])
                ->whereRaw('coalesce(events.ends_at, events.starts_at) < now()')->orderByDesc('events.starts_at'),
            'cancelled' => $query->where(fn ($q) => $q->whereIn('tickets.status', ['cancelled', 'expired', 'transferred'])->orWhere('events.status', 'cancelled'))
                ->orderByDesc('tickets.updated_at'),
        };

        $page = $list->paginate($query);

        return response()->json(['data' => $this->presenter->listItems($page->items()), 'meta' => ListQuery::meta($page)]);
    }

    public function show(string $id): JsonResponse
    {
        return $this->ok($this->presenter->detail($this->find($id)));
    }

    public function cancel(CancelTicketRequest $request, string $id, RefundService $refunds): JsonResponse
    {
        $ticket = $this->find($id);
        if ($ticket->status->value === 'checked_in') {
            throw ApiException::conflict('Checked-in tickets cannot be cancelled.');
        }
        if ($ticket->status->value !== 'valid') {
            throw ApiException::conflict('This ticket is already '.$ticket->status->value.'.');
        }
        $ticket = $refunds->cancelByBuyer($ticket, $this->customer(), $request->string('reason'), $request->input('note'));

        return $this->ok($this->presenter->one($ticket), 'Your booking has been cancelled.');
    }

    public function transfer(TransferTicketRequest $request, string $id, TicketTransferService $transfers): JsonResponse
    {
        $ticket = $transfers->transfer($this->find($id), $this->customer(), $request->string('recipientEmail'), $request->input('message'));

        return $this->ok($this->presenter->one($ticket), 'Ticket transferred.');
    }

    public function download(string $id): JsonResponse
    {
        $ticket = $this->find($id);
        if (! in_array($ticket->status->value, ['valid', 'checked_in'], true)) {
            throw ApiException::conflict('This ticket is no longer valid.');
        }

        return $this->ok([
            'url' => URL::temporarySignedRoute('documents.ticket', now()->addMinutes(15), ['ticket' => $ticket->id]),
            'filename' => 'wedpluz-ticket-'.$ticket->code.'.pdf',
        ]);
    }

    private function find(string $id): Ticket
    {
        $ticket = Ticket::query()->where('holder_user_id', $this->customer()->id)->find($id);
        if ($ticket === null) {
            throw ApiException::notFound('Ticket not found.');
        }

        return $ticket;
    }
}
