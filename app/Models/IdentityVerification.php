<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdentityVerification extends Model
{
    protected $table = 'identity_verifications';

    protected static string $idPrefix = 'idv';

    protected function casts(): array
    {
        return [
            'id_number_encrypted' => 'encrypted',
            'submitted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'media_purged_at' => 'immutable_datetime',
            'match_score' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function selfie(): BelongsTo
    {
        return $this->belongsTo(Upload::class, 'selfie_upload_id');
    }
}
