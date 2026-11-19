<?php

namespace App\Services\Users;

use App\Exceptions\ApiException;
use App\Http\Resources\Fmt;
use App\Models\DeviceToken;
use App\Models\Interest;
use App\Models\NotificationPreference;
use App\Models\PersonalAccessToken;
use App\Models\RefreshToken;
use App\Models\User;
use App\Models\UserPrivacySetting;
use App\Services\Auth\TokenService;
use App\Services\Auth\UserAgent;
use App\Services\Media\UploadService;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\DB;

/** Profile, onboarding, interests, privacy, notification preferences, sessions and devices. */
class ProfileService
{
    public function __construct(
        private readonly UploadService $uploads,
        private readonly TokenService $tokens,
    ) {}

    /** @param array<string,mixed> $data validated camelCase PATCH /me fields */
    public function update(User $user, array $data): User
    {
        $map = [
            'firstName' => 'first_name', 'lastName' => 'last_name', 'phone' => 'phone', 'dateOfBirth' => 'date_of_birth',
            'city' => 'city', 'countryCode' => 'country_code', 'avatarUrl' => 'avatar_url', 'username' => 'username', 'locale' => 'locale',
        ];
        $changes = [];
        foreach ($map as $key => $column) {
            if (array_key_exists($key, $data)) {
                $changes[$column] = $data[$key];
            }
        }
        if (isset($changes['username'])) {
            $changes['username'] = mb_strtolower($changes['username']);
            $this->ensureUsernameAvailable($changes['username'], $user->id);
        }
        if (isset($changes['country_code'])) {
            $changes['country_code'] = strtoupper($changes['country_code']);
        }
        if (array_key_exists('phone', $changes) && $changes['phone'] !== $user->phone) {
            $changes['phone_verified_at'] = null;
        }
        if (isset($changes['first_name']) || isset($changes['last_name'])) {
            $changes['display_name'] = trim(($changes['first_name'] ?? $user->first_name).' '.($changes['last_name'] ?? $user->last_name));
        }

        $user->forceFill($changes)->save();

        return $user->refresh();
    }

