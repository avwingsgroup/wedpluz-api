<?php

namespace App\Http\Requests\Account;

use App\Http\Requests\ApiRequest;
use App\Http\Requests\Rules;

class ChangePasswordRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'currentPassword' => ['required', 'string', 'max:128'],
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
