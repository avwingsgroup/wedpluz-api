<?php

namespace App\Http\Requests\Organizer;

use App\Http\Requests\ApiRequest;

class BulkDecideRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['string', 'max:40'],
            'status' => ['required', 'in:approved,declined'],
        ];
    }
}
