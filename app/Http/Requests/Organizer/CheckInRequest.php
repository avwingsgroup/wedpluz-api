<?php

namespace App\Http\Requests\Organizer;

use App\Http\Requests\ApiRequest;

class CheckInRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:512'],
            'method' => ['required', 'in:scan,manual'],
            'device' => ['nullable', 'string', 'max:255'],
        ];
    }
}
