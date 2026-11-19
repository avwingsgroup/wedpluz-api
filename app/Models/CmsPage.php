<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CmsPage extends Model
{
    protected $table = 'cms_pages';

    protected static string $idPrefix = 'cms';

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'updated_by_admin_id');
    }
}
