<?php

namespace App\Http\Requests\Account;

use App\Http\Requests\ApiRequest;

class IdentityVerificationRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'idType' => ['required', 'in:nin,bvn,passport,drivers_license,resident_card'],
            'idNumber' => ['required', 'string', 'max:30'],
            'selfieUploadId' => ['required', 'string', 'max:40'],
        ];
    }
}
