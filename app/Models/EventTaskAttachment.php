<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventTaskAttachment extends Model
{
    protected $table = 'event_task_attachments';

    protected static string $idPrefix = 'tatt';

    public function task(): BelongsTo
    {
        return $this->belongsTo(EventTask::class, 'task_id');
    }
}
