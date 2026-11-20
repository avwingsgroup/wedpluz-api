<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;

class SocialAuthRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'provider' => ['required', 'in:google'],
            'idToken' => ['required_without:code', 'nullable', 'string', 'max:4096'],
            // Redirect flow: the `code` and `state` Google appended to the web callback URL.
            'code' => ['required_without:idToken', 'nullable', 'string', 'max:2048'],
            'state' => ['required_with:code', 'nullable', 'string', 'max:128'],
            'deviceName' => ['nullable', 'string', 'max:255'],
        ];
    }
}
