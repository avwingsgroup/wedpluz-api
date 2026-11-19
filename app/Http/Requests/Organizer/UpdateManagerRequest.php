<?php

namespace App\Http\Requests\Organizer;

use App\Enums\ManagerPermission;
use App\Http\Requests\ApiRequest;

class UpdateManagerRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'access' => ['sometimes', 'in:full,partial'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => [ManagerPermission::rule()],
        ];
    }
}
