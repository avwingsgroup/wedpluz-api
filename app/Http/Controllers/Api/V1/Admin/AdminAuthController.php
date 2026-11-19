<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\PersonalAccessToken;
use App\Services\Admin\AdminPresenter;
use App\Services\Admin\SecurityLogger;
use App\Services\Admin\TwoFactorService;
use App\Services\Auth\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;

/**
 * Admin sign-in (authentication-and-permissions.md §4): password, then a
 * mandatory TOTP code. Invited admins set a password and enrol 2FA before
 * their first token is issued. Every attempt is written to security_logs.
 */
class AdminAuthController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly SecurityLogger $security,
        private readonly TokenService $tokens,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:128'],
            'otp' => ['nullable', 'string', 'max:10'],
        ]);
        $admin = AdminUser::query()->whereRaw('lower(email) = ?', [mb_strtolower($data['email'])])->first();

        if ($admin === null || $admin->password === null || ! Hash::check($data['password'], $admin->password)) {
            $this->security->log('login_failed', $admin, $data['email'], ['reason' => 'credentials']);
            throw ApiException::unauthenticated('Incorrect email or password.');
        }
        if ($admin->status === 'suspended') {
            $this->security->log('login_failed', $admin, $data['email'], ['reason' => 'suspended']);
            throw ApiException::accountSuspended('This administrator account is suspended.');
        }
        if ($admin->status !== 'active' || ! $admin->two_factor_enabled) {
            throw ApiException::forbidden('Accept your invitation and set up two-factor authentication first.');
        }

        if (empty($data['otp'])) {
            return $this->ok(['requiresTwoFactor' => true, 'admin' => null, 'tokens' => null]);
        }
        if (! $this->twoFactor->verifyFor($admin, $data['otp'])) {
            $this->security->log('login_failed', $admin, $data['email'], ['reason' => '2fa']);
            throw ApiException::otpInvalid('Invalid authentication code.', 'otp');
        }

        $admin->forceFill(['last_login_at' => now()])->save();
        $this->security->log('login_success', $admin, $admin->email);

        return $this->ok(['requiresTwoFactor' => false, 'admin' => AdminPresenter::admin($admin), 'tokens' => $this->tokens->issueAdmin($admin)]);
    }

    public function me(): JsonResponse
    {
        return $this->ok(AdminPresenter::admin($this->admin()));
    }

    public function logout(): Response
    {
        $admin = $this->admin();
        $token = $admin->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
        $this->security->log('logout', $admin, $admin->email);

        return $this->noContent();
    }

    /** Additive: invited admin sets a password and receives a TOTP secret to enrol. */
    public function acceptInvitation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:128'],
            'password' => ['required', 'string', 'min:12', 'max:128', 'regex:/[A-Za-z]/', 'regex:/\d/'],
        ]);
        $admin = $this->invited($data['token']);
        $secret = $this->twoFactor->generateSecret();
        $admin->forceFill(['password' => $data['password'], 'two_factor_secret' => $secret, 'two_factor_enabled' => false])->save();

        return $this->ok(['secret' => $secret, 'otpauthUri' => $this->twoFactor->provisioningUri($admin, $secret)], 'Scan the code with your authenticator app, then confirm.');
    }

    /** Additive: confirm 2FA enrolment with a code; activates the admin and signs them in. */
    public function confirmInvitation(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:128'], 'otp' => ['required', 'string', 'max:10']]);
        $admin = $this->invited($data['token']);
        if (! $admin->two_factor_secret || ! $this->twoFactor->verifyFor($admin, $data['otp'])) {
            throw ApiException::otpInvalid('Invalid authentication code.', 'otp');
        }
        $admin->forceFill([
            'status' => 'active', 'two_factor_enabled' => true, 'two_factor_confirmed_at' => now(),
            'invite_token_hash' => null, 'invite_expires_at' => null, 'last_login_at' => now(),
        ])->save();
        $this->security->log('2fa_enabled', $admin, $admin->email);

        return $this->ok(['requiresTwoFactor' => false, 'admin' => AdminPresenter::admin($admin->refresh()), 'tokens' => $this->tokens->issueAdmin($admin)]);
    }

    private function invited(string $token): AdminUser
    {
        $admin = AdminUser::query()->where('invite_token_hash', hash('sha256', $token))->where('status', 'invited')->first();
        if ($admin === null || ($admin->invite_expires_at && $admin->invite_expires_at->isPast())) {
            throw ApiException::forbidden('This invitation is invalid or has expired.');
        }

        return $admin;
    }
}
