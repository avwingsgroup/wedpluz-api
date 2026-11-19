<?php

namespace App\Http\Requests\Account;

use App\Http\Requests\ApiRequest;

class NotificationPreferencesRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'channels' => ['sometimes', 'array'],
            'channels.push' => ['sometimes', 'boolean'],
            'channels.email' => ['sometimes', 'boolean'],
            'channels.sms' => ['sometimes', 'boolean'],
            'channels.whatsapp' => ['sometimes', 'boolean'],
            'channels.inApp' => ['sometimes', 'boolean'],
            'topics' => ['sometimes', 'array'],
            'topics.eventReminders' => ['sometimes', 'boolean'],
            'topics.ticketUpdates' => ['sometimes', 'boolean'],
            'topics.messages' => ['sometimes', 'boolean'],
            'topics.communityActivity' => ['sometimes', 'boolean'],
            'topics.promotions' => ['sometimes', 'boolean'],
            'topics.collaboration' => ['sometimes', 'boolean'],
            'topics.walletActivity' => ['sometimes', 'boolean'],
        ];
    }
}
