<?php

use App\Models\User;

it('leaves countryCode null at registration', function () {
    $this->postJson('/api/v1/auth/register', [
        'firstName' => 'Ada', 'lastName' => 'Obi', 'email' => 'ada.country@example.com', 'dateOfBirth' => '1995-04-02',
        'password' => 'secret123', 'passwordConfirmation' => 'secret123', 'acceptedTerms' => true,
    ])->assertSuccessful();

    expect(User::query()->where('email', 'ada.country@example.com')->value('country_code'))->toBeNull();
});

it('stores countryCode and an E.164 phone from profile setup, normalising formatting', function () {
    $user = $this->asCustomer($this->customer(['country_code' => null, 'phone' => null, 'username' => null]));

    $this->postJson('/api/v1/me/profile-setup', ['username' => 'ada_gh', 'phone' => '+233 24 123 4567', 'countryCode' => 'gh'])
        ->assertOk();

    $user->refresh();
    expect($user->phone)->toBe('+233241234567')->and($user->country_code)->toBe('GH');
});

it('rejects invalid phone numbers with errors.phone', function () {
    $this->asCustomer($this->customer(['username' => null]));

    foreach (['+23408012345678', '+234801234567', '08012345678', 'not a phone'] as $bad) {
        $this->postJson('/api/v1/me/profile-setup', ['username' => 'ada_ng', 'phone' => $bad])
            ->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR')->assertJsonStructure(['errors' => ['phone']]);
    }
});

it('normalises and validates the phone on profile update', function () {
    $user = $this->asCustomer();

    $this->patchJson('/api/v1/me', ['phone' => '00234 (803) 123-4567'])->assertOk();
    expect($user->refresh()->phone)->toBe('+2348031234567');

    $this->patchJson('/api/v1/me', ['phone' => '+2340000000000'])->assertStatus(422)->assertJsonStructure(['errors' => ['phone']]);
});

it('returns geo.countryCode from the edge header and empty support contacts by default', function () {
    $response = $this->getJson('/api/v1/config', ['CF-IPCountry' => 'ng'])->assertOk()
        ->assertJsonPath('data.geo.countryCode', 'NG')
        ->assertJsonPath('data.support.phone', '')
        ->assertJsonPath('data.support.whatsapp', '');
    expect($response->headers->get('Vary'))->toContain('CF-IPCountry'); // shared caches must key on the country

    $this->getJson('/api/v1/config', ['CF-IPCountry' => 'XX'])->assertJsonPath('data.geo.countryCode', null);
    $this->getJson('/api/v1/config')->assertJsonPath('data.geo.countryCode', null);
});
