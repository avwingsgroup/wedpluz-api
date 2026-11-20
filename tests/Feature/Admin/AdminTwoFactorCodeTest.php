<?php

use App\Services\Admin\TwoFactorService;

it('accepts the local dev code as typed and zero-padded to six digits, never in production', function () {
    config(['wedpluz.auth.dev_fixed_otp' => '0000']);
    $twoFactor = app(TwoFactorService::class);

    expect($twoFactor->verifyFor($this->adminUser(), '0000'))->toBeTrue()
        ->and($twoFactor->verifyFor($this->adminUser(), '000000'))->toBeTrue()
        ->and($twoFactor->verifyFor($this->adminUser(), '111111'))->toBeFalse();

    app()['env'] = 'production';
    expect($twoFactor->verifyFor($this->adminUser(), '000000'))->toBeFalse()
        ->and($twoFactor->verifyFor($this->adminUser(), '0000'))->toBeFalse();
});

it('signs an admin in with the six-digit authenticator code', function () {
    $twoFactor = app(TwoFactorService::class);
    $admin = $this->adminUser(null, ['password' => 'Correct-horse-42']);

    $this->postJson('/api/v1/admin/auth/login', ['email' => $admin->email, 'password' => 'Correct-horse-42'])
        ->assertOk()->assertJsonPath('data.requiresTwoFactor', true);

    $code = $twoFactor->currentCode($admin->two_factor_secret);
    $this->postJson('/api/v1/admin/auth/login', ['email' => $admin->email, 'password' => 'Correct-horse-42', 'otp' => '1234'])
        ->assertStatus(422)->assertJsonPath('code', 'OTP_INVALID');
    $this->postJson('/api/v1/admin/auth/login', ['email' => $admin->email, 'password' => 'Correct-horse-42', 'otp' => $code])
        ->assertOk()->assertJsonPath('data.requiresTwoFactor', false);
});
