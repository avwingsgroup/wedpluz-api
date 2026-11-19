<?php

namespace App\Integrations\Support;

use App\Models\IntegrationLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper over Laravel's HTTP client for third-party providers.
 *
 * Every call is logged to `integration_logs` with provider, operation, status,
 * latency and redacted request/response bodies. Network failures are converted
 * to IntegrationException so callers can distinguish transient from permanent
 * errors. Secrets, tokens, card and identity numbers are never logged.
 */
class ProviderHttp
{
    private const REDACT = [
        'authorization', 'password', 'secret', 'secret_key', 'api_key', 'apikey', 'token', 'access_token', 'client_secret',
        'pin', 'otp', 'code', 'number', 'nin', 'bvn', 'id_number', 'account_number', 'accountnumber', 'card', 'cvv', 'image',
        'selfie', 'photo', 'base64', 'sms', 'message', 'body', 'to', 'phone', 'email', 'recipient', 'id_token', 'idtoken',
    ];

    public function __construct(
        private readonly string $provider,
        private readonly int $timeout = 20,
        private readonly int $retries = 1,
    ) {}

    public static function for(string $provider, int $timeout = 20, int $retries = 1): self
    {
        return new self($provider, $timeout, $retries);
    }

    /**
     * @param  callable(PendingRequest): Response  $send
     */
    public function call(string $operation, callable $send, array $logRequest = []): Response
    {
        $started = microtime(true);
        $request = Http::timeout($this->timeout)
            ->connectTimeout(min(10, $this->timeout))
            ->acceptJson()
            ->withHeaders(['User-Agent' => 'WedpluzAPI/1.0'])
            ->retry($this->retries, 300, fn ($e) => $e instanceof ConnectionException, throw: false);

        try {
            $response = $send($request);
        } catch (ConnectionException $e) {
            $this->log($operation, null, false, $started, $logRequest, null, $e->getMessage());
            throw IntegrationException::transient($this->provider, $operation, 'Could not reach '.$this->provider.'.', previous: $e);
        }

        $ok = $response->successful();
        $this->log($operation, $response->status(), $ok, $started, $logRequest, $this->decode($response), $ok ? null : mb_substr($response->body(), 0, 1000));

        return $response;
    }

    private function decode(Response $response): ?array
    {
        $json = $response->json();

        return is_array($json) ? $json : ['raw' => mb_substr($response->body(), 0, 500)];
    }

    private function log(string $operation, ?int $status, bool $ok, float $started, array $request, ?array $response, ?string $error): void
    {
        try {
            IntegrationLog::query()->create([
                'provider' => $this->provider,
                'operation' => $operation,
                'status_code' => $status,
                'success' => $ok,
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
                'request' => $request === [] ? null : self::redact($request),
                'response' => $response === null ? null : self::redact($response),
                'error' => $error !== null ? self::scrub($error) : null,
                'correlation_id' => request()?->attributes->get('correlation_id'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('integration_log.write_failed', ['provider' => $this->provider, 'error' => $e->getMessage()]);
        }
    }

    /** @param array<mixed> $data */
    public static function redact(array $data, int $depth = 0): array
    {
        if ($depth > 6) {
            return ['…'];
        }
        foreach ($data as $key => $value) {
            $k = strtolower((string) $key);
            $sensitive = false;
            foreach (self::REDACT as $needle) {
                if ($k === $needle || str_ends_with($k, '_'.$needle) || str_contains($k, 'secret') || str_contains($k, 'token')) {
                    $sensitive = true;
                    break;
                }
            }
            if ($sensitive) {
                $data[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value, $depth + 1);
            } elseif (is_string($value) && strlen($value) > 500) {
                $data[$key] = substr($value, 0, 100).'…';
            }
        }

        return $data;
    }

    private static function scrub(string $text): string
    {
        // Drop long digit runs (card/account/identity numbers) and bearer tokens from error text.
        $text = preg_replace('/\d{8,}/', '[digits]', $text) ?? $text;

        return preg_replace('/Bearer\s+[A-Za-z0-9._\-]+/', 'Bearer [REDACTED]', $text) ?? $text;
    }
}
