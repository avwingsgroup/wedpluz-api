<?php

namespace App\Support;

/** Money is integer minor units. All supported currencies use 2 decimals. */
final class Money
{
    public static function toMajor(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }

    public static function fromMajor(int|float|string|null $major): int
    {
        return (int) round(((float) $major) * 100);
    }

    public static function percentOf(int $amount, float $percent): int
    {
        return (int) round($amount * $percent / 100);
    }

    public static function format(int $minor, string $currency = 'NGN'): string
    {
        $symbol = ['NGN' => '₦', 'USD' => '$', 'GBP' => '£', 'EUR' => '€'][$currency] ?? $currency.' ';

        return $symbol.number_format($minor / 100, 2);
    }
}
