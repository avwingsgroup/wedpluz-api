<?php

namespace App\Http\Requests\Vendors;

use App\Http\Requests\ApiRequest;

class ReviewRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }
}
