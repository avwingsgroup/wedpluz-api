<?php

namespace App\Services\Events;

use App\Enums\EventStatus;
use App\Exceptions\ApiException;
use App\Http\Resources\Fmt;
use App\Models\Event;
use App\Models\EventPromotion;
use App\Models\PromoterEnrollment;
use App\Models\User;
use App\Support\Codes;
use App\Support\Models;
use App\Support\Platform;
use App\Support\Sql;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Earn with Wedpluz": organisers enable promotion with a 1–50% commission;
 * users join to get a unique share code and link (`/events/{slug}?ref=CODE`).
 * Commission accrues on the ticket subtotal of attributed paid orders and is
 * credited to the promoter's wallet when the event settlement becomes ready.
 */
class PromotionService
{
    public function __construct(
        private readonly EventAccess $access,
        private readonly ActivityLogger $activity,
        private readonly EventPresenter $presenter,
    ) {}

    public function settings(Event $event): array
    {
        $event->loadMissing('promotion');

        return EventPresenter::promotionSettings($event, $this->promoterCount($event->id));
    }

    /** @param array{enabled?:bool, commissionPercent?:float, targetSignups?:?int, promoterLimit?:?int} $data */
    public function update(Event $event, User $actor, array $data): array
    {
        $this->access->requireFullAccess($actor, $event);
        $promotion = EventPromotion::query()->firstOrCreate(['event_id' => $event->id], ['enabled' => false, 'commission_percent' => 10]);
        $changes = [];
        foreach (['enabled' => 'enabled', 'commissionPercent' => 'commission_percent', 'targetSignups' => 'target_signups', 'promoterLimit' => 'promoter_limit'] as $key => $column) {
            if (array_key_exists($key, $data)) {
                $changes[$column] = $data[$key];
            }
        }
        $promotion->forceFill($changes)->save();
        $event->setRelation('promotion', $promotion);
        $this->activity->log($event, $actor, 'promotion.updated', ($promotion->enabled ? 'Updated' : 'Disabled').' the promotion programme', $data);

        return $this->settings($event);
    }

    /** PromoterBoardEntry[] ranked by signups then earnings. */
    public function leaderboard(Event $event): array
    {
        $rows = DB::table('promoter_enrollments as e')
            ->leftJoin('promoter_commissions as c', fn ($j) => $j->on('c.enrollment_id', '=', 'e.id')->where('c.status', '!=', 'reversed'))
            ->where('e.event_id', $event->id)
            ->groupBy('e.id', 'e.user_id')
            ->selectRaw('e.id, e.user_id, count(c.id) as signups, coalesce(sum(c.amount), 0) as earnings')
            ->orderByDesc('signups')->orderByDesc('earnings')->limit(100)->get();
        $users = User::withTrashed()->whereIn('id', $rows->pluck('user_id'))->get()->keyBy('id');

        return $rows->values()->map(fn ($r, $i) => [
            'rank' => $i + 1,
            'user' => Fmt::user($users[$r->user_id] ?? null),
            'signups' => (int) $r->signups,
            'earnings' => (int) $r->earnings,
        ])->all();
    }

    public function join(Event $event, User $user): PromoterEnrollment
    {
        $event->loadMissing('promotion');
        if ($event->status !== EventStatus::Live || ! $event->promotion?->enabled) {
            throw ApiException::conflict('This event is not open for promotion.');
        }
        if ($event->organizer_user_id === $user->id) {
            throw ApiException::conflict('You cannot promote your own event.');
        }
        $existing = PromoterEnrollment::query()->where('event_id', $event->id)->where('user_id', $user->id)->first();
        if ($existing) {
            return $existing;
        }
        $limit = $event->promotion->promoter_limit;
        if ($limit !== null && $this->promoterCount($event->id) >= $limit) {
            throw ApiException::conflict('This promotion has reached its promoter limit.');
        }

        return Sql::insertOrNull(fn () => PromoterEnrollment::query()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'share_code' => Codes::unique('promoter_enrollments', 'share_code', 8),
            'status' => 'active',
            'joined_at' => now(),
        ])) ?? PromoterEnrollment::query()->where('event_id', $event->id)->where('user_id', $user->id)->firstOrFail();
    }

    /** @param Collection<int, PromoterEnrollment> $enrollments */
    public function resources(Collection $enrollments, User $viewer): array
    {
        $enrollments = Models::collect($enrollments);
        $enrollments->loadMissing('event.promotion');
        $summaries = collect($this->presenter->summaries($enrollments->pluck('event'), $viewer))->keyBy('id');
        $stats = DB::table('promoter_commissions')->whereIn('enrollment_id', $enrollments->pluck('id'))->where('status', '!=', 'reversed')
            ->groupBy('enrollment_id')
            ->selectRaw("enrollment_id, count(*) as signups, coalesce(sum(amount), 0) as earnings, bool_or(status = 'credited') as withdrawable")
            ->get()->keyBy('enrollment_id');

        return $enrollments->map(function (PromoterEnrollment $e) use ($summaries, $stats) {
            $event = $e->event;
            $s = $stats[$e->id] ?? null;
            $ended = in_array($event->status, [EventStatus::Completed, EventStatus::Cancelled], true) || ($event->ends_at && $event->ends_at->isPast());

            return [
                'id' => $e->id,
                'event' => $summaries[$event->id],
                'commissionPercent' => (float) ($event->promotion?->commission_percent ?? 0),
                'shareLink' => Platform::webUrl('/events/'.$event->slug.'?ref='.$e->share_code),
                'shareCode' => $e->share_code,
                'signups' => (int) ($s->signups ?? 0),
                'targetSignups' => $event->promotion?->target_signups,
                'earnings' => (int) ($s->earnings ?? 0),
                'withdrawable' => (bool) ($s->withdrawable ?? false),
                'status' => $ended ? 'past' : ($event->promotion?->enabled ? 'active' : 'paused'),
                'joinedAt' => Fmt::date($e->joined_at),
            ];
        })->values()->all();
    }

    private function promoterCount(string $eventId): int
    {
        return PromoterEnrollment::query()->where('event_id', $eventId)->where('status', 'active')->count();
    }
}
