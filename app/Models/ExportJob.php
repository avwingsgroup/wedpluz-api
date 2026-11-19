<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExportJob extends Model
{
    protected $table = 'export_jobs';

    protected static string $idPrefix = 'exp';

    protected function casts(): array
    {
        return [
            'query' => 'array',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }
}
