<?php

namespace App\Integrations\Payments;

use App\Integrations\Support\IntegrationException;
use App\Integrations\Support\ProviderHttp;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * PayPal Orders v2 (intent CAPTURE). The buyer approves on PayPal; on verify
 * we capture an APPROVED order server-side and read the capture status.
 * Webhooks are verified via POST /v1/notifications/verify-webhook-signature.
 */
class PayPalGateway implements PaymentGateway
{
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'paypal';
    }

    public function currencies(): array
    {
        return ['USD', 'GBP', 'EUR'];
    }

    public function isConfigured(): bool
    {
        return ! empty($this->config['client_id']) && ! empty($this->config['client_secret']);
    }

    public function initialize(PaymentRequest $request): PaymentInitResult
    {
        $separator = str_contains($request->returnUrl, '?') ? '&' : '?';
        $response = $this->call('orders.create', fn (PendingRequest $http) => $http
            ->withHeaders(['PayPal-Request-Id' => 'init-'.$request->reference])
            ->post($this->url('/v2/checkout/orders'), [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => $request->reference,
                    'custom_id' => $request->reference,
                    'description' => mb_substr($request->description, 0, 127),
                    'amount' => ['currency_code' => $request->currency, 'value' => Money::toMajor($request->amount)],
                ]],
                'payment_source' => ['paypal' => ['experience_context' => [
                    'brand_name' => 'Wedpluz',
                    'user_action' => 'PAY_NOW',
                    'shipping_preference' => 'NO_SHIPPING',
                    'return_url' => $request->returnUrl.$separator.'reference='.urlencode($request->reference),
                    'cancel_url' => $request->returnUrl.$separator.'reference='.urlencode($request->reference).'&cancelled=1',
                ]]],
            ]), ['reference' => $request->reference, 'amount' => $request->amount]);

        $this->ensureOk($response, 'orders.create');
        $approve = collect($response->json('links', []))->first(fn ($l) => in_array($l['rel'] ?? '', ['payer-action', 'approve'], true));

        return new PaymentInitResult(
            $approve['href'] ?? null,
            $request->reference,
            (string) $response->json('id'),
            array_filter(['clientId' => $this->config['client_id'] ?? null, 'orderId' => $response->json('id')]),
        );
    }

    public function verify(Payment $payment): PaymentVerification
    {
        if (! $payment->provider_transaction_id) {
            return new PaymentVerification(PaymentVerification::PENDING);
        }
        $orderUrl = $this->url('/v2/checkout/orders/'.rawurlencode($payment->provider_transaction_id));
        $order = $this->call('orders.get', fn (PendingRequest $http) => $http->get($orderUrl));
        $this->ensureOk($order, 'orders.get');

        $status = (string) $order->json('status');
        if ($status === 'APPROVED') {
            $captured = $this->call('orders.capture', fn (PendingRequest $http) => $http
                ->withHeaders(['PayPal-Request-Id' => 'capture-'.$payment->provider_reference])
                ->withBody('{}', 'application/json')
                ->post($orderUrl.'/capture'));
            if ($captured->successful()) {
                $order = $captured;
                $status = (string) $order->json('status');
            } elseif ($captured->json('details.0.issue') === 'INSTRUMENT_DECLINED' || $captured->status() === 422) {
                return new PaymentVerification(PaymentVerification::FAILED, null, null, null, 0, [], 'PayPal declined the payment.');
            }
        }

        $capture = (array) $order->json('purchase_units.0.payments.captures.0', []);
        $captureStatus = (string) ($capture['status'] ?? '');
        $result = match (true) {
            $status === 'COMPLETED' && $captureStatus === 'COMPLETED' => PaymentVerification::SUCCEEDED,
            in_array($captureStatus, ['DECLINED', 'FAILED'], true), $status === 'VOIDED' => PaymentVerification::FAILED,
            default => PaymentVerification::PENDING,
        };

        return new PaymentVerification(
            $result,
            isset($capture['amount']['value']) ? Money::fromMajor($capture['amount']['value']) : null,
            $capture['amount']['currency_code'] ?? null,
            (string) $payment->provider_transaction_id,
            Money::fromMajor($capture['seller_receivable_breakdown']['paypal_fee']['value'] ?? 0),
            ['order_status' => $status, 'capture_id' => $capture['id'] ?? null, 'capture_status' => $captureStatus],
            $result === PaymentVerification::FAILED ? 'PayPal payment was not completed.' : null,
        );
    }

    public function refund(Payment $payment, int $amount, string $reference, string $reason): RefundResult
    {
        $captureId = $payment->raw_payload['capture_id'] ?? null;
        if (! $captureId) {
            return new RefundResult('failed', null, 'Capture not found for this payment.');
        }
        $response = $this->call('captures.refund', fn (PendingRequest $http) => $http
            ->withHeaders(['PayPal-Request-Id' => 'refund-'.$reference])
            ->post($this->url('/v2/payments/captures/'.rawurlencode($captureId).'/refund'), [
                'amount' => ['value' => Money::toMajor($amount), 'currency_code' => $payment->currency],
                'invoice_id' => $reference,
                'note_to_payer' => mb_substr($reason, 0, 255),
            ]), ['reference' => $reference, 'amount' => $amount]);

        if (! $response->successful()) {
            if ($response->serverError()) {
                throw IntegrationException::transient('paypal', 'refund', 'PayPal is unavailable.');
            }

            return new RefundResult('failed', null, (string) ($response->json('details.0.description') ?? $response->json('message') ?? 'Refund was rejected.'));
        }
        $status = (string) $response->json('status');

        return new RefundResult($status === 'COMPLETED' ? 'completed' : ($status === 'CANCELLED' || $status === 'FAILED' ? 'failed' : 'processing'), (string) $response->json('id'));
    }

    public function verifyWebhook(Request $request): bool
    {
        if (! $this->isConfigured() || empty($this->config['webhook_id'])) {
            return false;
        }
        $event = json_decode($request->getContent(), true);
        if (! is_array($event)) {
            return false;
        }

        try {
            $response = $this->call('webhooks.verify', fn (PendingRequest $http) => $http->post($this->url('/v1/notifications/verify-webhook-signature'), [
                'auth_algo' => $request->header('PAYPAL-AUTH-ALGO'),
                'cert_url' => $request->header('PAYPAL-CERT-URL'),
                'transmission_id' => $request->header('PAYPAL-TRANSMISSION-ID'),
                'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG'),
                'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
                'webhook_id' => $this->config['webhook_id'],
                'webhook_event' => $event,
            ]));
        } catch (IntegrationException) {
            return false;
        }

        return $response->successful() && $response->json('verification_status') === 'SUCCESS';
    }

    public function parseWebhook(array $payload, Request $request): ?WebhookPayload
    {
        $type = (string) ($payload['event_type'] ?? '');
        $resource = (array) ($payload['resource'] ?? []);
        $eventId = 'paypal:'.($payload['id'] ?? md5(json_encode($payload)));
        $reference = $resource['custom_id'] ?? $resource['invoice_id'] ?? null;

        return match ($type) {
            'PAYMENT.CAPTURE.COMPLETED' => new WebhookPayload($eventId, WebhookPayload::PAYMENT_SUCCEEDED, $reference, $type, ['capture_id' => $resource['id'] ?? null]),
            'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.DECLINED' => new WebhookPayload($eventId, WebhookPayload::PAYMENT_FAILED, $reference, $type, ['capture_id' => $resource['id'] ?? null]),
            'PAYMENT.CAPTURE.REFUNDED' => new WebhookPayload($eventId, WebhookPayload::REFUND_COMPLETED, (string) ($resource['id'] ?? ''), $type, ['invoice_id' => $resource['invoice_id'] ?? null]),
            'CUSTOMER.DISPUTE.CREATED' => new WebhookPayload($eventId, WebhookPayload::DISPUTE_CREATED, $resource['disputed_transactions'][0]['custom'] ?? null, $type, ['dispute_id' => $resource['dispute_id'] ?? null, 'reason' => $resource['reason'] ?? null]),
            default => null,
        };
    }

    private function accessToken(): string
    {
        return Cache::remember('paypal:token:'.md5((string) $this->config['client_id']), 3000, function () {
            $response = ProviderHttp::for('paypal', 15)->call(
                'oauth.token',
                fn (PendingRequest $http) => $http->withBasicAuth($this->config['client_id'], $this->config['client_secret'])
                    ->asForm()->post($this->url('/v1/oauth2/token'), ['grant_type' => 'client_credentials']),
            );
            if (! $response->successful() || ! $response->json('access_token')) {
                throw IntegrationException::transient('paypal', 'oauth', 'Could not authenticate with PayPal.');
            }

            return (string) $response->json('access_token');
        });
    }

    private function call(string $operation, callable $send, array $log = []): Response
    {
        if (! $this->isConfigured()) {
            throw IntegrationException::notConfigured('paypal');
        }
        $token = $this->accessToken();

        return ProviderHttp::for('paypal', (int) ($this->config['timeout'] ?? 20))->call(
            $operation,
            fn (PendingRequest $http) => $send($http->withToken($token)),
            $log,
        );
    }

    private function url(string $path): string
    {
        return rtrim($this->config['base_url'] ?? 'https://api-m.sandbox.paypal.com', '/').$path;
    }

    private function ensureOk(Response $response, string $operation): void
    {
        if (! $response->successful()) {
            throw IntegrationException::fromStatus('paypal', $operation, $response->status(), (string) ($response->json('message') ?? 'PayPal request failed.'));
        }
    }
}
