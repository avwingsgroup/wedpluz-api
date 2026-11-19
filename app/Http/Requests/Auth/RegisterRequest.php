<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;
use App\Http\Requests\Rules;
use App\Models\User;
use Closure;

class RegisterRequest extends ApiRequest
{
    public function rules(): array
    {
        $minAge = (int) config('wedpluz.auth.minimum_age', 13);

        return [
            'firstName' => ['required', 'string', 'max:100'],
            'lastName' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', function (string $attr, mixed $value, Closure $fail) {
                if (User::query()->whereRaw('lower(email) = ?', [mb_strtolower((string) $value)])->exists()) {
                    $fail('An account with this email already exists.');
                }
            }],
            'dateOfBirth' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now()->subYears($minAge)->toDateString(), 'after:1900-01-01'],
            'password' => ['required', 'string', Rules::password()],
            'passwordConfirmation' => ['required', 'same:password'],
            'acceptedTerms' => ['required', 'accepted'],
            'deviceName' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'dateOfBirth.before_or_equal' => 'You must be at least '.config('wedpluz.auth.minimum_age', 13).' years old.',
            'passwordConfirmation.same' => 'The passwords do not match.',
            'acceptedTerms.accepted' => 'You must accept the terms to continue.',
        ];
    }
}
