<?php

namespace App\Http\Requests\Wallet;

use App\Http\Requests\ApiRequest;
use App\Http\Requests\Rules;

class ActivateWalletRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'otpCode' => Rules::otp(),
        ];
    }
}
