<?php

namespace App\Services\Finance;

use App\Models\CommissionRule;
use App\Models\Event;

/**
 * Commission precedence: organiser override, then category rule, then
 * platform rule. The resolved rule is snapshotted on each order at purchase
 * time, so later rule changes never apply retroactively.
 */
class CommissionResolver
{
    /** @return array{ruleId:?string, scope:string, percent:float, fixedFee:int} */
    public function forEvent(Event $event): array
    {
        $rules = CommissionRule::query()->where('is_active', true)->where('effective_from', '<=', now()->toDateString())
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('scope', 'organizer')->where('scope_id', $event->organizer_user_id))
                ->orWhere(fn ($q) => $q->where('scope', 'category')->where('scope_id', $event->category_id))
                ->orWhere('scope', 'platform'))
            ->get()->keyBy('scope');

        $rule = $rules->get('organizer') ?? $rules->get('category') ?? $rules->get('platform');

        return [
            'ruleId' => $rule?->id,
            'scope' => $rule?->scope ?? 'platform',
            'percent' => (float) ($rule?->percent ?? config('wedpluz.default_commission_percent', 5)),
            'fixedFee' => (int) ($rule?->fixed_fee ?? 0),
        ];
    }

    /** Commission for one order from its snapshot. */
    public static function commissionFor(int $ticketRevenue, ?array $snapshot): int
    {
        if ($ticketRevenue <= 0) {
            return 0;
        }
        $percent = (float) ($snapshot['percent'] ?? 0);
        $fixed = (int) ($snapshot['fixedFee'] ?? 0);

        return min($ticketRevenue, (int) round($ticketRevenue * $percent / 100) + $fixed);
    }
}
