<?php

namespace App\Integrations\Payments;

use App\Integrations\Support\IntegrationException;
use App\Integrations\Support\ProviderHttp;
use App\Models\Payment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;

/**
 * Stripe Checkout Sessions (hosted). Also serves Google Pay: Checkout shows
 * Google Pay automatically for card payments when enabled in the Stripe
 * dashboard, so `google_pay` maps to this gateway (no standalone Google Pay
 * integration is assumed for every market).
 *
 * Webhooks: `Stripe-Signature` (t=..., v1=HMAC-SHA256("{t}.{payload}")),
 * rejected outside a 5-minute tolerance.
 */
class StripeGateway implements PaymentGateway
{
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'stripe';
    }

    public function currencies(): array
    {
        return ['USD', 'GBP', 'EUR', 'NGN'];
    }

    public function isConfigured(): bool
    {
        return ! empty($this->config['secret_key']);
    }

    public function initialize(PaymentRequest $request): PaymentInitResult
    {
        $separator = str_contains($request->returnUrl, '?') ? '&' : '?';
        $form = [
            'mode' => 'payment',
            'success_url' => $request->returnUrl.$separator.'reference='.urlencode($request->reference).'&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $request->returnUrl.$separator.'reference='.urlencode($request->reference).'&cancelled=1',
            'client_reference_id' => $request->reference,
            'customer_email' => $request->email,
            'line_items[0][quantity]' => 1,
            'line_items[0][price_data][currency]' => strtolower($request->currency),
            'line_items[0][price_data][unit_amount]' => $request->amount,
            'line_items[0][price_data][product_data][name]' => mb_substr($request->description, 0, 120),
            'metadata[reference]' => $request->reference,
            'payment_intent_data[metadata][reference]' => $request->reference,
            'payment_method_types[0]' => 'card',
            'expires_at' => time() + 1800,
        ];

        $response = $this->call('checkout.sessions.create', fn (PendingRequest $http) => $http->asForm()
            ->withHeaders(['Idempotency-Key' => 'init-'.$request->reference])
            ->post($this->url('/v1/checkout/sessions'), $form), ['reference' => $request->reference, 'amount' => $request->amount]);

        $this->ensureOk($response, 'checkout.sessions.create');

        return new PaymentInitResult(
            (string) $response->json('url'),
            $request->reference,
            (string) $response->json('id'),
            array_filter(['publishableKey' => $this->config['publishable_key'] ?? null, 'sessionId' => $response->json('id')]),
        );
    }

    public function verify(Payment $payment): PaymentVerification
    {
        if (! $payment->provider_transaction_id) {
            return new PaymentVerification(PaymentVerification::PENDING);
        }
        $response = $this->call('checkout.sessions.retrieve', fn (PendingRequest $http) => $http->get(
            $this->url('/v1/checkout/sessions/'.rawurlencode($payment->provider_transaction_id)),
            ['expand' => ['payment_intent.latest_charge.balance_transaction']],
        ));
        $this->ensureOk($response, 'checkout.sessions.retrieve');

        $session = (array) $response->json();
        $intent = is_array($session['payment_intent'] ?? null) ? $session['payment_intent'] : [];
        $status = match (true) {
            ($session['payment_status'] ?? null) === 'paid' => PaymentVerification::SUCCEEDED,
            ($session['status'] ?? null) === 'expired', ($intent['status'] ?? null) === 'canceled' => PaymentVerification::FAILED,
            default => PaymentVerification::PENDING,
        };
        $fee = (int) ($intent['latest_charge']['balance_transaction']['fee'] ?? 0);

        return new PaymentVerification(
            $status,
            isset($session['amount_total']) ? (int) $session['amount_total'] : null,
            isset($session['currency']) ? strtoupper((string) $session['currency']) : null,
            (string) $payment->provider_transaction_id,
            $fee,
            ['session_status' => $session['status'] ?? null, 'payment_status' => $session['payment_status'] ?? null, 'payment_intent' => $intent['id'] ?? null],
            $status === PaymentVerification::FAILED ? 'Checkout session expired or was cancelled.' : null,
        );
    }

    public function refund(Payment $payment, int $amount, string $reference, string $reason): RefundResult
    {
        $intent = $payment->raw_payload['payment_intent'] ?? null;
        if (! $intent) {
            $verification = $this->verify($payment);
            $intent = $verification->raw['payment_intent'] ?? null;
        }
        if (! $intent) {
            return new RefundResult('failed', null, 'Payment intent not found.');
        }

        $response = $this->call('refunds.create', fn (PendingRequest $http) => $http->asForm()
            ->withHeaders(['Idempotency-Key' => 'refund-'.$reference])
            ->post($this->url('/v1/refunds'), [
                'payment_intent' => $intent,
                'amount' => $amount,
                'metadata[reference]' => $reference,
                'reason' => 'requested_by_customer',
            ]), ['reference' => $reference, 'amount' => $amount]);

        if (! $response->successful()) {
            if ($response->serverError()) {
                throw IntegrationException::transient('stripe', 'refund', 'Stripe is unavailable.');
            }

            return new RefundResult('failed', null, (string) ($response->json('error.message') ?? 'Refund was rejected.'));
        }
        $status = (string) $response->json('status');

        return new RefundResult($status === 'succeeded' ? 'completed' : ($status === 'failed' || $status === 'canceled' ? 'failed' : 'processing'), (string) $response->json('id'));
    }

    public function verifyWebhook(Request $request): bool
    {
        $secret = (string) ($this->config['webhook_secret'] ?? '');
        $header = (string) $request->header('Stripe-Signature');
        if ($secret === '' || $header === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't') {
                $timestamp = (int) $v;
            } elseif ($k === 'v1') {
                $signatures[] = $v;
            }
        }
        if (! $timestamp || $signatures === [] || abs(time() - $timestamp) > (int) ($this->config['webhook_tolerance'] ?? 300)) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    public function parseWebhook(array $payload, Request $request): ?WebhookPayload
    {
        $type = (string) ($payload['type'] ?? '');
        $object = (array) ($payload['data']['object'] ?? []);
        $eventId = 'stripe:'.($payload['id'] ?? md5(json_encode($payload)));
        $reference = $object['metadata']['reference'] ?? $object['client_reference_id'] ?? null;

        return match ($type) {
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' => ($object['payment_status'] ?? null) === 'paid'
                ? new WebhookPayload($eventId, WebhookPayload::PAYMENT_SUCCEEDED, $reference, $type, ['payment_intent' => $object['payment_intent'] ?? null, 'id' => $object['id'] ?? null])
                : null,
            'payment_intent.succeeded' => new WebhookPayload($eventId, WebhookPayload::PAYMENT_SUCCEEDED, $reference, $type, ['payment_intent' => $object['id'] ?? null]),
            'payment_intent.payment_failed', 'checkout.session.expired', 'checkout.session.async_payment_failed' => new WebhookPayload($eventId, WebhookPayload::PAYMENT_FAILED, $reference, $type, ['id' => $object['id'] ?? null]),
            'charge.refunded' => new WebhookPayload($eventId, WebhookPayload::REFUND_COMPLETED, (string) ($object['refunds']['data'][0]['id'] ?? ''), $type, ['payment_intent' => $object['payment_intent'] ?? null, 'amount_refunded' => $object['amount_refunded'] ?? null]),
            'refund.updated' => match ($object['status'] ?? null) {
                'succeeded' => new WebhookPayload($eventId, WebhookPayload::REFUND_COMPLETED, (string) ($object['id'] ?? ''), $type, []),
                'failed', 'canceled' => new WebhookPayload($eventId, WebhookPayload::REFUND_FAILED, (string) ($object['id'] ?? ''), $type, ['failure_reason' => $object['failure_reason'] ?? null]),
                default => null,
            },
            'charge.dispute.created' => new WebhookPayload($eventId, WebhookPayload::DISPUTE_CREATED, null, $type, ['payment_intent' => $object['payment_intent'] ?? null, 'dispute_id' => $object['id'] ?? null, 'reason' => $object['reason'] ?? null]),
            default => null,
        };
    }

    private function call(string $operation, callable $send, array $log = []): Response
    {
        if (! $this->isConfigured()) {
            throw IntegrationException::notConfigured('stripe');
        }

        return ProviderHttp::for('stripe', (int) ($this->config['timeout'] ?? 20))->call(
            $operation,
            fn (PendingRequest $http) => $send($http->withToken($this->config['secret_key'])),
            $log,
        );
    }

    private function url(string $path): string
    {
        return rtrim($this->config['base_url'] ?? 'https://api.stripe.com', '/').$path;
    }

    private function ensureOk(Response $response, string $operation): void
    {
        if (! $response->successful()) {
            throw IntegrationException::fromStatus('stripe', $operation, $response->status(), (string) ($response->json('error.message') ?? 'Stripe request failed.'));
        }
    }
}
