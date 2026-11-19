<?php

namespace App\Http\Requests\Wallet;

use App\Http\Requests\ApiRequest;

class WithdrawRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1'],
            'bankAccountId' => ['required', 'string', 'max:40'],
            'pin' => ['required', 'string', 'regex:/^\d{4}$/'],
            'idempotencyKey' => ['sometimes', 'string', 'max:191'],
        ];
    }
}
