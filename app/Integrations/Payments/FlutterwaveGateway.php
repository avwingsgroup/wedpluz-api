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
 * Flutterwave v3 Standard (hosted checkout) and Transfers.
 * Flutterwave uses MAJOR units; we convert from minor units here.
 * Webhooks carry a `verif-hash` header equal to the configured secret hash.
 */
class FlutterwaveGateway implements PaymentGateway, TransferGateway
{
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'flutterwave';
    }

    public function currencies(): array
    {
        return ['NGN', 'USD', 'GBP', 'EUR'];
    }

    public function isConfigured(): bool
    {
        return ! empty($this->config['secret_key']);
    }

    public function initialize(PaymentRequest $request): PaymentInitResult
    {
        $response = $this->call('payments.create', fn (PendingRequest $http) => $http->post($this->url('/payments'), [
            'tx_ref' => $request->reference,
            'amount' => Money::toMajor($request->amount),
            'currency' => $request->currency,
            'redirect_url' => $request->returnUrl,
            'customer' => ['email' => $request->email, 'name' => $request->customerName],
            'customizations' => ['title' => 'Wedpluz', 'description' => mb_substr($request->description, 0, 100)],
            'meta' => ['reference' => $request->reference] + $request->metadata,
        ]), ['reference' => $request->reference, 'amount' => $request->amount]);

        $this->ensureOk($response, 'payments.create');

        return new PaymentInitResult((string) $response->json('data.link'), $request->reference, null, array_filter(['publicKey' => $this->config['public_key'] ?? null]));
    }

    public function verify(Payment $payment): PaymentVerification
    {
        $response = $this->call('transactions.verify', fn (PendingRequest $http) => $http->get($this->url('/transactions/verify_by_reference'), ['tx_ref' => $payment->provider_reference]));

        if ($response->status() === 404 || $response->status() === 400) {
            return new PaymentVerification(PaymentVerification::PENDING);
        }
        $this->ensureOk($response, 'transactions.verify');

        $data = (array) $response->json('data');
        $status = match (strtolower((string) ($data['status'] ?? ''))) {
            'successful' => PaymentVerification::SUCCEEDED,
            'failed', 'cancelled' => PaymentVerification::FAILED,
            default => PaymentVerification::PENDING,
        };

        return new PaymentVerification(
            $status,
            isset($data['amount']) ? Money::fromMajor($data['amount']) : null,
            isset($data['currency']) ? strtoupper((string) $data['currency']) : null,
            isset($data['id']) ? (string) $data['id'] : null,
            Money::fromMajor($data['app_fee'] ?? 0),
            ['status' => $data['status'] ?? null, 'processor_response' => $data['processor_response'] ?? null],
            $status === PaymentVerification::FAILED ? (string) ($data['processor_response'] ?? 'Payment failed') : null,
        );
    }

    public function refund(Payment $payment, int $amount, string $reference, string $reason): RefundResult
    {
        if (! $payment->provider_transaction_id) {
            return new RefundResult('failed', null, 'Missing provider transaction id.');
        }
        $response = $this->call('transactions.refund', fn (PendingRequest $http) => $http->post(
            $this->url('/transactions/'.rawurlencode($payment->provider_transaction_id).'/refund'),
            ['amount' => Money::toMajor($amount), 'comments' => mb_substr($reason, 0, 200)],
        ), ['reference' => $reference, 'amount' => $amount]);

        if (! $response->successful() || $response->json('status') !== 'success') {
            if ($response->serverError()) {
                throw IntegrationException::transient('flutterwave', 'refund', 'Flutterwave is unavailable.');
            }

            return new RefundResult('failed', null, (string) ($response->json('message') ?? 'Refund was rejected.'));
        }
        $status = strtolower((string) $response->json('data.status'));

        return new RefundResult(in_array($status, ['completed', 'successful'], true) ? 'completed' : 'processing', (string) $response->json('data.id'));
    }

    public function verifyWebhook(Request $request): bool
    {
        $expected = (string) ($this->config['secret_hash'] ?? '');
        $given = (string) $request->header('verif-hash');

        return $expected !== '' && $given !== '' && hash_equals($expected, $given);
    }

    public function parseWebhook(array $payload, Request $request): ?WebhookPayload
    {
        $event = (string) ($payload['event'] ?? $payload['event.type'] ?? '');
        $data = (array) ($payload['data'] ?? []);
        $status = strtolower((string) ($data['status'] ?? ''));
        $eventId = 'flutterwave:'.$event.':'.($data['id'] ?? md5(json_encode($data))).':'.$status;

        return match (true) {
            $event === 'charge.completed' && $status === 'successful' => new WebhookPayload($eventId, WebhookPayload::PAYMENT_SUCCEEDED, $data['tx_ref'] ?? null, $event, $data),
            $event === 'charge.completed' && $status === 'failed' => new WebhookPayload($eventId, WebhookPayload::PAYMENT_FAILED, $data['tx_ref'] ?? null, $event, $data),
            $event === 'transfer.completed' && $status === 'successful' => new WebhookPayload($eventId, WebhookPayload::TRANSFER_SUCCEEDED, $data['reference'] ?? null, $event, $data),
            $event === 'transfer.completed' && $status === 'failed' => new WebhookPayload($eventId, WebhookPayload::TRANSFER_FAILED, $data['reference'] ?? null, $event, $data),
            str_starts_with($event, 'refund') && in_array($status, ['completed', 'successful'], true) => new WebhookPayload($eventId, WebhookPayload::REFUND_COMPLETED, (string) ($data['id'] ?? ''), $event, $data),
            str_starts_with($event, 'refund') && $status === 'failed' => new WebhookPayload($eventId, WebhookPayload::REFUND_FAILED, (string) ($data['id'] ?? ''), $event, $data),
            default => null,
        };
    }

    public function banks(): array
    {
        return Cache::remember('flutterwave:banks:ng', 86400, function () {
            $response = $this->call('banks.list', fn (PendingRequest $http) => $http->get($this->url('/banks/NG')));
            $this->ensureOk($response, 'banks.list');

            return collect($response->json('data', []))->map(fn ($b) => ['code' => (string) $b['code'], 'name' => (string) $b['name']])->unique('code')->values()->all();
        });
    }

    public function resolveAccount(string $bankCode, string $accountNumber): array
    {
        $response = $this->call('accounts.resolve', fn (PendingRequest $http) => $http->post($this->url('/accounts/resolve'), [
            'account_number' => $accountNumber,
            'account_bank' => $bankCode,
        ]));
        if (! $response->successful() || ! $response->json('data.account_name')) {
            if ($response->serverError()) {
                throw IntegrationException::transient('flutterwave', 'accounts.resolve', 'Bank verification is temporarily unavailable.');
            }
            throw IntegrationException::permanent('flutterwave', 'accounts.resolve', 'We could not verify this account number.', 'unresolvable');
        }

        return ['accountName' => (string) $response->json('data.account_name'), 'bankName' => null];
    }

    public function transfer(TransferRequest $request): TransferResult
    {
        $response = $this->call('transfers.create', fn (PendingRequest $http) => $http->post($this->url('/transfers'), [
            'account_bank' => $request->bankCode,
            'account_number' => $request->accountNumber,
            'amount' => Money::toMajor($request->amount),
            'currency' => $request->currency,
            'reference' => $request->reference,
            'narration' => mb_substr($request->narration, 0, 100),
            'debit_currency' => $request->currency,
        ]), ['reference' => $request->reference, 'amount' => $request->amount]);

        if (! $response->successful() || $response->json('status') !== 'success') {
            if ($response->serverError()) {
                throw IntegrationException::transient('flutterwave', 'transfer', 'Flutterwave is unavailable.');
            }

            return new TransferResult('failed', null, null, (string) ($response->json('message') ?? 'Transfer rejected.'));
        }
        $status = strtoupper((string) $response->json('data.status'));

        return new TransferResult($status === 'SUCCESSFUL' ? 'successful' : ($status === 'FAILED' ? 'failed' : 'processing'), (string) $response->json('data.id'));
    }

    private function call(string $operation, callable $send, array $log = []): Response
    {
        if (! $this->isConfigured()) {
            throw IntegrationException::notConfigured('flutterwave');
        }

        return ProviderHttp::for('flutterwave', (int) ($this->config['timeout'] ?? 20))->call(
            $operation,
            fn (PendingRequest $http) => $send($http->withToken($this->config['secret_key'])),
            $log,
        );
    }

    private function url(string $path): string
    {
        return rtrim($this->config['base_url'] ?? 'https://api.flutterwave.com/v3', '/').$path;
    }

    private function ensureOk(Response $response, string $operation): void
    {
        if (! $response->successful() || $response->json('status') === 'error') {
            throw IntegrationException::fromStatus('flutterwave', $operation, $response->status(), (string) ($response->json('message') ?? 'Flutterwave request failed.'));
        }
    }
}
