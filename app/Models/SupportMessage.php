<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportMessage extends Model
{
    protected $table = 'support_messages';

    protected static string $idPrefix = 'spm';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    public function authorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function authorAdmin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'author_admin_id');
    }
}
