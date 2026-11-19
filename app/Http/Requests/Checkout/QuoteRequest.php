<?php

namespace App\Http\Requests\Checkout;

use App\Http\Requests\ApiRequest;

class QuoteRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'eventId' => ['required', 'string', 'max:40'],
            'lines' => ['required', 'array', 'min:1', 'max:20'],
            'lines.*.ticketTypeId' => ['required', 'string', 'max:40'],
            'lines.*.quantity' => ['required', 'integer', 'min:0', 'max:100'],
            'foodLines' => ['sometimes', 'array', 'max:50'],
            'foodLines.*.menuItemId' => ['required', 'string', 'max:80'],
            'foodLines.*.quantity' => ['required', 'integer', 'min:0', 'max:50'],
            'promoCode' => ['nullable', 'string', 'max:40'],
            'referralCode' => ['nullable', 'string', 'max:20'],
        ];
    }
}
