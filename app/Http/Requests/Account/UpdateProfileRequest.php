<?php

namespace App\Http\Requests\Account;

use App\Http\Requests\ApiRequest;
use App\Http\Requests\Rules;

class UpdateProfileRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'firstName' => ['sometimes', 'required', 'string', 'max:100'],
            'lastName' => ['sometimes', 'required', 'string', 'max:100'],
            'phone' => ['sometimes', 'nullable', 'string', Rules::phone()],
            'dateOfBirth' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.now()->subYears(13)->toDateString()],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'countryCode' => ['sometimes', 'nullable', 'string', 'size:2', 'alpha'],
            'avatarUrl' => ['sometimes', 'nullable', 'url:https', 'max:1024'],
            'username' => ['sometimes', 'required', ...Rules::username()],
            'locale' => ['sometimes', 'required', 'string', 'in:en,fr,yo,ig,ha'],
        ];
    }

    public function messages(): array
    {
        return [
            'username.regex' => 'Usernames may only contain lowercase letters, numbers, underscores and dots.',
        ];
    }
}
