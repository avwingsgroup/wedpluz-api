<?php

namespace App\Http\Requests\Wallet;

use App\Http\Requests\ApiRequest;

class PinRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'pin' => ['required', 'string', 'regex:/^\d{4}$/'],
            'pinConfirmation' => ['required', 'same:pin'],
            'otpCode' => ['nullable', 'string', 'regex:/^\d{4}$/'],
            'currentPin' => ['nullable', 'string', 'regex:/^\d{4}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'pin.regex' => 'Your PIN must be 4 digits.',
            'pinConfirmation.same' => 'The PINs do not match.',
        ];
    }
}
