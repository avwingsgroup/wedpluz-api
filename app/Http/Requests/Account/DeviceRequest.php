<?php

namespace App\Http\Requests\Account;

use App\Http\Requests\ApiRequest;

class DeviceRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['required', 'in:web,android,ios'],
        ];
    }
}
