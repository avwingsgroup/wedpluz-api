<?php

namespace App\Http\Requests\Organizer;

use App\Http\Requests\ApiRequest;

class PromotionRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            'commissionPercent' => ['sometimes', 'numeric', 'min:1', 'max:50'],
            'targetSignups' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'promoterLimit' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'promotersCount' => ['sometimes'],
        ];
    }
}
