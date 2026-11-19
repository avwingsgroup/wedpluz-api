<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class NotificationPreference extends Eloquent
{
    protected $table = 'notification_preferences';

    protected $guarded = [];

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'topics' => 'array',
        ];
    }
}
