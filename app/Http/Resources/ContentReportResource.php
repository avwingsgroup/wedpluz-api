<?php

namespace App\Http\Resources;

use App\Models\ContentReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ContentReport */
class ContentReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $preview = $this->target_preview ?? [];

        return [
            'id' => $this->id,
            'targetType' => $this->target_type,
            'targetId' => $this->target_id,
            'targetPreview' => [
                'title' => (string) ($preview['title'] ?? $this->target_type),
                'excerpt' => $preview['excerpt'] ?? null,
                'imageUrl' => $preview['imageUrl'] ?? null,
                'link' => $preview['link'] ?? null,
            ],
            'reporter' => Fmt::user($this->reporter),
            'reportedUser' => Fmt::user($this->reportedUser),
            'reason' => $this->reason,
            'details' => $this->details,
            'status' => $this->status,
            'priority' => $this->priority,
            'assignedTo' => Fmt::admin($this->assignee),
            'resolution' => $this->resolution,
            'createdAt' => Fmt::date($this->created_at),
        ];
    }
}
