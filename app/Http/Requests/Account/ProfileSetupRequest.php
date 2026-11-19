<?php

namespace App\Http\Requests\Account;

use App\Http\Requests\ApiRequest;
use App\Http\Requests\Rules;

class ProfileSetupRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'username' => ['required', ...Rules::username()],
            'phone' => ['nullable', 'string', Rules::phone()],
            'city' => ['nullable', 'string', 'max:120'],
            'countryCode' => ['nullable', 'string', 'size:2', 'alpha'],
            'avatarUploadId' => ['nullable', 'string', 'max:40'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter the phone number in international format, e.g. +2348012345678.',
            'username.regex' => 'Usernames may only contain lowercase letters, numbers, underscores and dots.',
        ];
    }
}
