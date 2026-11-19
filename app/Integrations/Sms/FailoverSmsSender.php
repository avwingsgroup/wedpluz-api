<?php

namespace App\Integrations\Sms;

use App\Integrations\Support\IntegrationException;
use Illuminate\Support\Facades\Log;

/**
 * Primary (Termii) with automatic fallback (Twilio).
 *
 * Fails over only for qualifying provider failures (timeouts, 5xx, balance,
 * auth, misconfiguration). Permanent recipient errors (invalid number,
 * unsubscribed) are rethrown immediately, because another provider would
 * fail the same way.
 */
class FailoverSmsSender implements SmsSender
{
    public function __construct(private readonly SmsSender $primary, private readonly ?SmsSender $fallback) {}

    public function name(): string
    {
        return $this->primary->name();
    }

    public function send(string $to, string $message): SmsResult
    {
        try {
            return $this->primary->send($to, $message);
        } catch (IntegrationException $e) {
            if ($e->providerCode === 'invalid_recipient' || $this->fallback === null) {
                throw $e;
            }
            Log::warning('sms.failover', ['from' => $this->primary->name(), 'to' => $this->fallback->name(), 'reason' => $e->getMessage()]);
            $result = $this->fallback->send($to, $message);

            return new SmsResult($result->provider, $result->messageId, $result->status, true);
        }
    }
}
