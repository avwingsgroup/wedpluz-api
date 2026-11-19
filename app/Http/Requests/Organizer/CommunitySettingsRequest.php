<?php

namespace App\Http\Requests\Organizer;

use App\Http\Requests\ApiRequest;

class CommunitySettingsRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'permissions' => ['sometimes', 'array'],
            'permissions.membersCanSendMessages' => ['sometimes', 'boolean'],
            'permissions.membersCanPostStatus' => ['sometimes', 'boolean'],
            'permissions.membersCanInvite' => ['sometimes', 'boolean'],
            'permissions.membersCanGoLive' => ['sometimes', 'boolean'],
            'permissions.requireApprovalToJoin' => ['sometimes', 'boolean'],
        ];
    }
}
