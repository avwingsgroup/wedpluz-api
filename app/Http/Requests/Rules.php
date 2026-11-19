<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rules\Password;

/** Shared validation rules. */
final class Rules
{
    /** >= 8 chars with a letter and a number; breached-password check in production. */
    public static function password(): Password
    {
        $rule = Password::min(8)->max(128)->letters()->numbers();

        return app()->isProduction() && config('wedpluz.auth.check_breached_passwords', true) ? $rule->uncompromised() : $rule;
    }

    public static function phone(): string
    {
        return 'regex:/^\+[1-9]\d{7,14}$/';
    }

    public static function username(): array
    {
        return ['string', 'min:3', 'max:30', 'regex:/^[a-z0-9_.]+$/'];
    }

    public static function otp(): array
    {
        return ['required', 'string', 'regex:/^\d{4}$/'];
    }
}
