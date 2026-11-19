<?php

namespace App\Models;

class SystemAlert extends Model
{
    protected $table = 'system_alerts';

    protected static string $idPrefix = 'alr';

    protected function casts(): array
    {
        return [
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
