<?php

use App\Models\OtpCode;
use App\Models\RefreshToken;
use App\Services\Auth\OtpService;

function registerPayload(array $overrides = []): array
{
    return array_merge([
        'firstName' => 'Tessy',
        'lastName' => 'Bello',
        'email' => 'tessy@example.com',
        'dateOfBirth' => '1998-04-10',
        'password' => 'Password1',
        'passwordConfirmation' => 'Password1',
        'acceptedTerms' => true,
    ], $overrides);
}

it('registers a customer, returns tokens and the verify_email next step', function () {
    $this->fakeQueue();

    $response = $this->postJson('/api/v1/auth/register', registerPayload());

    $response->assertCreated()
        ->assertJsonPath('data.user.email', 'tessy@example.com')
        ->assertJsonPath('data.user.emailVerifiedAt', null)
        ->assertJsonPath('data.isNewUser', true)
        ->assertJsonPath('data.nextStep', 'verify_email')
        ->assertJsonPath('data.tokens.tokenType', 'Bearer')
        ->assertJsonStructure(['data' => ['user' => ['id', 'displayName', 'roles', 'interests'], 'tokens' => ['accessToken', 'refreshToken', 'expiresIn']]]);

    expect($response->json('data.user.id'))->toStartWith('usr_');
    expect(OtpCode::query()->where('email', 'tessy@example.com')->where('purpose', 'email_verification')->exists())->toBeTrue();
});

it('returns camelCase validation errors in the contract envelope', function () {
    $response = $this->postJson('/api/v1/auth/register', registerPayload([
        'dateOfBirth' => now()->subYears(10)->toDateString(),
        'password' => 'short',
        'acceptedTerms' => false,
    ]));

    $response->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonPath('errors.dateOfBirth.0', 'You must be at least 13 years old.')
        ->assertJsonStructure(['message', 'code', 'errors' => ['password', 'acceptedTerms'], 'correlationId']);
});

it('rejects duplicate emails case-insensitively', function () {
    $this->customer(['email' => 'dup@example.com']);

    $this->postJson('/api/v1/auth/register', registerPayload(['email' => 'DUP@example.com']))
        ->assertStatus(422)
        ->assertJsonPath('errors.email.0', 'An account with this email already exists.');
});

it('logs in with valid credentials and rejects bad ones with UNAUTHENTICATED', function () {
    $user = $this->customer(['email' => 'login@example.com', 'password' => 'Secret123']);

    $this->postJson('/api/v1/auth/login', ['email' => 'LOGIN@example.com', 'password' => 'Secret123'])
        ->assertOk()
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.nextStep', null);

    $this->postJson('/api/v1/auth/login', ['email' => 'login@example.com', 'password' => 'wrong-pass1'])
        ->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED')
        ->assertJsonPath('message', 'Incorrect email or password.');
});

it('blocks suspended accounts at login', function () {
    $this->customer(['email' => 'sus@example.com', 'password' => 'Secret123', 'status' => 'suspended']);

    $this->postJson('/api/v1/auth/login', ['email' => 'sus@example.com', 'password' => 'Secret123'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'ACCOUNT_SUSPENDED');
});

it('verifies email with a one-time code and enforces the attempt limit', function () {
    $this->fakeQueue();
    $user = $this->customer(['email' => 'otp@example.com', 'email_verified_at' => null]);
    $code = app(OtpService::class)->issue('otp@example.com', 'email_verification', $user);
    $wrong = $code === '0000' ? '1111' : '0000';

    $this->postJson('/api/v1/auth/otp/verify', ['email' => 'otp@example.com', 'purpose' => 'email_verification', 'code' => $wrong])
        ->assertStatus(422)->assertJsonPath('code', 'OTP_INVALID');

    $this->postJson('/api/v1/auth/otp/verify', ['email' => 'otp@example.com', 'purpose' => 'email_verification', 'code' => $code])
        ->assertOk()->assertJsonPath('data.verified', true);

    expect($user->refresh()->email_verified_at)->not->toBeNull();

    // A consumed code cannot be reused.
    $this->postJson('/api/v1/auth/otp/verify', ['email' => 'otp@example.com', 'purpose' => 'email_verification', 'code' => $code])
        ->assertStatus(422)->assertJsonPath('code', 'OTP_EXPIRED');
});

it('invalidates a code after five wrong attempts', function () {
    $user = $this->customer(['email' => 'five@example.com', 'email_verified_at' => null]);
    $code = app(OtpService::class)->issue('five@example.com', 'email_verification', $user);
    $wrong = $code === '0000' ? '1111' : '0000';

    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/auth/otp/verify', ['email' => 'five@example.com', 'purpose' => 'email_verification', 'code' => $wrong]);
    }

    $this->postJson('/api/v1/auth/otp/verify', ['email' => 'five@example.com', 'purpose' => 'email_verification', 'code' => $code])
        ->assertStatus(422)->assertJsonPath('code', 'OTP_EXPIRED');
});

