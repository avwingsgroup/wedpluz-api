<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentReport extends Model
{
    protected $table = 'content_reports';

    protected static string $idPrefix = 'rpt';

    protected function casts(): array
    {
        return [
            'target_preview' => 'array',
            'resolution' => 'array',
        ];
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    public function reportedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_user_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'assigned_admin_id');
    }
}
