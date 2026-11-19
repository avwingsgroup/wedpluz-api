<?php

namespace App\Http\Requests\Organizer;

use App\Http\Requests\ApiRequest;

class EventStatusRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', 'in:completed,cancelled'],
            'cancellationReason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
