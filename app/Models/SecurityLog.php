<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityLog extends Model
{
    protected $table = 'security_logs';

    protected static string $idPrefix = 'sec';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }
}
