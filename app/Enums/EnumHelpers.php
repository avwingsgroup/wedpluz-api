<?php

namespace App\Enums;

trait EnumHelpers
{
    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case) => $case->value, self::cases());
    }

    /** Comma-separated values for `in:` validation rules. */
    public static function rule(): string
    {
        return 'in:'.implode(',', self::values());
    }
}
