<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PlannerMenu extends Model
{
    use SoftDeletes;

    protected $table = 'planner_menus';

    protected static string $idPrefix = 'pmenu';

    protected function casts(): array
    {
        return [
            'sections' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
