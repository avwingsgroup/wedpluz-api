<?php

namespace App\Http\Requests\Checkout;

use App\Http\Requests\ApiRequest;

class VerifyPaymentRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'reference' => ['nullable', 'string', 'max:191'],
            'simulate' => ['nullable', 'in:success,failed'],
        ];
    }
}
