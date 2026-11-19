<?php

namespace App\Http\Resources;

use App\Models\AdminUser;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/** Shared formatting for API resources (camelCase, ISO-8601 UTC dates). */
final class Fmt
{
    public static function date(?DateTimeInterface $date): ?string
    {
        return $date === null ? null : CarbonImmutable::instance($date)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    public static function day(?DateTimeInterface $date): ?string
    {
        return $date?->format('Y-m-d');
    }

    /** UserSummary */
    public static function user(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }
        if ($user->anonymized_at !== null) {
            return ['id' => $user->id, 'displayName' => 'Deleted user', 'username' => null, 'avatarUrl' => null, 'isVerified' => false, 'isVendor' => false];
        }

        return [
            'id' => $user->id,
            'displayName' => $user->display_name,
            'username' => $user->username,
            'avatarUrl' => $user->avatar_url,
            'isVerified' => $user->identity_status?->value === 'verified',
            'isVendor' => (bool) $user->is_vendor,
        ];
    }

    /** UserSummary & { email } */
    public static function userWithEmail(?User $user): ?array
    {
        $summary = self::user($user);

        return $summary === null ? null : $summary + ['email' => $user->anonymized_at ? null : $user->email];
    }

    /** { id, firstName, lastName } admin reference */
    public static function admin(?AdminUser $admin): ?array
    {
        return $admin ? ['id' => $admin->id, 'firstName' => $admin->first_name, 'lastName' => $admin->last_name] : null;
    }
}
