<?php

namespace App\Services\Checkout;

use App\Enums\TicketStatus;
use App\Exceptions\ApiException;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\TicketTransfer;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\Platform\SettingsService;
use App\Support\Codes;
use App\Support\Platform;
use Illuminate\Support\Facades\DB;

/**
 * Ticket transfer (contract status: proposed). Only valid tickets, before the
 * event starts, when the organiser allows it. The old QR is invalidated and a
 * new ticket with a new code and QR is issued to the recipient.
 */
class TicketTransferService
{
    public function __construct(private readonly NotificationService $notifications, private readonly SettingsService $settings) {}

    public function transfer(Ticket $ticket, User $user, string $recipientEmail, ?string $message): Ticket
    {
        if (! $this->settings->feature('ticketTransferEnabled')) {
            throw ApiException::forbidden('Ticket transfers are not available.');
        }
        $event = Event::query()->findOrFail($ticket->event_id);
        if (! $event->allow_ticket_transfer) {
            throw ApiException::forbidden('The organiser does not allow ticket transfers for this event.');
        }
        if ($event->starts_at && $event->starts_at->isPast()) {
            throw ApiException::conflict('Tickets cannot be transferred after the event starts.');
        }
        $email = mb_strtolower(trim($recipientEmail));
        if ($email === mb_strtolower($user->email)) {
            throw ApiException::validation('You cannot transfer a ticket to yourself.', ['recipientEmail' => ['Enter someone else\'s email.']]);
        }
        $recipient = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        $new = DB::transaction(function () use ($ticket, $user, $email, $recipient, $message) {
            $locked = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->first();
            if ($locked->status !== TicketStatus::Valid) {
                throw ApiException::conflict('Only valid tickets can be transferred.');
            }

            $new = Ticket::query()->create([
                'code' => Codes::ticketCode(),
                'order_id' => $locked->order_id,
                'event_id' => $locked->event_id,
                'schedule_id' => $locked->schedule_id,
                'ticket_type_id' => $locked->ticket_type_id,
                'holder_user_id' => $recipient?->id,
                'holder_name' => $recipient?->fullName() ?? $email,
                'holder_email' => $email,
                'is_additional_guest' => $locked->is_additional_guest,
                'status' => TicketStatus::Valid,
                'seat_reservation_id' => $locked->seat_reservation_id,
            ]);
            $locked->forceFill([
                'status' => TicketStatus::Transferred,
                'transferred_to_ticket_id' => $new->id,
                'transferred_to_user_id' => $recipient?->id,
                'qr_secret_version' => $locked->qr_secret_version + 1,
            ])->save();
            TicketTransfer::query()->create([
                'ticket_id' => $locked->id, 'from_user_id' => $user->id, 'recipient_email' => $email,
                'message' => $message, 'new_ticket_id' => $new->id, 'status' => 'completed',
            ]);

            return $new;
        });

        $eventTitle = Event::query()->whereKey($ticket->event_id)->value('title');
        if ($recipient) {
            $this->notifications->notify($recipient, 'ticket_purchased', [
                'actor' => $user,
                'title' => 'You received a ticket',
                'body' => $user->display_name.' sent you a ticket for '.$eventTitle.'.',
                'data' => ['ticketId' => $new->id],
                'link' => Platform::webUrl('/tickets/'.$new->id),
                'dedupe' => 'transfer:'.$new->id,
                'email' => ['eventTitle' => $eventTitle, 'orderReference' => $new->code, 'ticketCount' => 1, 'total' => 'Transferred', 'eventDate' => '', 'ticketsUrl' => Platform::webUrl('/tickets')],
            ]);
        } else {
            $this->notifications->sendDirect('email', $email, 'generic', [
                'firstName' => 'there',
                'title' => 'You received a Wedpluz ticket',
                'body' => $user->display_name.' sent you a ticket for '.$eventTitle.'. Sign up with this email address to view it. Ticket code: '.$new->code.($message ? ' — "'.$message.'"' : ''),
            ], 'transfer:'.$new->id);
        }

        return $ticket->refresh();
    }
}
