<?php

namespace App\Support;

use App\Models\User;

/**
 * Rules for the demo dataset (database/seeders/DemoDataSeeder.php).
 *
 * Demo accounts are recognised by their email domain. Their balances, orders and
 * settlements are fabricated, so on a production server they may be used for
 * testing but no money may ever leave the platform from them, and nothing is
 * sent to their made-up email addresses or phone numbers.
 */
final class DemoAccounts
{
    public const DOMAIN = 'demo.wedpluz.test';

    public static function isDemoEmail(?string $email): bool
    {
        return $email !== null && str_ends_with(mb_strtolower(trim($email)), '@'.self::DOMAIN);
    }

    public static function isDemoUserId(?string $userId): bool
    {
        return $userId !== null && self::isDemoEmail(User::withTrashed()->whereKey($userId)->value('email'));
    }

    /** Outside production always; in production only with DEMO_DATA_IN_PRODUCTION=true. */
    public static function seedingAllowed(): bool
    {
        return ! app()->isProduction() || (bool) config('wedpluz.demo.allow_in_production');
    }

    /** In production, demo balances must never turn into real transfers. */
    public static function blocksMoneyOut(?string $email): bool
    {
        return app()->isProduction() && self::isDemoEmail($email);
    }

    public static function blocksMoneyOutForUserId(?string $userId): bool
    {
        return app()->isProduction() && self::isDemoUserId($userId);
    }
}
