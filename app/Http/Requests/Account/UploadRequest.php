<?php

namespace App\Http\Requests\Account;

use App\Enums\UploadPurpose;
use App\Http\Requests\ApiRequest;

class UploadRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:51200'],
            'purpose' => ['required', UploadPurpose::rule()],
        ];
    }
}
