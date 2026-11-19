<?php

namespace App\Integrations\Push;

use App\Integrations\Support\IntegrationException;
use App\Integrations\Support\ProviderHttp;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;

/**
 * Firebase Cloud Messaging HTTP v1.
 *
 * Authenticates with a service-account JWT exchanged for a short-lived OAuth2
 * access token (cached). `send` returns the FCM message name, or throws an
 * IntegrationException with providerCode `unregistered` when the device token
 * is no longer valid, so callers can delete it.
 */
class FcmClient
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public function __construct(private readonly array $config) {}

    public function isConfigured(): bool
    {
        return $this->credentials() !== null && $this->projectId() !== null;
    }

    /**
     * @param  array<string,string>  $data
     */
    public function send(string $token, string $title, string $body, array $data = []): string
    {
        return $this->dispatch(['token' => $token], $title, $body, $data);
    }

    /** @param array<string,string> $data */
    public function sendToTopic(string $topic, string $title, string $body, array $data = []): string
    {
        return $this->dispatch(['topic' => $topic], $title, $body, $data);
    }

    private function dispatch(array $target, string $title, string $body, array $data): string
    {
        if (! $this->isConfigured()) {
            throw IntegrationException::notConfigured('fcm');
        }

        $message = $target + [
            'notification' => ['title' => $title, 'body' => $body],
            'data' => array_map('strval', $data),
            'android' => ['priority' => 'high'],
            'apns' => ['headers' => ['apns-priority' => '10'], 'payload' => ['aps' => ['sound' => 'default']]],
            'webpush' => ['headers' => ['Urgency' => 'high']],
        ];

        $url = 'https://fcm.googleapis.com/v1/projects/'.$this->projectId().'/messages:send';
        $response = ProviderHttp::for('fcm', (int) ($this->config['timeout'] ?? 10), 1)->call(
            'messages.send',
            fn (PendingRequest $http) => $http->withToken($this->accessToken())->post($url, ['message' => $message]),
        );

        if ($response->successful()) {
            return (string) $response->json('name');
        }

        $status = (string) $response->json('error.status');
        $detail = collect($response->json('error.details', []))->pluck('errorCode')->filter()->first();
        if ($detail === 'UNREGISTERED' || $status === 'NOT_FOUND' || ($status === 'INVALID_ARGUMENT' && str_contains((string) $response->json('error.message'), 'registration token'))) {
            throw IntegrationException::permanent('fcm', 'send', 'Device token is no longer registered.', 'unregistered');
        }

        throw IntegrationException::fromStatus('fcm', 'send', $response->status(), 'FCM could not deliver the notification.', $status);
    }

    private function accessToken(): string
    {
        $credentials = $this->credentials();

        return Cache::remember('fcm:access_token:'.md5($credentials['client_email']), 3300, function () use ($credentials) {
            $now = time();
            $assertion = JWT::encode([
                'iss' => $credentials['client_email'],
                'scope' => self::SCOPE,
                'aud' => $credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ], $credentials['private_key'], 'RS256');

            $response = ProviderHttp::for('fcm', 10, 1)->call(
                'oauth.token',
                fn (PendingRequest $http) => $http->asForm()->post($credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token', [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $assertion,
                ]),
            );

            if (! $response->successful() || ! $response->json('access_token')) {
                throw IntegrationException::transient('fcm', 'oauth', 'Could not obtain an FCM access token.');
            }

            return (string) $response->json('access_token');
        });
    }

    /** @return array{client_email:string, private_key:string, project_id?:string, token_uri?:string}|null */
    private function credentials(): ?array
    {
        $raw = (string) ($this->config['credentials'] ?? '');
        if ($raw === '') {
            return null;
        }
        if (is_file($raw)) {
            $raw = (string) file_get_contents($raw);
        } elseif (! str_starts_with(ltrim($raw), '{')) {
            $raw = (string) base64_decode($raw, true);
        }
        $json = json_decode($raw, true);

        return is_array($json) && isset($json['client_email'], $json['private_key']) ? $json : null;
    }

    private function projectId(): ?string
    {
        return $this->config['project_id'] ?: ($this->credentials()['project_id'] ?? null);
    }
}
