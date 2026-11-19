<?php

namespace App\Http\Requests\Subscriptions;

use App\Enums\PaymentMethod;
use App\Http\Requests\ApiRequest;

class SubscribeRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'planId' => ['required', 'string', 'max:40'],
            'interval' => ['required', 'in:monthly,yearly'],
            'paymentMethod' => ['required', PaymentMethod::rule()],
            'idempotencyKey' => ['sometimes', 'string', 'max:191'],
            'returnUrl' => ['sometimes', 'url', 'max:2048'],
        ];
    }
}
