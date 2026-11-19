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
 * Monnify: NGN payments (card, bank transfer) and Disbursements for payouts.
 * OAuth bearer from /api/v1/auth/login (cached). Amounts in MAJOR units.
 * Webhooks: HMAC-SHA512 of the raw body with the secret key (`monnify-signature`).
 */
class MonnifyGateway implements PaymentGateway, TransferGateway
{
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'monnify';
    }

    public function currencies(): array
    {
        return ['NGN'];
    }

    public function isConfigured(): bool
    {
        return ! empty($this->config['api_key']) && ! empty($this->config['secret_key']) && ! empty($this->config['contract_code']);
    }

    public function initialize(PaymentRequest $request): PaymentInitResult
    {
        $response = $this->call('transactions.init', fn (PendingRequest $http) => $http->post($this->url('/api/v1/merchant/transactions/init-transaction'), [
            'amount' => (float) Money::toMajor($request->amount),
            'customerName' => $request->customerName,
            'customerEmail' => $request->email,
            'paymentReference' => $request->reference,
            'paymentDescription' => mb_substr($request->description, 0, 100),
            'currencyCode' => $request->currency,
            'contractCode' => $this->config['contract_code'],
            'redirectUrl' => $request->returnUrl,
            'paymentMethods' => ['CARD', 'ACCOUNT_TRANSFER', 'USSD'],
            'metaData' => ['reference' => $request->reference],
        ]), ['reference' => $request->reference, 'amount' => $request->amount]);

        $this->ensureOk($response, 'transactions.init');

        return new PaymentInitResult(
            (string) $response->json('responseBody.checkoutUrl'),
            $request->reference,
            (string) $response->json('responseBody.transactionReference'),
        );
    }

    public function verify(Payment $payment): PaymentVerification
    {
        $response = $this->call('transactions.query', fn (PendingRequest $http) => $http->get($this->url('/api/v2/merchant/transactions/query'), ['paymentReference' => $payment->provider_reference]));
        if ($response->status() === 404 || $response->status() === 400) {
            return new PaymentVerification(PaymentVerification::PENDING);
        }
        $this->ensureOk($response, 'transactions.query');

        $body = (array) $response->json('responseBody');
        $status = match (strtoupper((string) ($body['paymentStatus'] ?? ''))) {
            'PAID', 'OVERPAID' => PaymentVerification::SUCCEEDED,
            'FAILED', 'CANCELLED', 'EXPIRED', 'REVERSED' => PaymentVerification::FAILED,
            default => PaymentVerification::PENDING, // PENDING, PARTIALLY_PAID
        };

        return new PaymentVerification(
            $status,
            isset($body['amountPaid']) ? Money::fromMajor($body['amountPaid']) : null,
            strtoupper((string) ($body['currencyCode'] ?? $body['currency'] ?? 'NGN')),
            isset($body['transactionReference']) ? (string) $body['transactionReference'] : null,
            Money::fromMajor(($body['amountPaid'] ?? 0) - ($body['settlementAmount'] ?? $body['amountPaid'] ?? 0)),
            ['paymentStatus' => $body['paymentStatus'] ?? null, 'paymentMethod' => $body['paymentMethod'] ?? null],
            $status === PaymentVerification::FAILED ? 'Payment '.strtolower((string) ($body['paymentStatus'] ?? 'failed')) : null,
        );
    }

    public function refund(Payment $payment, int $amount, string $reference, string $reason): RefundResult
    {
        $response = $this->call('refunds.initiate', fn (PendingRequest $http) => $http->post($this->url('/api/v1/refunds/initiate-refund'), [
            'transactionReference' => $payment->provider_transaction_id,
            'refundReference' => $reference,
            'refundAmount' => (float) Money::toMajor($amount),
            'refundReason' => mb_substr($reason, 0, 200),
            'customerNote' => 'Wedpluz refund',
        ]), ['reference' => $reference, 'amount' => $amount]);

        if (! $response->successful() || $response->json('requestSuccessful') !== true) {
            if ($response->serverError()) {
                throw IntegrationException::transient('monnify', 'refund', 'Monnify is unavailable.');
            }

            return new RefundResult('failed', null, (string) ($response->json('responseMessage') ?? 'Refund was rejected.'));
        }
        $status = strtoupper((string) $response->json('responseBody.refundStatus'));

        return new RefundResult($status === 'COMPLETED' ? 'completed' : ($status === 'FAILED' ? 'failed' : 'processing'), $reference);
    }

    public function verifyWebhook(Request $request): bool
    {
        $secret = (string) ($this->config['secret_key'] ?? '');
        $signature = (string) $request->header('monnify-signature');

        return $secret !== '' && $signature !== '' && hash_equals(hash_hmac('sha512', $request->getContent(), $secret), $signature);
    }

    public function parseWebhook(array $payload, Request $request): ?WebhookPayload
    {
        $type = (string) ($payload['eventType'] ?? '');
        $data = (array) ($payload['eventData'] ?? []);
        $id = $data['transactionReference'] ?? $data['reference'] ?? $data['refundReference'] ?? md5(json_encode($data));
        $eventId = 'monnify:'.$type.':'.$id;

        return match ($type) {
            'SUCCESSFUL_TRANSACTION' => new WebhookPayload($eventId, WebhookPayload::PAYMENT_SUCCEEDED, $data['paymentReference'] ?? null, $type, $data),
            'FAILED_TRANSACTION' => new WebhookPayload($eventId, WebhookPayload::PAYMENT_FAILED, $data['paymentReference'] ?? null, $type, $data),
            'SUCCESSFUL_DISBURSEMENT' => new WebhookPayload($eventId, WebhookPayload::TRANSFER_SUCCEEDED, $data['reference'] ?? null, $type, $data),
            'FAILED_DISBURSEMENT' => new WebhookPayload($eventId, WebhookPayload::TRANSFER_FAILED, $data['reference'] ?? null, $type, $data),
            'REVERSED_DISBURSEMENT' => new WebhookPayload($eventId, WebhookPayload::TRANSFER_REVERSED, $data['reference'] ?? null, $type, $data),
            'SUCCESSFUL_REFUND' => new WebhookPayload($eventId, WebhookPayload::REFUND_COMPLETED, $data['refundReference'] ?? null, $type, $data),
            'FAILED_REFUND' => new WebhookPayload($eventId, WebhookPayload::REFUND_FAILED, $data['refundReference'] ?? null, $type, $data),
            default => null,
        };
    }

    public function banks(): array
    {
        return Cache::remember('monnify:banks', 86400, function () {
            $response = $this->call('banks.list', fn (PendingRequest $http) => $http->get($this->url('/api/v1/banks')));
            $this->ensureOk($response, 'banks.list');

            return collect($response->json('responseBody', []))->map(fn ($b) => ['code' => (string) $b['code'], 'name' => (string) $b['name']])->unique('code')->values()->all();
        });
    }

    public function resolveAccount(string $bankCode, string $accountNumber): array
    {
        $response = $this->call('account.validate', fn (PendingRequest $http) => $http->get($this->url('/api/v1/disbursements/account/validate'), [
            'accountNumber' => $accountNumber,
            'bankCode' => $bankCode,
        ]));
        if (! $response->successful() || ! $response->json('responseBody.accountName')) {
            if ($response->serverError()) {
                throw IntegrationException::transient('monnify', 'account.validate', 'Bank verification is temporarily unavailable.');
            }
            throw IntegrationException::permanent('monnify', 'account.validate', 'We could not verify this account number.', 'unresolvable');
        }

        return ['accountName' => (string) $response->json('responseBody.accountName'), 'bankName' => null];
    }

    public function transfer(TransferRequest $request): TransferResult
    {
        $response = $this->call('disbursements.single', fn (PendingRequest $http) => $http->post($this->url('/api/v2/disbursements/single'), [
            'amount' => (float) Money::toMajor($request->amount),
            'reference' => $request->reference,
            'narration' => mb_substr($request->narration, 0, 100),
            'destinationBankCode' => $request->bankCode,
            'destinationAccountNumber' => $request->accountNumber,
            'currency' => $request->currency,
            'sourceAccountNumber' => $this->config['wallet_account_number'],
        ]), ['reference' => $request->reference, 'amount' => $request->amount]);

        if (! $response->successful() || $response->json('requestSuccessful') !== true) {
            if ($response->serverError()) {
                throw IntegrationException::transient('monnify', 'transfer', 'Monnify is unavailable.');
            }

            return new TransferResult('failed', null, null, (string) ($response->json('responseMessage') ?? 'Transfer rejected.'));
        }
        $status = strtoupper((string) $response->json('responseBody.status'));

        return new TransferResult(
            $status === 'SUCCESS' ? 'successful' : (in_array($status, ['FAILED', 'REVERSED'], true) ? 'failed' : 'processing'),
            (string) $response->json('responseBody.reference', $request->reference),
        );
    }

    private function token(): string
    {
        return Cache::remember('monnify:token:'.md5((string) $this->config['api_key']), 3000, function () {
            $response = ProviderHttp::for('monnify', 15)->call(
                'auth.login',
                fn (PendingRequest $http) => $http->withBasicAuth($this->config['api_key'], $this->config['secret_key'])->post($this->url('/api/v1/auth/login')),
            );
            if (! $response->successful() || ! $response->json('responseBody.accessToken')) {
                throw IntegrationException::transient('monnify', 'auth', 'Could not authenticate with Monnify.');
            }

            return (string) $response->json('responseBody.accessToken');
        });
    }

    private function call(string $operation, callable $send, array $log = []): Response
    {
        if (! $this->isConfigured()) {
            throw IntegrationException::notConfigured('monnify');
        }
        $token = $this->token();

        return ProviderHttp::for('monnify', (int) ($this->config['timeout'] ?? 20))->call(
            $operation,
            fn (PendingRequest $http) => $send($http->withToken($token)),
            $log,
        );
    }

    private function url(string $path): string
    {
        return rtrim($this->config['base_url'] ?? 'https://sandbox.monnify.com', '/').$path;
    }

    private function ensureOk(Response $response, string $operation): void
    {
        if (! $response->successful() || $response->json('requestSuccessful') === false) {
            throw IntegrationException::fromStatus('monnify', $operation, $response->status(), (string) ($response->json('responseMessage') ?? 'Monnify request failed.'));
        }
    }
}
