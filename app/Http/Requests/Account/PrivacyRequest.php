<?php

namespace App\Http\Requests\Account;

use App\Http\Requests\ApiRequest;

class PrivacyRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'profileVisibility' => ['sometimes', 'in:public,connections,private'],
            'showEmail' => ['sometimes', 'boolean'],
            'showPhone' => ['sometimes', 'boolean'],
            'allowMessageRequests' => ['sometimes', 'boolean'],
            'allowTagging' => ['sometimes', 'boolean'],
            'discoverableBySearch' => ['sometimes', 'boolean'],
        ];
    }
}
