<?php

namespace App\Http\Requests\Vendors;

use App\Http\Requests\ApiRequest;

class WorkRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:5000'],
            'mediaUploadIds' => ['required', 'array', 'min:1', 'max:20'],
            'mediaUploadIds.*' => ['string', 'max:40'],
        ];
    }
}
