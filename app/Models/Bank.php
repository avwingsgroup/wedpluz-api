<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class Bank extends Eloquent
{
    protected $table = 'banks';

    protected $guarded = [];

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'provider_codes' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
