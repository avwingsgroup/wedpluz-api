<?php

namespace App\Http\Requests\Vendors;

use App\Http\Requests\ApiRequest;

class PromoRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:1000'],
            'discountPercent' => ['nullable', 'numeric', 'min:1', 'max:100'],
            'validUntil' => ['nullable', 'date', 'after:now'],
        ];
    }
}
