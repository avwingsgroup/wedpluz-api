<?php

namespace App\Http\Requests\Checkout;

use App\Enums\PaymentMethod;
use App\Http\Requests\ApiRequest;

class CreateOrderRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'quoteId' => ['required', 'string', 'max:40'],
            'attendee' => ['required', 'array'],
            'attendee.firstName' => ['required', 'string', 'max:100'],
            'attendee.lastName' => ['required', 'string', 'max:100'],
            'attendee.email' => ['required', 'email', 'max:255'],
            'attendee.phone' => ['nullable', 'string', 'max:20'],
            'additionalGuests' => ['sometimes', 'array', 'max:20'],
            'additionalGuests.*.firstName' => ['required', 'string', 'max:100'],
            'additionalGuests.*.lastName' => ['required', 'string', 'max:100'],
            'additionalGuests.*.email' => ['nullable', 'email', 'max:255'],
            'seats' => ['sometimes', 'array', 'max:100'],
            'seats.*.scheduleId' => ['required', 'string', 'max:40'],
            'seats.*.seatId' => ['required', 'string', 'max:80'],
            'seats.*.tableLabel' => ['nullable', 'string', 'max:60'],
            'seats.*.seatLabel' => ['nullable', 'string', 'max:60'],
            'paymentMethod' => ['required', PaymentMethod::rule()],
            'idempotencyKey' => ['sometimes', 'string', 'max:191'],
            'returnUrl' => ['required', 'url', 'max:2048'],
        ];
    }
}
