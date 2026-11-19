<?php

namespace App\Services\Users;

use App\Models\NotificationPreference;
use App\Models\User;
use App\Models\UserPrivacySetting;
use App\Services\Notifications\NotificationService;

/** Creates a customer account with its default privacy and notification settings. */
class UserProvisioner
{
    /** @param array<string,mixed> $attributes snake_case user columns */
    public function create(array $attributes): User
    {
        $attributes['display_name'] ??= trim($attributes['first_name'].' '.$attributes['last_name']);

        $user = User::query()->create($attributes);

        UserPrivacySetting::query()->create(['user_id' => $user->id]);
        NotificationPreference::query()->create([
            'user_id' => $user->id,
            'channels' => NotificationService::DEFAULT_CHANNELS,
            'topics' => NotificationService::DEFAULT_TOPICS,
        ]);

        return $user->refresh();
    }
}