    /** @param array{username:string, phone?:string, city?:string, countryCode?:string, avatarUploadId?:string} $data */
    public function completeProfileSetup(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            $username = mb_strtolower($data['username']);
            $this->ensureUsernameAvailable($username, $user->id);

            $changes = [
                'username' => $username,
                'profile_completed' => true,
            ];
            foreach (['phone' => 'phone', 'city' => 'city'] as $key => $column) {
                if (! empty($data[$key])) {
                    $changes[$column] = $data[$key];
                }
            }
            if (! empty($data['countryCode'])) {
                $changes['country_code'] = strtoupper($data['countryCode']);
            }
            if (! empty($data['avatarUploadId'])) {
                $changes['avatar_url'] = $this->uploads->consume($data['avatarUploadId'], $user, 'avatar', 'avatarUploadId')->url;
            }
            $user->forceFill($changes)->save();

            return $user->refresh();
        });
    }

    /** @param list<string> $slugs */
    public function replaceInterests(User $user, array $slugs): User
    {
        $slugs = array_values(array_unique($slugs));
        $valid = Interest::query()->whereIn('slug', $slugs)->where('is_active', true)->pluck('slug')->all();
        if (count($valid) !== count($slugs)) {
            throw ApiException::validation('Some interests are not valid.', ['interests' => ['Select interests from the list.']]);
        }

        DB::transaction(function () use ($user, $valid) {
            DB::table('user_interests')->where('user_id', $user->id)->delete();
            DB::table('user_interests')->insert(array_map(fn ($s) => ['user_id' => $user->id, 'interest_slug' => $s], $valid));
            $user->forceFill(['interests_completed' => true])->save();
        });

        return $user->refresh();
    }

    public function privacy(User $user): UserPrivacySetting
    {
        return UserPrivacySetting::query()->firstOrCreate(['user_id' => $user->id]);
    }

    /** @param array<string,mixed> $data */
    public function updatePrivacy(User $user, array $data): UserPrivacySetting
    {
        $map = [
            'profileVisibility' => 'profile_visibility', 'showEmail' => 'show_email', 'showPhone' => 'show_phone',
            'allowMessageRequests' => 'allow_message_requests', 'allowTagging' => 'allow_tagging', 'discoverableBySearch' => 'discoverable_by_search',
        ];
        $privacy = $this->privacy($user);
        foreach ($map as $key => $column) {
            if (array_key_exists($key, $data)) {
                $privacy->{$column} = $data[$key];
            }
        }
        $privacy->save();

        return $privacy;
    }

    public function notificationPreferences(User $user): NotificationPreference
    {
        return NotificationPreference::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['channels' => NotificationService::DEFAULT_CHANNELS, 'topics' => NotificationService::DEFAULT_TOPICS],
        );
    }

    /** @param array{channels?:array<string,bool>, topics?:array<string,bool>} $data */
    public function updateNotificationPreferences(User $user, array $data): NotificationPreference
    {
        $pref = $this->notificationPreferences($user);
        if (isset($data['channels'])) {
            $pref->channels = array_replace(NotificationService::DEFAULT_CHANNELS, $pref->channels ?? [], array_intersect_key(array_map('boolval', $data['channels']), NotificationService::DEFAULT_CHANNELS));
        }
        if (isset($data['topics'])) {
            $pref->topics = array_replace(NotificationService::DEFAULT_TOPICS, $pref->topics ?? [], array_intersect_key(array_map('boolval', $data['topics']), NotificationService::DEFAULT_TOPICS));
        }
        $pref->save();

        return $pref;
    }

    /** Sessions are refresh-token families. @return list<array> */
    public function sessions(User $user): array
    {
        $current = $user->currentAccessToken();
        $currentFamily = $current instanceof PersonalAccessToken
            ? RefreshToken::query()->where('access_token_id', $current->id)->value('family_id')
            : null;

        $active = RefreshToken::query()
            ->where('user_id', $user->id)->whereNull('revoked_at')->where('expires_at', '>', now())
            ->orderByDesc('last_used_at')->get();

        $lastUsed = PersonalAccessToken::query()->whereIn('id', $active->pluck('access_token_id')->filter())->pluck('last_used_at', 'id');

        return $active->unique('family_id')->map(function (RefreshToken $t) use ($currentFamily, $lastUsed) {
            $ua = UserAgent::describe($t->user_agent);
            $tokenUsed = $lastUsed[$t->access_token_id] ?? null;
            $lastActive = $tokenUsed && $tokenUsed > $t->last_used_at ? $tokenUsed : $t->last_used_at;

            return [
                'id' => $t->family_id,
                'device' => $t->device_name ?: $ua['device'],
                'browser' => $ua['browser'],
                'ip' => (string) $t->ip_address,
                'location' => null,
                'lastActiveAt' => Fmt::date($lastActive ?? $t->created_at),
                'isCurrent' => $t->family_id === $currentFamily,
            ];
        })->values()->all();
    }

    public function revokeSession(User $user, string $sessionId): void
    {
        $exists = RefreshToken::query()->where('user_id', $user->id)->where('family_id', $sessionId)->exists();
        if (! $exists) {
            throw ApiException::notFound('Session not found.');
        }
        $this->tokens->revokeFamily($sessionId);
    }

    public function revokeOtherSessions(User $user): void
    {
        $this->tokens->revokeAllForUser($user, keepCurrent: true);
    }

    public function registerDevice(User $user, string $token, string $platform): void
    {
        DeviceToken::query()->updateOrCreate(['token' => $token], ['user_id' => $user->id, 'platform' => $platform, 'last_seen_at' => now()]);
    }

    private function ensureUsernameAvailable(string $username, string $userId): void
    {
        $taken = User::query()->withTrashed()->whereRaw('lower(username) = ?', [$username])->where('id', '!=', $userId)->exists();
        if ($taken) {
            throw ApiException::validation('That username is taken.', ['username' => ['This username is already taken.']]);
        }
    }
}
