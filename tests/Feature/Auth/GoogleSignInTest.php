<?php

use App\Integrations\Google\GoogleIdTokenVerifier;
use App\Integrations\Google\GoogleOAuthClient;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Auth\AuthService;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;

const TEST_GOOGLE_CLIENT = 'test-client.apps.googleusercontent.com';

/** Throwaway RSA key + its JWKS, standing in for Google's signing keys. */
function googleKeys(): array
{
    static $keys;
    if ($keys === null) {
        // Test-only key (tests/Fixtures), never used outside the test suite.
        $private = file_get_contents(base_path('tests/Fixtures/google-test-key.pem'));
        $rsa = openssl_pkey_get_details(openssl_pkey_get_private($private))['rsa'];
        $b64 = fn ($v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
        $keys = ['private' => $private, 'jwks' => ['keys' => [['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => 'test-kid', 'n' => $b64($rsa['n']), 'e' => $b64($rsa['e'])]]]];
    }

    return $keys;
}

function googleIdToken(array $overrides = []): string
{
    return JWT::encode(array_merge([
        'iss' => 'https://accounts.google.com', 'aud' => TEST_GOOGLE_CLIENT, 'sub' => 'google-sub-123',
        'email' => 'Chioma.New@Gmail.com', 'email_verified' => true, 'given_name' => 'Chioma', 'family_name' => 'New',
        'picture' => 'https://lh3.googleusercontent.com/a/photo', 'iat' => time(), 'exp' => time() + 3600,
    ], $overrides), googleKeys()['private'], 'RS256', 'test-kid');
}

beforeEach(function () {
    config([
        'wedpluz.auth.google_client_ids' => [TEST_GOOGLE_CLIENT],
        'services.google.client_id' => TEST_GOOGLE_CLIENT,
        'services.google.client_secret' => 'test-secret',
        'services.google.redirect_uri' => 'https://wedpluz.com/auth/google/callback',
        'services.google.redirect_uris' => ['http://localhost:5173/auth/google/callback'],
    ]);
    foreach ([GoogleOAuthClient::class, GoogleIdTokenVerifier::class, AuthService::class] as $class) {
        app()->forgetInstance($class);
    }
});

it('returns a Google consent URL with single-use state and PKCE', function () {
    $data = $this->getJson('/api/v1/auth/google/redirect?redirectUri='.urlencode('http://localhost:5173/auth/google/callback'))->assertOk()->json('data');
    parse_str(parse_url($data['url'], PHP_URL_QUERY), $q);

    expect($data['redirectUri'])->toBe('http://localhost:5173/auth/google/callback')
        ->and($q['client_id'])->toBe(TEST_GOOGLE_CLIENT)
        ->and($q['state'])->toBe($data['state'])
        ->and($q['code_challenge_method'])->toBe('S256')
        ->and($q['scope'])->toBe('openid email profile')
        ->and($q)->not->toHaveKey('client_secret');

    // Default callback, and callbacks outside the allow-list are refused.
    expect($this->getJson('/api/v1/auth/google/redirect')->json('data.redirectUri'))->toBe('https://wedpluz.com/auth/google/callback');
    $this->getJson('/api/v1/auth/google/redirect?redirectUri='.urlencode('https://evil.example.com/cb'))->assertStatus(422);
});

it('signs up a new user with the code from the redirect, exactly once per state', function () {
    $state = $this->getJson('/api/v1/auth/google/redirect')->json('data.state');
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'x', 'id_token' => googleIdToken(), 'token_type' => 'Bearer']),
        'www.googleapis.com/oauth2/v3/certs' => Http::response(googleKeys()['jwks']),
    ]);

    $this->postJson('/api/v1/auth/social', ['provider' => 'google', 'code' => '4/abc', 'state' => $state, 'deviceName' => 'Chrome'])
        ->assertOk()
        ->assertJsonPath('data.isNewUser', true)
        ->assertJsonPath('data.user.email', 'chioma.new@gmail.com')
        ->assertJsonStructure(['data' => ['tokens' => ['accessToken', 'refreshToken'], 'nextStep']]);

    $user = User::query()->where('email', 'chioma.new@gmail.com')->firstOrFail();
    expect($user->email_verified_at)->not->toBeNull()
        ->and(SocialAccount::query()->where('provider_user_id', 'google-sub-123')->value('user_id'))->toBe($user->id);

    // The secret and the PKCE verifier went to Google, server to server.
    Http::assertSent(fn ($r) => str_contains($r->url(), 'oauth2.googleapis.com/token')
        && $r['client_secret'] === 'test-secret' && strlen($r['code_verifier']) >= 43 && $r['redirect_uri'] === 'https://wedpluz.com/auth/google/callback');

    // Replaying the same state fails.
    $this->postJson('/api/v1/auth/social', ['provider' => 'google', 'code' => '4/abc', 'state' => $state])->assertStatus(401);
});

it('signs an existing account in with Google instead of duplicating it', function () {
    $existing = $this->customer(['email' => 'chioma.new@gmail.com']);
    Http::fake(['www.googleapis.com/oauth2/v3/certs' => Http::response(googleKeys()['jwks'])]);

    $this->postJson('/api/v1/auth/social', ['provider' => 'google', 'idToken' => googleIdToken()])
        ->assertOk()->assertJsonPath('data.isNewUser', false)->assertJsonPath('data.user.id', $existing->id);
    expect(User::query()->where('email', 'chioma.new@gmail.com')->count())->toBe(1);
});

it('rejects bad codes, unknown state and tokens for another app', function () {
    $state = $this->getJson('/api/v1/auth/google/redirect')->json('data.state');
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
        'www.googleapis.com/oauth2/v3/certs' => Http::response(googleKeys()['jwks']),
    ]);

    $this->postJson('/api/v1/auth/social', ['provider' => 'google', 'code' => 'bad', 'state' => $state])->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
    $this->postJson('/api/v1/auth/social', ['provider' => 'google', 'code' => 'x', 'state' => 'never-issued'])->assertStatus(401);
    $this->postJson('/api/v1/auth/social', ['provider' => 'google', 'idToken' => googleIdToken(['aud' => 'someone-else.apps.googleusercontent.com'])])->assertStatus(401);
    $this->postJson('/api/v1/auth/social', ['provider' => 'google'])->assertStatus(422);
});
