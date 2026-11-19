<?php

namespace App\Http\Requests\Vendors;

use App\Http\Requests\ApiRequest;
use App\Http\Requests\Rules;

class UpdateVendorProfileRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'businessName' => ['sometimes', 'required', 'string', 'max:200'],
            'location' => ['sometimes', 'required', 'string', 'max:200'],
            'categoryId' => ['sometimes', 'required', 'string', 'exists:vendor_categories,id'],
            'description' => ['sometimes', 'required', 'string', 'max:5000'],
            'avatarUploadId' => ['sometimes', 'nullable', 'string', 'max:40'],
            'coverUploadId' => ['sometimes', 'nullable', 'string', 'max:40'],
            'contactEmail' => ['sometimes', 'nullable', 'email', 'max:255'],
            'contactPhone' => ['sometimes', 'nullable', 'string', Rules::phone()],
            'website' => ['sometimes', 'nullable', 'url', 'max:512'],
            'availability' => ['sometimes', 'in:available,busy,unavailable'],
            'socials' => ['sometimes', 'array'],
            'socials.*' => ['nullable', 'string', 'max:255'],
        ];
    }
}
