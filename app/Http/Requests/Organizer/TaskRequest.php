<?php

namespace App\Http\Requests\Organizer;

use App\Http\Requests\ApiRequest;

class TaskRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['sometimes', 'in:todo,in_progress,done'],
            'dueAt' => ['nullable', 'date'],
            'assigneeIds' => ['sometimes', 'array', 'max:50'],
            'assigneeIds.*' => ['string', 'max:40'],
            'attachmentUploadIds' => ['sometimes', 'array', 'max:10'],
            'attachmentUploadIds.*' => ['string', 'max:40'],
        ];
    }
}
