<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionRule extends Model
{
    protected $table = 'commission_rules';

    protected static string $idPrefix = 'cmr';

    protected function casts(): array
    {
        return [
            'percent' => 'float',
            'fixed_fee' => 'integer',
            'is_active' => 'boolean',
            'effective_from' => 'immutable_date',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'updated_by_admin_id');
    }
}
