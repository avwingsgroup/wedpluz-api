<?php

namespace App\Models;

use App\Support\Concerns\HasPrefixedId;
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * Sanctum token with a prefixed ULID key and session metadata (device, IP,
 * user agent) used by GET /me/sessions.
 */
class PersonalAccessToken extends SanctumToken
{
    use HasPrefixedId;

    protected static string $idPrefix = 'pat';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'abilities' => 'json',
            'last_used_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:sP';
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
