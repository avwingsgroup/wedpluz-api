<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class HallLayout extends Model
{
    use SoftDeletes;

    protected $table = 'hall_layouts';

    protected static string $idPrefix = 'hall';

    protected function casts(): array
    {
        return [
            'tables' => 'array',
            'fixtures' => 'array',
            'total_seats' => 'integer',
            'reserved_seats' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
