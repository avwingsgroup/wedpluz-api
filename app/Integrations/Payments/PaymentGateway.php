<?php

namespace App\Integrations\Payments;

use App\Models\Payment;
use Illuminate\Http\Request;

/**
 * Provider-agnostic payment operations. Implementations: Paystack,
 * Flutterwave, Monnify, Stripe (also Google Pay via Stripe Checkout), PayPal.
 *
 * Amounts are always integer minor units; gateways that use major units
 * convert internally.
 */
interface PaymentGateway
{
    public function name(): string;

    /** @return list<string> ISO-4217 currencies this gateway accepts */
    public function currencies(): array;

    public function isConfigured(): bool;

    public function initialize(PaymentRequest $request): PaymentInitResult;

    /** Re-query the provider. Never trusts redirect parameters. */
    public function verify(Payment $payment): PaymentVerification;

    public function refund(Payment $payment, int $amount, string $reference, string $reason): RefundResult;

    public function verifyWebhook(Request $request): bool;

    /** Normalise a verified webhook payload; null when the event is irrelevant. */
    public function parseWebhook(array $payload, Request $request): ?WebhookPayload;
}
