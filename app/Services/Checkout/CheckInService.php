<?php

namespace App\Services\Checkout;

use App\Enums\EventStatus;
use App\Http\Resources\Fmt;
use App\Models\CheckIn;
use App\Models\Event;
use App\Models\EventSchedule;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Events\ActivityLogger;
use App\Services\Realtime\Realtime;
use App\Support\Platform;
use Illuminate\Support\Facades\DB;

/**
 * Ticket validation at the door (business-rules.md, "Tickets and QR check-in").
 * Result reasons in priority order: not_found, wrong_event, cancelled/expired,
 * wrong_day, already_checked_in, ok. `ok` is a single atomic conditional
 * UPDATE, so two scanners can never admit the same ticket twice. Every
 * attempt is recorded.
 */
class CheckInService
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function validate(Event $event, User $scanner, string $code, string $method, ?string $device): array
    {
        $code = trim($code);
        $ticket = $this->resolve($code);
        $reason = $this->reason($event, $ticket);

        if ($reason === 'ok') {
            $updated = DB::table('tickets')->where('id', $ticket->id)->where('status', 'valid')->update([
                'status' => 'checked_in',
                'checked_in_at' => now(),
                'checked_in_by_user_id' => $scanner->id,
                'updated_at' => now(),
            ]);
            if ($updated === 0) {
                $reason = 'already_checked_in';
            }
            $ticket->refresh();
        }

        $record = CheckIn::query()->create([
            'ticket_id' => $ticket?->id,
            'event_id' => $event->id,
            'code_scanned' => mb_substr($code, 0, 512),
            'result' => $reason,
            'method' => $method,
            'device' => $device ? mb_substr($device, 0, 255) : null,
            'scanned_by_user_id' => $scanner->id,
        ]);

        if ($reason === 'ok') {
            $this->activity->log($event, $scanner, 'ticket.checked_in', 'Checked in '.$ticket->holder_name.' ('.$ticket->code.')');
            $checked = Ticket::query()->where('event_id', $event->id)->where('status', 'checked_in')->count();
            Realtime::emit('event.'.$event->id.'.manage', 'checkin.created', self::record($record->load(['ticket.holder', 'ticket.ticketType', 'scanner'])) + [
                'checkedIn' => $checked,
                'capacity' => $event->guest_limit,
            ]);
            Realtime::toUser((string) $ticket->holder_user_id, 'ticket.updated', ['id' => $ticket->id, 'status' => 'checked_in']);
        }

        $ticket?->loadMissing(['holder', 'ticketType', 'seatReservation']);
        $exposeTicket = $ticket !== null && $reason !== 'not_found' && $reason !== 'wrong_event';

        return [
            'valid' => $reason === 'ok',
            'reason' => $reason,
            'ticket' => $exposeTicket ? [
                'id' => $ticket->id,
                'code' => $ticket->code,
                'holderName' => $ticket->holder_name,
                'ticketTypeName' => $ticket->is_additional_guest ? 'Guest' : ($ticket->ticketType?->name ?? 'Ticket'),
                'checkedInAt' => Fmt::date($ticket->checked_in_at),
                'seat' => $ticket->seatReservation ? ['tableLabel' => $ticket->seatReservation->table_label, 'seatLabel' => $ticket->seatReservation->seat_label] : null,
            ] : null,
            'attendee' => $exposeTicket ? Fmt::user($ticket->holder) : null,
        ];
    }

    private function resolve(string $code): ?Ticket
    {
        if (str_starts_with($code, 'WP1.')) {
            $parsed = TicketQr::parse($code);
            if ($parsed === null) {
                return null;
            }
            $ticket = Ticket::query()->find($parsed['ticketId']);

            return $ticket && TicketQr::verify($ticket, $parsed) ? $ticket : null;
        }

        return Ticket::query()->where('code', strtoupper($code))->first();
    }

    private function reason(Event $event, ?Ticket $ticket): string
    {
        if ($ticket === null) {
            return 'not_found';
        }
        if ($ticket->event_id !== $event->id) {
            return 'wrong_event';
        }
        if ($ticket->status->value === 'cancelled' || $ticket->status->value === 'transferred' || $event->status === EventStatus::Cancelled) {
            return 'cancelled';
        }
        if ($ticket->status->value === 'expired') {
            return 'expired';
        }
        if ($ticket->schedule_id !== null && ! $this->isTodaysSchedule($ticket->schedule_id)) {
            return 'wrong_day';
        }
        if ($ticket->status->value === 'checked_in') {
            return 'already_checked_in';
        }

        return 'ok';
    }

    /** A schedule is "today" if now falls on its local calendar day(s), with a 6-hour grace either side. */
    private function isTodaysSchedule(string $scheduleId): bool
    {
        $schedule = EventSchedule::query()->find($scheduleId);
        if ($schedule === null) {
            return true;
        }
        $tz = Platform::timezone();
        $start = $schedule->starts_at->setTimezone($tz)->startOfDay()->subHours(6);
        $end = ($schedule->ends_at ?? $schedule->starts_at)->setTimezone($tz)->endOfDay()->addHours(6);

        return now()->between($start, $end);
    }

    /** CheckInRecord. */
    public static function record(CheckIn $c): array
    {
        $ticket = $c->ticket;

        return [
            'id' => $c->id,
            'ticketCode' => $ticket?->code ?? $c->code_scanned,
            'attendee' => Fmt::user($ticket?->holder) ?? ['id' => '', 'displayName' => $ticket?->holder_name ?? 'Unknown', 'avatarUrl' => null],
            'ticketTypeName' => $ticket?->is_additional_guest ? 'Guest' : ($ticket?->ticketType?->name ?? 'Ticket'),
            'checkedInAt' => Fmt::date($c->created_at),
            'checkedInBy' => Fmt::user($c->scanner),
            'method' => $c->method,
        ];
    }
}
