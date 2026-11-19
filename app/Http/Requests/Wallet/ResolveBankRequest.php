<?php

namespace App\Http\Requests\Wallet;

use App\Http\Requests\ApiRequest;

class ResolveBankRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'bankCode' => ['required', 'string', 'max:20'],
            'accountNumber' => ['required', 'string', 'regex:/^\d{10}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'accountNumber.regex' => 'Account numbers are 10 digits.',
        ];
    }
}
