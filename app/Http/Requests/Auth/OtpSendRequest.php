<?php

namespace App\Http\Requests\Auth;

use App\Enums\OtpPurpose;
use App\Http\Requests\ApiRequest;

class OtpSendRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'purpose' => ['required', OtpPurpose::rule()],
        ];
    }
}
