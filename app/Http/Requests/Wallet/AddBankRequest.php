<?php

namespace App\Http\Requests\Wallet;

use App\Http\Requests\ApiRequest;

class AddBankRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'bankCode' => ['required', 'string', 'max:20'],
            'accountNumber' => ['required', 'string', 'regex:/^\d{10}$/'],
            'accountName' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'accountNumber.regex' => 'Account numbers are 10 digits.',
        ];
    }
}
