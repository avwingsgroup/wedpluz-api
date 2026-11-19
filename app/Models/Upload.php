<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Upload extends Model
{
    protected $table = 'uploads';

    protected static string $idPrefix = 'upl';

    protected function casts(): array
    {
        return [
            'attached_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration_seconds' => 'integer',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
