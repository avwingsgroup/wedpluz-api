<?php

namespace App\Integrations\Google;

use App\Integrations\Support\ProviderHttp;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;

/**
 * Verifies a Google Identity Services ID token server-side: RS256 signature
 * against Google's JWKS (cached), `iss`, `aud` (web, Android and iOS client
 * IDs), `exp` and `email_verified`. Never accepts access tokens or profile JSON.
 */
class GoogleIdTokenVerifier
{
    /** @param list<string> $audiences */
    public function __construct(private readonly array $audiences, private readonly array $config) {}

    /**
     * @return array{sub:string, email:string, given_name:?string, family_name:?string, picture:?string}|null
     */
    public function verify(string $idToken): ?array
    {
        if ($this->audiences === []) {
            return null;
        }

        try {
            JWT::$leeway = 60;
            $claims = (array) JWT::decode($idToken, JWK::parseKeySet($this->keys(), 'RS256'));
        } catch (\Throwable) {
            Cache::forget('google:jwks'); // keys may have rotated; next request refetches

            return null;
        }

        $aud = $claims['aud'] ?? null;
        if (! in_array($claims['iss'] ?? null, $this->config['issuers'] ?? [], true)
            || ! in_array($aud, $this->audiences, true)
            || ! ($claims['email_verified'] ?? false)
            || empty($claims['email']) || empty($claims['sub'])) {
            return null;
        }

        return [
            'sub' => (string) $claims['sub'],
            'email' => mb_strtolower((string) $claims['email']),
            'given_name' => $claims['given_name'] ?? null,
            'family_name' => $claims['family_name'] ?? null,
            'picture' => $claims['picture'] ?? null,
        ];
    }

    private function keys(): array
    {
        return Cache::remember('google:jwks', 3600, function () {
            $response = ProviderHttp::for('google', 10)->call('jwks', fn (PendingRequest $http) => $http->get($this->config['certs_url']));

            return (array) $response->json();
        });
    }
}
