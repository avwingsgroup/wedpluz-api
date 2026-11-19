<?php

namespace App\Integrations\Ai;

use App\Integrations\Support\IntegrationException;
use App\Integrations\Support\ProviderHttp;
use Illuminate\Http\Client\PendingRequest;

/**
 * Cake and event-branding design generation (contract status: proposed; the
 * image provider is not chosen). Talks to a configurable HTTP endpoint:
 * POST {endpoint} { type, prompt, count } with a bearer key, expecting
 * { urls: [...] }. Swap the driver here once a provider is selected.
 */
class ImageGenerator
{
    public function __construct(private readonly array $config) {}

    public function isConfigured(): bool
    {
        return ! empty($this->config['endpoint']) && ! empty($this->config['api_key']);
    }

    /** @return list<string> */
    public function generate(string $type, string $prompt, int $count = 4): array
    {
        if (! $this->isConfigured()) {
            throw IntegrationException::notConfigured('image_generation');
        }
        $response = ProviderHttp::for('image_generation', 120, 1)->call(
            'generate',
            fn (PendingRequest $http) => $http->withToken($this->config['api_key'])->post($this->config['endpoint'], [
                'type' => $type, 'prompt' => $prompt, 'count' => $count,
            ]),
            ['type' => $type],
        );
        if (! $response->successful()) {
            throw IntegrationException::fromStatus('image_generation', 'generate', $response->status(), 'Design generation failed.');
        }

        return array_values(array_filter((array) $response->json('urls', []), 'is_string'));
    }
}
