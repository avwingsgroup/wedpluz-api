<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Human-facing references and codes. Uses an unambiguous alphabet (no 0/O,
 * 1/I/L) and CSPRNG randomness; uniqueness is checked against the table.
 */
final class Codes
{
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public static function random(int $length): string
    {
        $out = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }

        return $out;
    }

    /** e.g. WP-7K3M9QX2TA */
    public static function reference(string $prefix, string $table, string $column = 'reference'): string
    {
        do {
            $ref = $prefix.'-'.self::random(10);
        } while (DB::table($table)->where($column, $ref)->exists());

        return $ref;
    }

    /** Ticket short code for manual entry, e.g. DT78-216-M0 style: XXXX-XXX-XX. */
    public static function ticketCode(): string
    {
        do {
            $code = self::random(4).'-'.self::random(3).'-'.self::random(2);
        } while (DB::table('tickets')->where('code', $code)->exists());

        return $code;
    }

    public static function unique(string $table, string $column, int $length): string
    {
        do {
            $code = self::random($length);
        } while (DB::table($table)->where($column, $code)->exists());

        return $code;
    }

    /** Numeric OTP of the configured length. */
    public static function otp(int $length = 4): string
    {
        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }
}
