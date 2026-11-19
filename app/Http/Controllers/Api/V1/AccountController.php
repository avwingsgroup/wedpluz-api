<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ChangePasswordRequest;
use App\Http\Requests\Account\DeletionRequest;
use App\Http\Requests\Account\DeviceRequest;
use App\Http\Requests\Account\IdentityVerificationRequest;
use App\Http\Requests\Account\InterestsRequest;
use App\Http\Requests\Account\NotificationPreferencesRequest;
use App\Http\Requests\Account\PrivacyRequest;
use App\Http\Requests\Account\ProfileSetupRequest;
use App\Http\Requests\Account\UpdateProfileRequest;
use App\Http\Resources\Fmt;
use App\Http\Resources\UserResource;
use App\Models\AccountDeletionRequest;
use App\Models\NotificationPreference;
use App\Models\UserPrivacySetting;
use App\Services\Auth\AuthService;
use App\Services\Notifications\NotificationService;
use App\Services\Users\AccountDeletionService;
use App\Services\Users\IdentityVerificationService;
use App\Services\Users\ProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/** /me/* — the signed-in customer's profile, settings, sessions, identity and deletion. */
class AccountController extends Controller
{
    public function __construct(private readonly ProfileService $profiles) {}

    public function show(): JsonResponse
    {
        return $this->ok(new UserResource($this->customer()));
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        return $this->ok(new UserResource($this->profiles->update($this->customer(), $request->validated())), 'Profile updated.');
    }

    public function profileSetup(ProfileSetupRequest $request): JsonResponse
    {
        return $this->ok(new UserResource($this->profiles->completeProfileSetup($this->customer(), $request->validated())));
    }

    public function interests(InterestsRequest $request): JsonResponse
    {
        return $this->ok(new UserResource($this->profiles->replaceInterests($this->customer(), $request->validated('interests'))));
    }

    public function password(ChangePasswordRequest $request, AuthService $auth): JsonResponse
    {
        $auth->changePassword($this->customer(), (string) $request->input('currentPassword'), (string) $request->input('password'));

        return $this->ok(['updated' => true], 'Password updated. Your other sessions were signed out.');
    }

    public function privacy(): JsonResponse
    {
        return $this->ok(self::privacyResource($this->profiles->privacy($this->customer())));
    }

    public function updatePrivacy(PrivacyRequest $request): JsonResponse
    {
        return $this->ok(self::privacyResource($this->profiles->updatePrivacy($this->customer(), $request->validated())), 'Privacy settings saved.');
    }

    public function notificationPreferences(): JsonResponse
    {
        return $this->ok(self::preferencesResource($this->profiles->notificationPreferences($this->customer())));
    }

    public function updateNotificationPreferences(NotificationPreferencesRequest $request): JsonResponse
    {
        return $this->ok(self::preferencesResource($this->profiles->updateNotificationPreferences($this->customer(), $request->validated())), 'Notification preferences saved.');
    }

    public function sessions(): JsonResponse
    {
        return $this->ok($this->profiles->sessions($this->customer()));
    }

    public function revokeSession(string $id): Response
    {
        $this->profiles->revokeSession($this->customer(), $id);

        return $this->noContent();
    }

    public function revokeOtherSessions(): Response
    {
        $this->profiles->revokeOtherSessions($this->customer());

        return $this->noContent();
    }

    public function identity(IdentityVerificationService $identity): JsonResponse
    {
        return $this->ok(IdentityVerificationService::resource($identity->latest($this->customer())));
    }

    public function submitIdentity(IdentityVerificationRequest $request, IdentityVerificationService $identity): JsonResponse
    {
        $verification = $identity->submit($this->customer(), $request->string('idType'), $request->string('idNumber'), $request->string('selfieUploadId'));

        return $this->created(IdentityVerificationService::resource($verification), 'Verification submitted. We will notify you when it completes.');
    }

    public function deletionRequest(AccountDeletionService $deletion): JsonResponse
    {
        return $this->ok(self::deletionResource($deletion->pending($this->customer())));
    }

    public function requestDeletion(DeletionRequest $request, AccountDeletionService $deletion): JsonResponse
    {
        $result = $deletion->request($this->customer(), $request->string('reason'), (string) $request->input('password'));

        return $this->created(self::deletionResource($result), 'Your account is scheduled for deletion.');
    }

    public function cancelDeletion(AccountDeletionService $deletion): Response
    {
        $deletion->cancel($this->customer());

        return $this->noContent();
    }

    public function registerDevice(DeviceRequest $request): JsonResponse
    {
        $this->profiles->registerDevice($this->customer(), $request->string('token'), $request->string('platform'));

        return $this->created(['registered' => true]);
    }

    public static function privacyResource(UserPrivacySetting $p): array
    {
        return [
            'profileVisibility' => $p->profile_visibility ?? 'public',
            'showEmail' => (bool) $p->show_email,
            'showPhone' => (bool) $p->show_phone,
            'allowMessageRequests' => (bool) ($p->allow_message_requests ?? true),
            'allowTagging' => (bool) ($p->allow_tagging ?? true),
            'discoverableBySearch' => (bool) ($p->discoverable_by_search ?? true),
        ];
    }

    public static function preferencesResource(NotificationPreference $p): array
    {
        return [
            'channels' => array_map('boolval', array_replace(NotificationService::DEFAULT_CHANNELS, $p->channels ?? [])),
            'topics' => array_map('boolval', array_replace(NotificationService::DEFAULT_TOPICS, $p->topics ?? [])),
        ];
    }

    public static function deletionResource(?AccountDeletionRequest $r): ?array
    {
        return $r === null ? null : [
            'id' => $r->id,
            'reason' => $r->reason,
            'status' => $r->status,
            'requestedAt' => Fmt::date($r->requested_at),
            'scheduledFor' => Fmt::date($r->scheduled_for),
        ];
    }
}
