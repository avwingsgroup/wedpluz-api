<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class OtpCode extends Eloquent
{
    protected $table = 'otp_codes';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
        ];
    }
}
