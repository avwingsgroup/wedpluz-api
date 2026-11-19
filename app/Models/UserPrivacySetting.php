<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class UserPrivacySetting extends Eloquent
{
    protected $table = 'user_privacy_settings';

    protected $guarded = [];

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'show_email' => 'boolean',
            'show_phone' => 'boolean',
            'allow_message_requests' => 'boolean',
            'allow_tagging' => 'boolean',
            'discoverable_by_search' => 'boolean',
        ];
    }
}
