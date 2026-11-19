<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class IntegrationLog extends Eloquent
{
    protected $table = 'integration_logs';

    protected $guarded = [];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'request' => 'array',
            'response' => 'array',
            'success' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }
}
