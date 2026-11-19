<?php

namespace App\Services\Checkout;

use App\Models\Ticket;

/**
 * Opaque, signed QR payloads: `WP1.<ticketId>.<scheduleId|*>.<hmac>`.
 * The HMAC covers the ticket's secret version, so bumping the version
 * (transfer, reissue) invalidates previously printed codes. The QR is never
 * trusted without server-side validation at check-in.
 */
final class TicketQr
{
    public static function payload(Ticket $ticket): string
    {
        $schedule = $ticket->schedule_id ?: '*';

        return 'WP1.'.$ticket->id.'.'.$schedule.'.'.self::sign($ticket->id, $schedule, (int) $ticket->qr_secret_version);
    }

    /** @return array{ticketId:string, scheduleId:?string, signature:string}|null */
    public static function parse(string $payload): ?array
    {
        if (! preg_match('/^WP1\.(tik_[0-9A-Z]{26})\.([A-Za-z0-9_*]+)\.([A-Za-z0-9_-]{16,64})$/', trim($payload), $m)) {
            return null;
        }

        return ['ticketId' => $m[1], 'scheduleId' => $m[2] === '*' ? null : $m[2], 'signature' => $m[3]];
    }

    public static function verify(Ticket $ticket, array $parsed): bool
    {
        $schedule = $parsed['scheduleId'] ?? '*';

        return hash_equals(self::sign($ticket->id, $schedule, (int) $ticket->qr_secret_version), $parsed['signature']);
    }

    private static function sign(string $ticketId, string $schedule, int $version): string
    {
        $mac = hash_hmac('sha256', 'WP1|'.$ticketId.'|'.$schedule.'|'.$version, (string) config('app.key'), true);

        return rtrim(strtr(base64_encode(substr($mac, 0, 18)), '+/', '-_'), '=');
    }
}
