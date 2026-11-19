<?php

namespace App\Http\Requests\Account;

use App\Http\Requests\ApiRequest;

class SupportTicketRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:200'],
            'category' => ['required', 'in:payment,ticket,account,event,vendor,bug,other'],
            'message' => ['required', 'string', 'max:5000'],
        ];
    }
}
