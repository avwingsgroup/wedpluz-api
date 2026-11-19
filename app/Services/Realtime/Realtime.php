<?php

namespace App\Services\Realtime;

use App\Events\RealtimeBroadcast;

/**
 * Emits realtime events with the contract's dotted names on Pusher-protocol
 * channels (Reverb). Always queued and dispatched after commit; a no-op when
 * broadcasting is disabled. Payloads use the REST resource shapes, and events
 * are at-most-once: clients refetch on reconnect.
 */
final class Realtime
{
    public static function enabled(): bool
    {
        return (bool) config('wedpluz.realtime.enabled');
    }

    /** @param list<string>|string $channels private channel names without the `private-` prefix */
    public static function emit(array|string $channels, string $event, array $payload): void
    {
        if (! self::enabled()) {
            return;
        }
        event(new RealtimeBroadcast((array) $channels, $event, $payload));
    }

    public static function toUser(string $userId, string $event, array $payload): void
    {
        self::emit('user.'.$userId, $event, $payload);
    }
}
