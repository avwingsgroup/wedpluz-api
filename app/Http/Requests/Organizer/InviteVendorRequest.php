<?php

namespace App\Http\Requests\Organizer;

use App\Http\Requests\ApiRequest;

class InviteVendorRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'vendorId' => ['required', 'string', 'max:40'],
            'role' => ['nullable', 'string', 'max:120'],
        ];
    }
}
