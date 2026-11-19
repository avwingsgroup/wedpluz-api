<?php

namespace App\Http\Requests\Vendors;

use App\Http\Requests\ApiRequest;

class UpdateWorkRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'description' => ['sometimes', 'required', 'string', 'max:5000'],
            'mediaUploadIds' => ['sometimes', 'array', 'min:1', 'max:20'],
            'mediaUploadIds.*' => ['string', 'max:40'],
        ];
    }
}
