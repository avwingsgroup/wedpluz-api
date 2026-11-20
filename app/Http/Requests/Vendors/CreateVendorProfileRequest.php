<?php

namespace App\Http\Requests\Vendors;

use App\Http\Requests\ApiRequest;
use App\Http\Requests\Rules;

class CreateVendorProfileRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'businessName' => ['required', 'string', 'max:200'],
            'headline' => ['sometimes', 'nullable', 'string', 'max:80'],
            'location' => ['required', 'string', 'max:200'],
            'categoryId' => ['required', 'string', 'exists:vendor_categories,id'],
            'description' => ['required', 'string', 'max:5000'],
            'avatarUploadId' => ['nullable', 'string', 'max:40'],
            'coverUploadId' => ['nullable', 'string', 'max:40'],
            'contactEmail' => ['nullable', 'email', 'max:255'],
            'contactPhone' => ['nullable', 'string', Rules::phone()],
            'website' => ['nullable', 'url', 'max:512'],
            'socials' => ['sometimes', 'array'],
            'socials.*' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
        ];
    }
}
