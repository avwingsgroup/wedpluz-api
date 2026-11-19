<?php

namespace App\Integrations\Live;

use App\Integrations\Support\IntegrationException;
use Firebase\JWT\JWT;

/**
 * LiveKit access tokens for community live sessions. A room token is an
 * HS256 JWT signed with the LiveKit API secret carrying a `video` grant.
 * Media flows through LiveKit; Reverb only carries state changes.
 */
class LiveKitTokenIssuer
{
    public function __construct(private readonly array $config) {}

    public function isConfigured(): bool
    {
        return ! empty($this->config['api_key']) && ! empty($this->config['api_secret']);
    }

    public function issue(string $room, string $identity, string $name, bool $canPublish): string
    {
        if (! $this->isConfigured()) {
            throw IntegrationException::notConfigured('livekit');
        }
        $now = time();

        return JWT::encode([
            'iss' => $this->config['api_key'],
            'sub' => $identity,
            'name' => $name,
            'nbf' => $now,
            'exp' => $now + (int) ($this->config['token_ttl'] ?? 3600),
            'video' => [
                'room' => $room,
                'roomJoin' => true,
                'canPublish' => $canPublish,
                'canSubscribe' => true,
                'canPublishData' => true,
            ],
        ], $this->config['api_secret'], 'HS256');
    }
}
