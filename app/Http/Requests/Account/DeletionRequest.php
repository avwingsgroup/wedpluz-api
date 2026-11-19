<?php

namespace App\Http\Requests\Account;

use App\Http\Requests\ApiRequest;

class DeletionRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'password' => ['required', 'string', 'max:128'],
        ];
    }
}
