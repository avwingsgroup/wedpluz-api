<?php

namespace App\Http\Resources;

use App\Models\AppNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AppNotification */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'actor' => Fmt::user($this->actor),
            'title' => $this->title,
            'body' => $this->body,
            'segments' => $this->segments ?? [],
            'action' => $this->action,
            'actionState' => $this->action_state,
            'data' => (object) array_map('strval', $this->data ?? []),
            'isRequest' => $this->is_request,
            'readAt' => Fmt::date($this->read_at),
            'createdAt' => Fmt::date($this->created_at),
            'link' => $this->link,
        ];
    }
}
