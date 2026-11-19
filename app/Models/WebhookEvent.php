<?php

namespace App\Models;

class WebhookEvent extends Model
{
    protected $table = 'webhook_events';

    protected static string $idPrefix = 'whk';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'signature_valid' => 'boolean',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
