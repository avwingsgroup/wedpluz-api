<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Phone numbers are stored in E.164 only (`+2348012345678`), so search and
 * uniqueness work. Values are normalised by {@see self::normalize()} in the
 * form requests before this rule runs.
 *
 * Beyond the E.164 shape, the national number length is checked for the
 * countries most Wedpluz users are in, which catches the common "+234 0803…"
 * mistake (trunk zero kept). Other countries get the E.164 shape check only.
 * Swap in libphonenumber here for full per-country validation.
 */
class PhoneNumber implements ValidationRule
{
    /** Country calling code => national significant number pattern (after the code). */
    private const NATIONAL = [
        '234' => '/^(?:[789][01]\d{8}|[1-9]\d{7})$/',   // Nigeria: mobile 0803 123 4567 -> 803..., landline 01 234 5678 -> 1234...
        '233' => '/^[235]\d{8}$/',                      // Ghana
        '254' => '/^[127]\d{8}$/',                      // Kenya
        '27' => '/^[1-8]\d{8}$/',                       // South Africa
        '44' => '/^(?:7\d{9}|[1-3]\d{8,9}|8\d{8,9})$/',  // United Kingdom
        '1' => '/^[2-9]\d{2}[2-9]\d{6}$/',             // US / Canada (NANP)
    ];

    public const MESSAGE = 'Enter a valid phone number with its country code, e.g. +2348012345678.';

    /** Strip formatting the user may type; `00` international prefix becomes `+`. */
    public static function normalize(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        $clean = preg_replace('/[\s\-\.\(\)\x{00A0}]/u', '', trim($value));
        if (str_starts_with($clean, '00')) {
            $clean = '+'.substr($clean, 2);
        }

        return $clean === '' ? null : $clean;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^\+[1-9]\d{7,14}$/', $value)) {
            $fail(self::MESSAGE);

            return;
        }
        $digits = substr($value, 1);
        foreach (self::NATIONAL as $code => $pattern) {
            if (str_starts_with($digits, (string) $code)) {
                if (! preg_match($pattern, substr($digits, strlen((string) $code)))) {
                    $fail(self::MESSAGE);
                }

                return;
            }
        }
    }
}
