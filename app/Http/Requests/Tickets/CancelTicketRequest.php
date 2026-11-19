<?php

namespace App\Http\Requests\Tickets;

use App\Http\Requests\ApiRequest;

class CancelTicketRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'in:other,no_longer_interested,bad_weather,technical_or_venue,wrong_event,scheduling_conflict,health_or_safety,personal'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
