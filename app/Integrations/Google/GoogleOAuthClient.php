<?php

namespace App\Integrations\Google;

use App\Integrations\Support\IntegrationException;
use App\Integrations\Support\ProviderHttp;
use Illuminate\Http\Client\PendingRequest;

/**
 * Google OAuth 2.0 authorization-code flow with PKCE, for "Continue with
 * Google" by redirect. The client secret never leaves the server: the web app
 * only receives the consent URL and later hands back `code` + `state`.
 * The ID token returned by the exchange is still verified by
 * GoogleIdTokenVerifier (signature, issuer, audience, email_verified).
 */
class GoogleOAuthClient
{
    public function __construct(private readonly array $config) {}

    public function isConfigured(): bool
    {
        return ! empty($this->config['client_id']) && ! empty($this->config['client_secret']) && $this->allowedRedirectUris() !== [];
    }

    /** @return list<string> the default callback first */
    public function allowedRedirectUris(): array
    {
        return array_values(array_unique(array_filter(array_merge([(string) ($this->config['redirect_uri'] ?? '')], (array) ($this->config['redirect_uris'] ?? [])))));
    }

    /** The requested callback if allowed, the default when none is given, otherwise null. */
    public function resolveRedirectUri(?string $requested): ?string
    {
        $allowed = $this->allowedRedirectUris();
        if ($requested === null || $requested === '') {
            return $allowed[0] ?? null;
        }

        return in_array(rtrim($requested, '/'), array_map(fn ($u) => rtrim($u, '/'), $allowed), true) ? $requested : null;
    }

    public function authorizationUrl(string $state, string $codeChallenge, string $redirectUri, ?string $loginHint = null): string
    {
        return $this->config['auth_url'].'?'.http_build_query(array_filter([
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'access_type' => 'online',
            'include_granted_scopes' => 'true',
            'prompt' => 'select_account',
            'login_hint' => $loginHint,
        ]), '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Exchange the code for tokens; returns the ID token, or null when Google
     * rejects the code (expired, reused, wrong redirect URI or verifier).
     */
    public function exchangeCode(string $code, string $codeVerifier, string $redirectUri): ?string
    {
        if (! $this->isConfigured()) {
            throw IntegrationException::notConfigured('google');
        }
        $response = ProviderHttp::for('google', 15, 0)->call(
            'oauth.token',
            fn (PendingRequest $http) => $http->asForm()->post($this->config['token_url'], [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'code_verifier' => $codeVerifier,
                'redirect_uri' => $redirectUri,
                'client_id' => $this->config['client_id'],
                'client_secret' => $this->config['client_secret'],
            ]),
        );

        if ($response->successful() && is_string($response->json('id_token'))) {
            return $response->json('id_token');
        }
        if ($response->status() >= 500) {
            throw IntegrationException::transient('google', 'oauth.token', 'Google sign-in is temporarily unavailable.');
        }

        return null; // invalid_grant and friends: the user simply retries
    }
}