it('does not reveal whether an account exists on forgot password', function () {
    $this->fakeQueue();
    $this->postJson('/api/v1/auth/password/forgot', ['email' => 'nobody@example.com'])
        ->assertOk()->assertJsonPath('data.sent', true);
    expect(OtpCode::query()->where('email', 'nobody@example.com')->exists())->toBeFalse();
});

it('resets the password with a code and revokes existing sessions', function () {
    $this->fakeQueue();
    $user = $this->customer(['email' => 'reset@example.com', 'password' => 'OldPass123']);
    $this->asCustomer($user);
    $code = app(OtpService::class)->issue('reset@example.com', 'password_reset', $user);

    $this->postJson('/api/v1/auth/password/reset', [
        'email' => 'reset@example.com', 'code' => $code, 'password' => 'NewPass123', 'passwordConfirmation' => 'NewPass123',
    ])->assertOk()->assertJsonPath('data.reset', true);

    expect($user->tokens()->count())->toBe(0);
    $this->postJson('/api/v1/auth/login', ['email' => 'reset@example.com', 'password' => 'NewPass123'])->assertOk();
});

it('rotates refresh tokens and revokes the family when a used token is replayed', function () {
    $user = $this->customer(['email' => 'refresh@example.com', 'password' => 'Secret123']);
    $login = $this->postJson('/api/v1/auth/login', ['email' => 'refresh@example.com', 'password' => 'Secret123'])->json('data.tokens');

    $rotated = $this->postJson('/api/v1/auth/refresh', ['refreshToken' => $login['refreshToken']])->assertOk()->json('data');
    expect($rotated['refreshToken'])->not->toBe($login['refreshToken']);

    // Replay of the old token: reuse detected, whole family revoked.
    $this->postJson('/api/v1/auth/refresh', ['refreshToken' => $login['refreshToken']])->assertStatus(401);
    $this->postJson('/api/v1/auth/refresh', ['refreshToken' => $rotated['refreshToken']])->assertStatus(401);

    expect(RefreshToken::query()->where('user_id', $user->id)->whereNull('revoked_at')->count())->toBe(0);
});

it('restores the session with /auth/me and logs out', function () {
    $user = $this->asCustomer();

    $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $user->id);
    $this->postJson('/api/v1/auth/logout')->assertNoContent();

    app('auth')->forgetGuards();
    $this->getJson('/api/v1/auth/me')->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
});

it('echoes the correlation id header in responses and errors', function () {
    $this->withHeader('X-Correlation-Id', '6f1c2b8e-0f8a-4f7e-9d0e-3b1f2a7c9d11')
        ->getJson('/api/v1/auth/me')
        ->assertStatus(401)
        ->assertHeader('X-Correlation-Id', '6f1c2b8e-0f8a-4f7e-9d0e-3b1f2a7c9d11')
        ->assertJsonPath('correlationId', '6f1c2b8e-0f8a-4f7e-9d0e-3b1f2a7c9d11');
});

it('rejects an admin token on customer routes', function () {
    $this->asAdmin();

    $this->getJson('/api/v1/auth/me')->assertStatus(401);
});

it('rate limits login attempts', function () {
    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/auth/login', ['email' => 'rl@example.com', 'password' => 'whatever1']);
    }

    $this->postJson('/api/v1/auth/login', ['email' => 'rl@example.com', 'password' => 'whatever1'])
        ->assertStatus(429)
        ->assertJsonPath('code', 'RATE_LIMITED')
        ->assertHeader('Retry-After');
});
