<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class IdempotencyKey extends Eloquent
{
    protected $table = 'idempotency_keys';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'response_body' => 'array',
            'locked_until' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
