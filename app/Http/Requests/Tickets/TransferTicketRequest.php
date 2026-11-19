<?php

namespace App\Http\Requests\Tickets;

use App\Http\Requests\ApiRequest;

class TransferTicketRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'recipientEmail' => ['required', 'email', 'max:255'],
            'message' => ['nullable', 'string', 'max:500'],
        ];
    }
}
