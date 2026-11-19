<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;
use App\Http\Requests\Rules;

class ResetPasswordRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'code' => Rules::otp(),
            'password' => ['required', 'string', Rules::password()],
            'passwordConfirmation' => ['required', 'same:password'],
        ];
    }

    public function messages(): array
    {
        return [
            'passwordConfirmation.same' => 'The passwords do not match.',
        ];
    }
}
