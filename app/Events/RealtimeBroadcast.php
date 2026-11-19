<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Generic broadcast with a contract event name (e.g. `message.created`). */
class RealtimeBroadcast implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public string $queue = 'broadcasts';

    /** @param list<string> $channelNames */
    public function __construct(
        public readonly array $channelNames,
        public readonly string $eventName,
        public readonly array $payload,
    ) {}

    public function broadcastOn(): array
    {
        return array_map(fn (string $name) => new PrivateChannel($name), $this->channelNames);
    }

    public function broadcastAs(): string
    {
        return $this->eventName;
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
