<?php

namespace App\Http\Requests\Account;

use App\Enums\ReportTargetType;
use App\Http\Requests\ApiRequest;

class ReportRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'targetType' => ['required', ReportTargetType::rule()],
            'targetId' => ['required', 'string', 'max:40'],
            'reason' => ['required', 'in:spam,harassment,hate_speech,nudity,violence,scam_or_fraud,impersonation,false_information,intellectual_property,other'],
            'details' => ['nullable', 'string', 'max:2000'],
            'blockUser' => ['sometimes', 'boolean'],
        ];
    }
}
