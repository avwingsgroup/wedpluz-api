<?php

namespace App\Integrations\Support;

use RuntimeException;
use Throwable;

/**
 * A provider call failed. `transient` failures (timeouts, 5xx, rate limits)
 * may be retried or failed over; permanent failures (invalid recipient,
 * declined, bad request) must not be.
 */
class IntegrationException extends RuntimeException
{
    public function __construct(
        public readonly string $provider,
        public readonly string $operation,
        string $message,
        public readonly bool $transient,
        public readonly ?string $providerCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function transient(string $provider, string $operation, string $message, ?string $code = null, ?Throwable $previous = null): self
    {
        return new self($provider, $operation, $message, true, $code, $previous);
    }

    public static function permanent(string $provider, string $operation, string $message, ?string $code = null): self
    {
        return new self($provider, $operation, $message, false, $code);
    }

    public static function notConfigured(string $provider): self
    {
        return new self($provider, 'configure', ucfirst($provider).' is not configured.', false, 'not_configured');
    }

    public static function fromStatus(string $provider, string $operation, int $status, string $message, ?string $code = null): self
    {
        return new self($provider, $operation, $message, $status >= 500 || $status === 429 || $status === 408, $code);
    }
}
