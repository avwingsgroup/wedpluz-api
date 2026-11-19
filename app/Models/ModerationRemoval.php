<?php

namespace App\Models;

class ModerationRemoval extends Model
{
    protected $table = 'moderation_removals';

    protected static string $idPrefix = 'mrem';

    protected function casts(): array
    {
        return [
            'restored_at' => 'immutable_datetime',
        ];
    }
}
