<?php

namespace App\Http\Requests\Organizer;

use App\Http\Requests\ApiRequest;

class UpdateTaskRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'status' => ['sometimes', 'in:todo,in_progress,done'],
            'dueAt' => ['sometimes', 'nullable', 'date'],
            'assigneeIds' => ['sometimes', 'array', 'max:50'],
            'assigneeIds.*' => ['string', 'max:40'],
            'attachmentUploadIds' => ['sometimes', 'array', 'max:10'],
            'attachmentUploadIds.*' => ['string', 'max:40'],
        ];
    }
}
