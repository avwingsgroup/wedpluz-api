<?php

namespace App\Http\Requests\Organizer;

use App\Enums\ManagerPermission;
use App\Http\Requests\ApiRequest;

class InviteManagerRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'userId' => ['required_without:email', 'nullable', 'string', 'max:40'],
            'email' => ['required_without:userId', 'nullable', 'email', 'max:255'],
            'access' => ['required', 'in:full,partial'],
            'permissions' => ['present', 'array'],
            'permissions.*' => [ManagerPermission::rule()],
        ];
    }
}
