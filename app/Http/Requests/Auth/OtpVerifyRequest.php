<?php

namespace App\Http\Requests\Auth;

use App\Enums\OtpPurpose;
use App\Http\Requests\ApiRequest;
use App\Http\Requests\Rules;

class OtpVerifyRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'code' => Rules::otp(),
            'purpose' => ['required', OtpPurpose::rule()],
        ];
    }
}
