<?php

namespace App\Integrations\Payments;

use App\Integrations\Support\IntegrationException;
use App\Integrations\Support\ProviderHttp;
use App\Models\Payment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Paystack: primary NGN card, bank transfer and USSD; Transfers API for payouts.
 * Amounts are sent in kobo (minor units). Webhooks are signed with
 * HMAC-SHA512 of the raw body using the secret key (`x-paystack-signature`).
 */
class PaystackGateway implements PaymentGateway, TransferGateway
{
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'paystack';
    }

    public function currencies(): array
    {
        return ['NGN', 'USD'];
    }

    public function isConfigured(): bool
    {
        return ! empty($this->config['secret_key']);
    }

    public function initialize(PaymentRequest $request): PaymentInitResult
    {
        $response = $this->call('transaction.initialize', fn (PendingRequest $http) => $http->post($this->url('/transaction/initialize'), [
            'email' => $request->email,
            'amount' => $request->amount,
            'currency' => $request->currency,
            'reference' => $request->reference,
            'callback_url' => $request->returnUrl,
            'metadata' => ['reference' => $request->reference, 'description' => $request->description] + $request->metadata,
        ]), ['reference' => $request->reference, 'amount' => $request->amount]);

        $this->ensureOk($response, 'transaction.initialize');

        return new PaymentInitResult(
            (string) $response->json('data.authorization_url'),
            $request->reference, // we set the reference; never trust an echoed value
            null,
            array_filter(['publicKey' => $this->config['public_key'] ?? null, 'accessCode' => $response->json('data.access_code')]),
        );
    }

    public function verify(Payment $payment): PaymentVerification
    {
        $response = $this->call('transaction.verify', fn (PendingRequest $http) => $http->get($this->url('/transaction/verify/'.rawurlencode($payment->provider_reference))));

        if ($response->status() === 404) {
            return new PaymentVerification(PaymentVerification::PENDING);
        }
        $this->ensureOk($response, 'transaction.verify');

        $data = (array) $response->json('data');
        $status = match ($data['status'] ?? null) {
            'success' => PaymentVerification::SUCCEEDED,
            'failed', 'reversed' => PaymentVerification::FAILED,
            default => PaymentVerification::PENDING, // abandoned, ongoing, pending, processing, queued
        };

        return new PaymentVerification(
            $status,
            isset($data['amount']) ? (int) $data['amount'] : null,
            isset($data['currency']) ? strtoupper((string) $data['currency']) : null,
            isset($data['id']) ? (string) $data['id'] : null,
            (int) ($data['fees'] ?? 0),
            ['status' => $data['status'] ?? null, 'channel' => $data['channel'] ?? null, 'gateway_response' => $data['gateway_response'] ?? null, 'paid_at' => $data['paid_at'] ?? null],
            $status === PaymentVerification::FAILED ? (string) ($data['gateway_response'] ?? 'Payment failed') : null,
        );
    }

    public function refund(Payment $payment, int $amount, string $reference, string $reason): RefundResult
    {
        $response = $this->call('refund.create', fn (PendingRequest $http) => $http->post($this->url('/refund'), [
            'transaction' => $payment->provider_transaction_id ?: $payment->provider_reference,
            'amount' => $amount,
            'merchant_note' => mb_substr($reason, 0, 200),
            'customer_note' => 'Wedpluz refund '.$reference,
        ]), ['reference' => $reference, 'amount' => $amount]);

        if (! $response->successful() || $response->json('status') !== true) {
            if ($response->serverError()) {
                throw IntegrationException::transient('paystack', 'refund', 'Paystack is unavailable.');
            }

            return new RefundResult('failed', null, (string) ($response->json('message') ?? 'Refund was rejected.'));
        }

        $status = $response->json('data.status');

        return new RefundResult($status === 'processed' ? 'completed' : 'processing', (string) $response->json('data.id'));
    }

    public function verifyWebhook(Request $request): bool
    {
        $signature = (string) $request->header('x-paystack-signature');
        $secret = (string) ($this->config['secret_key'] ?? '');

        return $secret !== '' && $signature !== '' && hash_equals(hash_hmac('sha512', $request->getContent(), $secret), $signature);
    }

    public function parseWebhook(array $payload, Request $request): ?WebhookPayload
    {
        $event = (string) ($payload['event'] ?? '');
        $data = (array) ($payload['data'] ?? []);
        $eventId = 'paystack:'.$event.':'.($data['id'] ?? $data['reference'] ?? md5(json_encode($data))).':'.($data['status'] ?? '');

        return match ($event) {
            'charge.success' => new WebhookPayload($eventId, WebhookPayload::PAYMENT_SUCCEEDED, $data['reference'] ?? null, $event, $data),
            'charge.failed' => new WebhookPayload($eventId, WebhookPayload::PAYMENT_FAILED, $data['reference'] ?? null, $event, $data),
            'refund.processed' => new WebhookPayload($eventId, WebhookPayload::REFUND_COMPLETED, (string) ($data['id'] ?? $data['refund_reference'] ?? ''), $event, $data),
            'refund.failed' => new WebhookPayload($eventId, WebhookPayload::REFUND_FAILED, (string) ($data['id'] ?? $data['refund_reference'] ?? ''), $event, $data),
            'transfer.success' => new WebhookPayload($eventId, WebhookPayload::TRANSFER_SUCCEEDED, $data['reference'] ?? null, $event, $data),
            'transfer.failed' => new WebhookPayload($eventId, WebhookPayload::TRANSFER_FAILED, $data['reference'] ?? null, $event, $data),
            'transfer.reversed' => new WebhookPayload($eventId, WebhookPayload::TRANSFER_REVERSED, $data['reference'] ?? null, $event, $data),
            'charge.dispute.create' => new WebhookPayload($eventId, WebhookPayload::DISPUTE_CREATED, $data['transaction']['reference'] ?? null, $event, $data),
            default => null,
        };
    }

    // ---- Transfers -------------------------------------------------------------

    public function banks(): array
    {
        return Cache::remember('paystack:banks:ng', 86400, function () {
            $response = $this->call('bank.list', fn (PendingRequest $http) => $http->get($this->url('/bank'), ['country' => 'nigeria', 'perPage' => 200]));
            $this->ensureOk($response, 'bank.list');

            return collect($response->json('data', []))
                ->filter(fn ($b) => ($b['active'] ?? true) && ! ($b['is_deleted'] ?? false))
                ->map(fn ($b) => ['code' => (string) $b['code'], 'name' => (string) $b['name']])
                ->unique('code')->values()->all();
        });
    }

    public function resolveAccount(string $bankCode, string $accountNumber): array
    {
        $response = $this->call('bank.resolve', fn (PendingRequest $http) => $http->get($this->url('/bank/resolve'), [
            'account_number' => $accountNumber,
            'bank_code' => $bankCode,
        ]));

        if (! $response->successful() || ! $response->json('data.account_name')) {
            if ($response->serverError()) {
                throw IntegrationException::transient('paystack', 'bank.resolve', 'Bank verification is temporarily unavailable.');
            }
            throw IntegrationException::permanent('paystack', 'bank.resolve', 'We could not verify this account number.', 'unresolvable');
        }

        return ['accountName' => (string) $response->json('data.account_name'), 'bankName' => null];
    }

    public function transfer(TransferRequest $request): TransferResult
    {
        $recipient = $request->recipientCode;
        if (! $recipient) {
            $created = $this->call('transferrecipient.create', fn (PendingRequest $http) => $http->post($this->url('/transferrecipient'), [
                'type' => 'nuban',
                'name' => $request->accountName,
                'account_number' => $request->accountNumber,
                'bank_code' => $request->bankCode,
                'currency' => $request->currency,
            ]));
            $this->ensureOk($created, 'transferrecipient.create');
            $recipient = (string) $created->json('data.recipient_code');
        }

        $response = $this->call('transfer.create', fn (PendingRequest $http) => $http->post($this->url('/transfer'), [
            'source' => 'balance',
            'amount' => $request->amount,
            'currency' => $request->currency,
            'recipient' => $recipient,
            'reference' => $request->reference,
            'reason' => mb_substr($request->narration, 0, 100),
        ]), ['reference' => $request->reference, 'amount' => $request->amount]);

        if (! $response->successful()) {
            if ($response->serverError()) {
                throw IntegrationException::transient('paystack', 'transfer', 'Paystack is unavailable.');
            }

            return new TransferResult('failed', null, $recipient, (string) ($response->json('message') ?? 'Transfer rejected.'));
        }

        $status = match ($response->json('data.status')) {
            'success' => 'successful',
            'failed', 'reversed' => 'failed',
            default => 'processing', // pending, otp, received
        };

        return new TransferResult($status, (string) $response->json('data.transfer_code'), $recipient);
    }

    // ---- Helpers ---------------------------------------------------------------

    private function call(string $operation, callable $send, array $log = []): Response
    {
        if (! $this->isConfigured()) {
            throw IntegrationException::notConfigured('paystack');
        }

        return ProviderHttp::for('paystack', (int) ($this->config['timeout'] ?? 20))->call(
            $operation,
            fn (PendingRequest $http) => $send($http->withToken($this->config['secret_key'])),
            $log,
        );
    }

    private function url(string $path): string
    {
        return rtrim($this->config['base_url'] ?? 'https://api.paystack.co', '/').$path;
    }

    private function ensureOk(Response $response, string $operation): void
    {
        if (! $response->successful() || $response->json('status') === false) {
            throw IntegrationException::fromStatus('paystack', $operation, $response->status(), (string) ($response->json('message') ?? 'Paystack request failed.'));
        }
    }
}
