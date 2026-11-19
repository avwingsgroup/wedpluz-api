<?php

namespace App\Integrations\Sms;

use App\Integrations\Support\IntegrationException;
use App\Integrations\Support\ProviderHttp;
use Illuminate\Http\Client\PendingRequest;

/** Termii messaging API: POST {base}/api/sms/send. Primary SMS route (DND-safe for OTP in Nigeria). */
class TermiiSmsSender implements SmsSender
{
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'termii';
    }

    public function send(string $to, string $message): SmsResult
    {
        if (empty($this->config['api_key'])) {
            throw IntegrationException::transient('termii', 'send', 'Termii is not configured.', 'not_configured');
        }

        $payload = [
            'to' => ltrim($to, '+'),
            'from' => $this->config['sender_id'] ?? 'Wedpluz',
            'sms' => $message,
            'type' => 'plain',
            'channel' => $this->config['channel'] ?? 'dnd',
            'api_key' => $this->config['api_key'],
        ];

        $response = ProviderHttp::for('termii', (int) ($this->config['timeout'] ?? 10), 0)->call(
            'sms.send',
            fn (PendingRequest $http) => $http->post(rtrim($this->config['base_url'], '/').'/api/sms/send', $payload),
            ['channel' => $payload['channel']],
        );

        if ($response->successful() && $response->json('message_id')) {
            return new SmsResult('termii', (string) $response->json('message_id'));
        }

        $error = strtolower((string) ($response->json('message') ?? $response->body()));
        if ($response->status() >= 400 && $response->status() < 500 && (str_contains($error, 'phone') || str_contains($error, 'number') || str_contains($error, 'recipient'))) {
            throw IntegrationException::permanent('termii', 'sms.send', 'Invalid recipient number.', 'invalid_recipient');
        }

        // Balance, auth, sender-ID and 5xx problems are provider failures: fail over.
        throw IntegrationException::transient('termii', 'sms.send', 'Termii could not send the message.', (string) $response->status());
    }
}
