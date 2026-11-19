<?php

namespace App\Http\Requests\Organizer;

use App\Http\Requests\ApiRequest;

class DecideRequestRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', 'in:approved,declined'],
        ];
    }
}
