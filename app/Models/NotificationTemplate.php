<?php

namespace App\Models;

class NotificationTemplate extends Model
{
    protected $table = 'notification_templates';

    protected static string $idPrefix = 'tpl';

    protected function casts(): array
    {
        return [
            'variables' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
