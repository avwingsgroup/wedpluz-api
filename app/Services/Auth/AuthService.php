<?php

namespace App\Services\Auth;

use App\Enums\OtpPurpose;
use App\Exceptions\ApiException;
use App\Http\Middleware\RequestContext;
use App\Http\Resources\UserResource;
use App\Integrations\Google\GoogleIdTokenVerifier;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\Users\UserProvisioner;
use App\Support\Platform;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** Customer registration, login, Google sign-in, logout and password recovery. */
class AuthService
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly OtpService $otp,
        private readonly UserProvisioner $provisioner,
        private readonly GoogleIdTokenVerifier $google,
        private readonly NotificationService $notifications,
    ) {}

    /** @param array{firstName:string,lastName:string,email:string,dateOfBirth:string,password:string} $data */
    public function register(array $data): array
    {
        $user = DB::transaction(fn () => $this->provisioner->create([
            'first_name' => $data['firstName'],
            'last_name' => $data['lastName'],
            'email' => mb_strtolower($data['email']),
            'date_of_birth' => $data['dateOfBirth'],
            'password' => $data['password'],
            'signup_source' => RequestContext::source(),
        ]));

        $this->otp->send($user->email, OtpPurpose::EmailVerification->value);

        return $this->response($user, $this->tokens->issueCustomer($user, $data['deviceName'] ?? null), true);
    }

    public function login(string $email, string $password, ?string $deviceName): array
    {
        $user = User::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])->first();

        // Constant-ish work whether or not the account exists.
        $valid = $user !== null && $user->password !== null
            ? Hash::check($password, $user->password)
            : password_verify($password, '$2y$12$Ht0vXrG3wQyK1m4E8b0g9eWm3oR6s9zq4pU2n7cJ5a1vYxL8dF0Qe') && false;

        if (! $valid || $user === null) {
            throw ApiException::unauthenticated('Incorrect email or password.');
        }
        if ($user->isSuspended()) {
            throw ApiException::accountSuspended();
        }

        return $this->response($user, $this->tokens->issueCustomer($user, $deviceName));
    }

    public function social(string $provider, string $idToken, ?string $deviceName): array
    {
        $profile = $provider === 'google' ? $this->google->verify($idToken) : null;
        if ($profile === null) {
            throw ApiException::unauthenticated('We could not verify your Google account. Please try again.');
        }

        [$user, $isNew] = DB::transaction(function () use ($profile, $provider) {
            $account = SocialAccount::query()->where('provider', $provider)->where('provider_user_id', $profile['sub'])->first();
            if ($account) {
                $user = User::query()->find($account->user_id);
                if ($user !== null) {
                    return [$user, false];
                }
            }

            $user = User::query()->whereRaw('lower(email) = ?', [$profile['email']])->first();
            $isNew = false;
            if ($user === null) {
                $user = $this->provisioner->create([
                    'first_name' => $profile['given_name'] ?: 'Wedpluz',
                    'last_name' => $profile['family_name'] ?: 'User',
                    'email' => $profile['email'],
                    'password' => null,
                    'avatar_url' => $profile['picture'],
                    'email_verified_at' => now(),
                    'signup_source' => RequestContext::source(),
                ]);
                $isNew = true;
            } elseif (! $user->hasVerifiedEmail()) {
                // Google verified the address, so it can be trusted.
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            SocialAccount::query()->updateOrCreate(
                ['provider' => $provider, 'provider_user_id' => $profile['sub']],
                ['user_id' => $user->id, 'email' => $profile['email']],
            );

            return [$user, $isNew];
        });

        if ($user->isSuspended()) {
            throw ApiException::accountSuspended();
        }

        return $this->response($user->refresh(), $this->tokens->issueCustomer($user, $deviceName), $isNew);
    }

    /** POST /auth/otp/verify. */
    public function verifyOtp(string $email, string $purpose, string $code): array
    {
        // email_verification completes here; other purposes are confirmed by
        // their follow-up action (reset, wallet activation...), which re-checks the code.
        $consume = $purpose === OtpPurpose::EmailVerification->value;
        $otp = $this->otp->verify($email, $purpose, $code, $consume);

        if ($purpose === OtpPurpose::EmailVerification->value) {
            $user = User::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->first();
            if ($user && ! $user->hasVerifiedEmail()) {
                $user->forceFill(['email_verified_at' => now()])->save();
                $this->notifications->sendDirect('email', $user->email, 'welcome', [
                    'firstName' => $user->first_name,
                    'appUrl' => Platform::webUrl('/'),
                ], 'welcome:'.$user->id, $user);
            }
        }

        $resetToken = $purpose === OtpPurpose::PasswordReset->value
            ? Crypt::encryptString(json_encode(['otp' => $otp->id, 'email' => mb_strtolower($email), 'exp' => now()->addMinutes(10)->timestamp]))
            : null;

        return ['verified' => true, 'resetToken' => $resetToken];
    }

    public function resetPassword(string $email, string $code, string $password): void
    {
        $this->otp->verify($email, OtpPurpose::PasswordReset->value, $code, true);
        $user = User::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->first();
        if ($user === null) {
            throw ApiException::otpInvalid();
        }

        DB::transaction(function () use ($user, $password) {
            $user->forceFill(['password' => $password])->save();
            $this->tokens->revokeAllForUser($user);
        });
        $this->notifications->sendDirect('email', $user->email, 'password_changed', ['firstName' => $user->first_name], null, $user);
    }

    public function changePassword(User $user, string $current, string $password): void
    {
        if ($user->password === null || ! Hash::check($current, $user->password)) {
            throw ApiException::validation('Your current password is incorrect.', ['currentPassword' => ['Your current password is incorrect.']]);
        }
        if (Hash::check($password, $user->password)) {
            throw ApiException::validation('Choose a new password.', ['password' => ['The new password must be different from the current one.']]);
        }

        DB::transaction(function () use ($user, $password) {
            $user->forceFill(['password' => $password])->save();
            $this->tokens->revokeAllForUser($user, keepCurrent: true);
        });
        $this->notifications->sendDirect('email', $user->email, 'password_changed', ['firstName' => $user->first_name], null, $user);
    }

    private function response(User $user, array $tokens, bool $isNew = false): array
    {
        return [
            'user' => (new UserResource($user))->resolve(),
            'tokens' => $tokens,
            'isNewUser' => $isNew,
            'nextStep' => $user->nextOnboardingStep(),
        ];
    }
}
