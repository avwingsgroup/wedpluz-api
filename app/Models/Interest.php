<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class Interest extends Eloquent
{
    protected $table = 'interests';

    protected $guarded = [];

    protected $primaryKey = 'slug';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
