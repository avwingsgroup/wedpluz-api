<?php

namespace App\Http\Requests\Organizer;

use App\Http\Requests\ApiRequest;

class CollaborationResponseRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', 'in:accepted,declined'],
        ];
    }
}
