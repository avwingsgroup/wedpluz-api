<?php

namespace App\Integrations\Identity;

use App\Integrations\Support\IntegrationException;
use App\Integrations\Support\ProviderHttp;
use Illuminate\Http\Client\PendingRequest;

/**
 * Prembly (IdentityPass) Nigerian KYC: ID lookup with face match.
 *
 * Endpoint paths per ID type are configurable (`PREMBLY_ENDPOINT_*`) because
 * Prembly versions its product routes; the defaults follow the IdentityPass
 * "with face" verification products. Data minimisation: only the provider
 * reference, the outcome and the match score are kept. The full identity
 * record returned by the provider is discarded, never stored or logged.
 */
class PremblyVerifier implements IdentityVerifier
{
    private const DEFAULT_ENDPOINTS = [
        'nin' => '/identitypass/verification/biometrics/face/nin_w_face',
        'bvn' => '/identitypass/verification/bvn_w_face',
        'passport' => '/identitypass/verification/national_passport_with_face',
        'drivers_license' => '/identitypass/verification/drivers_license/image',
    ];

    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'prembly';
    }

    public function supports(string $idType): bool
    {
        return $this->endpoint($idType) !== null;
    }

    public function verify(string $idType, string $idNumber, string $selfie, string $reference): IdentityResult
    {
        if (empty($this->config['api_key']) || empty($this->config['app_id'])) {
            throw IntegrationException::notConfigured('prembly');
        }
        $endpoint = $this->endpoint($idType);
        if ($endpoint === null) {
            return new IdentityResult('pending', null, null, null); // routed to manual review
        }

        $payload = ['number' => $idNumber, 'image' => base64_encode($selfie)];
        $response = ProviderHttp::for('prembly', (int) ($this->config['timeout'] ?? 30), 1)->call(
            'verify.'.$idType,
            fn (PendingRequest $http) => $http
                ->withHeaders(['x-api-key' => $this->config['api_key'], 'app-id' => $this->config['app_id']])
                ->post(rtrim($this->config['base_url'], '/').$endpoint, $payload),
            ['idType' => $idType, 'reference' => $reference],
        );

        if ($response->serverError() || $response->status() === 429) {
            throw IntegrationException::transient('prembly', 'verify', 'Identity provider is unavailable.');
        }
        if ($response->status() === 401 || $response->status() === 403) {
            throw IntegrationException::transient('prembly', 'verify', 'Identity provider rejected our credentials.', 'auth');
        }

        $json = $response->json() ?? [];
        $providerRef = (string) ($json['verification']['reference'] ?? $json['reference'] ?? $json['data']['reference'] ?? $reference);
        $found = ($json['status'] ?? false) === true && in_array((string) ($json['response_code'] ?? ''), ['00', '0'], true);
        $verificationStatus = strtoupper((string) ($json['verification']['status'] ?? ($found ? 'VERIFIED' : 'NOT-VERIFIED')));

        if (! $found || $verificationStatus !== 'VERIFIED') {
            $reason = (string) ($json['detail'] ?? $json['message'] ?? 'We could not verify this ID number.');

            return new IdentityResult('failed', $providerRef, null, $this->friendlyReason($reason));
        }

        $face = $json['face_data'] ?? null;
        $score = isset($face['confidence']) ? (float) $face['confidence'] : null;
        $threshold = (float) ($this->config['face_match_threshold'] ?? 80);
        $faceOk = is_array($face) ? (($face['status'] ?? false) === true && ($score === null || $score >= $threshold)) : false;

        if (! $faceOk) {
            return new IdentityResult('failed', $providerRef, $score, 'Your selfie did not match the photo on your ID.');
        }

        return new IdentityResult('verified', $providerRef, $score, null);
    }

    private function endpoint(string $idType): ?string
    {
        $configured = $this->config['endpoints'][$idType] ?? null;

        return $configured ?: (self::DEFAULT_ENDPOINTS[$idType] ?? null);
    }

    private function friendlyReason(string $reason): string
    {
        $lower = strtolower($reason);

        return match (true) {
            str_contains($lower, 'not found'), str_contains($lower, 'invalid') => 'We could not find a record for this ID number.',
            str_contains($lower, 'face') => 'Your selfie did not match the photo on your ID.',
            default => 'We could not verify your identity with the details provided.',
        };
    }
}
