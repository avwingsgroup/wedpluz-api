<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\MorphTo;

class Media extends Model
{
    protected $table = 'media';

    protected static string $idPrefix = 'med';

    protected function casts(): array
    {
        return [
            'waveform' => 'array',
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration_seconds' => 'integer',
        ];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
