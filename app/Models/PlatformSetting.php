<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class PlatformSetting extends Eloquent
{
    protected $table = 'platform_settings';

    protected $guarded = [];

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }
}
