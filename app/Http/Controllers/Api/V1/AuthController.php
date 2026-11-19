<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\OtpSendRequest;
use App\Http\Requests\Auth\OtpVerifyRequest;
use App\Http\Requests\Auth\RefreshRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\SocialAuthRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\AuthService;
use App\Services\Auth\OtpService;
use App\Services\Auth\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly OtpService $otp,
        private readonly TokenService $tokens,
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        return $this->created($this->auth->register($request->validated()), 'Account created. Check your email for a verification code.');
    }

    public function login(LoginRequest $request): JsonResponse
    {
        return $this->ok($this->auth->login($request->string('email'), (string) $request->input('password'), $request->input('deviceName')));
    }

    public function social(SocialAuthRequest $request): JsonResponse
    {
        return $this->ok($this->auth->social($request->string('provider'), $request->string('idToken'), $request->input('deviceName')));
    }

    public function sendOtp(OtpSendRequest $request): JsonResponse
    {
        return $this->ok($this->otp->send($request->string('email'), $request->string('purpose')), 'If the email is eligible, a code has been sent.');
    }

    public function verifyOtp(OtpVerifyRequest $request): JsonResponse
    {
        return $this->ok($this->auth->verifyOtp($request->string('email'), $request->string('purpose'), $request->string('code')));
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->otp->send($request->string('email'), 'password_reset');

        return $this->ok(['sent' => true], 'If an account exists for this email, a reset code has been sent.');
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $this->auth->resetPassword($request->string('email'), $request->string('code'), (string) $request->input('password'));

        return $this->ok(['reset' => true], 'Your password has been reset. Please sign in.');
    }

    public function refresh(RefreshRequest $request): JsonResponse
    {
        return $this->ok($this->tokens->refresh($request->string('refreshToken')));
    }

    public function logout(): Response
    {
        $this->tokens->revokeCurrent($this->customer());

        return $this->noContent();
    }

    public function me(): JsonResponse
    {
        return $this->ok(new UserResource($this->customer()));
    }
}
