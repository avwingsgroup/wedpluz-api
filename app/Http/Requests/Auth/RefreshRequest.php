<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;

class RefreshRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'refreshToken' => ['required', 'string', 'max:255'],
        ];
    }
}
