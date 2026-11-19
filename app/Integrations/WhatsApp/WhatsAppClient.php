<?php

namespace App\Integrations\WhatsApp;

use App\Integrations\Support\IntegrationException;
use App\Integrations\Support\ProviderHttp;
use Illuminate\Http\Client\PendingRequest;

/**
 * Meta WhatsApp Business Cloud API.
 *
 * Business-initiated messages must use pre-approved templates
 * (`sendTemplate`). Free-form text is only allowed inside the 24-hour
 * customer-service window (`sendText`).
 */
class WhatsAppClient
{
    /** Errors that will never succeed for this recipient/template. */
    private const PERMANENT = [100, 131008, 131009, 131021, 131026, 131030, 131031, 131051, 132000, 132001, 132005, 132007, 132012, 132015, 132016];

    public function __construct(private readonly array $config) {}

    public function isConfigured(): bool
    {
        return ! empty($this->config['access_token']) && ! empty($this->config['phone_number_id']);
    }

    /** @param list<string> $bodyParameters */
    public function sendTemplate(string $to, string $template, array $bodyParameters = [], ?string $language = null): string
    {
        $components = $bodyParameters === [] ? [] : [[
            'type' => 'body',
            'parameters' => array_map(fn ($p) => ['type' => 'text', 'text' => (string) $p], $bodyParameters),
        ]];

        return $this->send($to, [
            'type' => 'template',
            'template' => array_filter([
                'name' => $template,
                'language' => ['code' => $language ?? ($this->config['default_language'] ?? 'en')],
                'components' => $components ?: null,
            ]),
        ], 'template:'.$template);
    }

    public function sendText(string $to, string $text): string
    {
        return $this->send($to, ['type' => 'text', 'text' => ['preview_url' => false, 'body' => $text]], 'text');
    }

    private function send(string $to, array $message, string $operation): string
    {
        if (! $this->isConfigured()) {
            throw IntegrationException::notConfigured('whatsapp');
        }

        $url = sprintf('https://graph.facebook.com/%s/%s/messages', $this->config['api_version'] ?? 'v21.0', $this->config['phone_number_id']);
        $payload = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => ltrim($to, '+')] + $message;

        $response = ProviderHttp::for('whatsapp', (int) ($this->config['timeout'] ?? 15), 1)->call(
            'messages.'.$operation,
            fn (PendingRequest $http) => $http->withToken($this->config['access_token'])->post($url, $payload),
            ['type' => $message['type']],
        );

        if ($response->successful() && $response->json('messages.0.id')) {
            return (string) $response->json('messages.0.id');
        }

        $code = (int) $response->json('error.code');
        $text = (string) ($response->json('error.error_data.details') ?? $response->json('error.message') ?? 'WhatsApp rejected the message.');
        if (in_array($code, self::PERMANENT, true)) {
            throw IntegrationException::permanent('whatsapp', 'send', $text, (string) $code);
        }

        throw IntegrationException::fromStatus('whatsapp', 'send', $response->status(), $text, (string) $code);
    }

    public function verifySignature(string $rawBody, ?string $header): bool
    {
        $secret = (string) ($this->config['app_secret'] ?? '');
        if ($secret === '' || ! $header || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $rawBody, $secret), $header);
    }
}
