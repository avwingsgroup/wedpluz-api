<?php

namespace App\Services\Payments;

use App\Integrations\Payments\PaymentGateway;
use App\Integrations\Payments\WebhookPayload;
use App\Jobs\ProcessWebhookJob;
use App\Models\WebhookEvent;
use App\Services\Platform\AlertService;
use App\Support\Sql;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Inbound provider webhooks (integrations.md §2.3):
 *  1. verify the signature over the raw body (401 on failure),
 *  2. deduplicate on (provider, provider_event_id) — a replay returns 200,
 *  3. store the event and queue processing, answering 200 quickly.
 * Repeated signature failures raise an admin alert. A valid signed webhook
 * is never answered with 4xx, because providers retry.
 */
class WebhookService
{
    public function __construct(private readonly AlertService $alerts) {}

    /** @return int HTTP status to answer with */
    public function receivePayment(string $provider, PaymentGateway $gateway, Request $request): int
    {
        if (! $gateway->verifyWebhook($request)) {
            $this->signatureFailure($provider, $request);

            return 401;
        }

        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload)) {
            return 200;
        }

        $normalised = $gateway->parseWebhook($payload, $request);
        if ($normalised === null) {
            $this->store($provider, 'ignored:'.hash('sha256', $request->getContent()), (string) ($payload['event'] ?? $payload['type'] ?? $payload['eventType'] ?? $payload['event_type'] ?? 'unknown'), $payload, 'ignored');

            return 200;
        }

        $event = $this->store($provider, $normalised->eventId, $normalised->type, ['normalised' => [
            'type' => $normalised->type,
            'reference' => $normalised->reference,
            'providerType' => $normalised->providerType,
            'data' => $normalised->data,
        ], 'raw' => self::scrub($payload)], 'received');

        if ($event !== null) {
            ProcessWebhookJob::dispatch($event->id);
        }

        return 200;
    }

    /** Store a generic (non-payment) webhook event and queue its handler. */
    public function receiveGeneric(string $provider, string $eventId, string $type, array $payload): ?WebhookEvent
    {
        $event = $this->store($provider, $eventId, $type, self::scrub($payload), 'received');
        if ($event !== null) {
            ProcessWebhookJob::dispatch($event->id);
        }

        return $event;
    }

    public function signatureFailure(string $provider, Request $request): void
    {
        Log::warning('webhook.signature_invalid', ['provider' => $provider, 'ip' => $request->ip()]);
        $key = 'webhook_sig_fail:'.$provider;
        $count = Cache::increment($key);
        if ($count === 1) {
            Cache::put($key, 1, 3600);
        }
        if ($count === 10) {
            $this->alerts->raise('critical', 'Repeated webhook signature failures', '10 '.$provider.' webhooks failed signature verification in the last hour. Check the webhook secret or investigate spoofing.', null, 'webhook-sig:'.$provider.':'.now()->format('YmdH'));
        }
    }

    private function store(string $provider, string $eventId, string $type, array $payload, string $status): ?WebhookEvent
    {
        return Sql::insertOrNull(fn () => WebhookEvent::query()->create([
            'provider' => $provider,
            'provider_event_id' => mb_substr($eventId, 0, 191),
            'type' => mb_substr($type, 0, 100),
            'signature_valid' => true,
            'payload' => $payload,
            'status' => $status,
        ]));
    }

    /** Remove card and identity data from stored payloads. */
    public static function scrub(array $payload, int $depth = 0): array
    {
        foreach ($payload as $key => $value) {
            $k = strtolower((string) $key);
            if (in_array($k, ['authorization', 'card', 'bin', 'last4', 'signature', 'cvv', 'pan', 'account_number', 'accountnumber', 'bvn', 'nin', 'image', 'selfie'], true)) {
                $payload[$key] = '[REDACTED]';
            } elseif (is_array($value) && $depth < 8) {
                $payload[$key] = self::scrub($value, $depth + 1);
            }
        }

        return $payload;
    }

    public static function payload(WebhookEvent $event): ?WebhookPayload
    {
        $n = $event->payload['normalised'] ?? null;

        return $n ? new WebhookPayload($event->provider_event_id, $n['type'], $n['reference'], $n['providerType'], $n['data'] ?? []) : null;
    }
}
