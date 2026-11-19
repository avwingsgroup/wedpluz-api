<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;

class SocialAuthRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'provider' => ['required', 'in:google'],
            'idToken' => ['required', 'string', 'max:4096'],
            'deviceName' => ['nullable', 'string', 'max:255'],
        ];
    }
}
