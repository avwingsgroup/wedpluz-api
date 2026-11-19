<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PlannerGeneration extends Model
{
    use SoftDeletes;

    protected $table = 'planner_generations';

    protected static string $idPrefix = 'pgen';

    protected function casts(): array
    {
        return [
            'result_urls' => 'array',
            'cost_units' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
