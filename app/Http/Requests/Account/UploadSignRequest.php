<?php

namespace App\Http\Requests\Account;

use App\Enums\UploadPurpose;
use App\Http\Requests\ApiRequest;

class UploadSignRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'purpose' => ['required', UploadPurpose::rule()],
        ];
    }
}
