<?php

namespace App\Http\Requests\Wallet;

use App\Http\Requests\ApiRequest;

class TopUpRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'method' => ['required', 'in:paystack,monnify,flutterwave,card,bank_transfer'],
            'idempotencyKey' => ['sometimes', 'string', 'max:191'],
            'returnUrl' => ['required', 'url', 'max:2048'],
        ];
    }
}
