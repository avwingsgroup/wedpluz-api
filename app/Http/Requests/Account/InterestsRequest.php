<?php

namespace App\Http\Requests\Account;

use App\Http\Requests\ApiRequest;

class InterestsRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'interests' => ['required', 'array', 'min:1', 'max:10'],
            'interests.*' => ['required', 'string', 'max:60'],
        ];
    }
}
