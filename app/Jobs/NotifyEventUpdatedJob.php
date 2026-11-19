<?php

namespace App\Jobs;

use App\Models\Event;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/** Notifies ticket holders when a live event's date, time or venue changes. */
class NotifyEventUpdatedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $eventId, public readonly string $changes)
    {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    public function handle(NotificationService $notifications): void
    {
        $event = Event::query()->find($this->eventId);
        if ($event === null) {
            return;
        }
        $holderIds = DB::table('tickets')->where('event_id', $event->id)->whereIn('status', ['valid'])->whereNotNull('holder_user_id')->distinct()->pluck('holder_user_id');
        $stamp = now()->format('YmdHi');

        User::query()->whereIn('id', $holderIds)->chunkById(200, function ($users) use ($notifications, $event, $stamp) {
            foreach ($users as $user) {
                $notifications->notify($user, 'event_updated', [
                    'tokens' => ['event' => $event->title],
                    'data' => ['eventId' => $event->id, 'eventSlug' => $event->slug],
                    'dedupe' => 'event-updated:'.$event->id.':'.$user->id.':'.$stamp,
                    'email' => ['eventTitle' => $event->title, 'changes' => $this->changes],
                ]);
            }
        });
    }
}
