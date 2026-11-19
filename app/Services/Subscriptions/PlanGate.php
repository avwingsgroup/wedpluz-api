<?php

namespace App\Services\Subscriptions;

use App\Exceptions\ApiException;
use App\Models\User;

/**
 * Server-side feature gating by plan tier (business-rules.md, "Subscriptions").
 * Higher tiers include lower-tier features. Premium-only actions return
 * 402 PAYMENT_REQUIRED.
 */
class PlanGate
{
    public const TIERS = ['free' => 0, 'basic' => 1, 'premium' => 2, 'premium_plus' => 3];

    /** feature => minimum tier (designs: sub-events show a "Go Pro" upgrade prompt). */
    public const FEATURES = [
        'sub_events' => 'premium',
        'planner_ai' => 'premium',
        'planner_generation' => 'premium',
        'advanced_analytics' => 'basic',
    ];

    public function allows(User $user, string $feature): bool
    {
        $required = config('wedpluz.plan_features.'.$feature, self::FEATURES[$feature] ?? 'free');

        return (self::TIERS[$user->plan] ?? 0) >= (self::TIERS[$required] ?? 0);
    }

    public function require(User $user, string $feature, ?string $message = null): void
    {
        if (! $this->allows($user, $feature)) {
            $tier = config('wedpluz.plan_features.'.$feature, self::FEATURES[$feature] ?? 'premium');
            throw ApiException::paymentRequired($message ?? 'Upgrade to '.str_replace('_', ' ', ucfirst($tier)).' to use this feature.');
        }
    }
}
