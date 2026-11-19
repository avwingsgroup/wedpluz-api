<?php

namespace App\Integrations\Payments;

final class PaymentInitResult
{
    public function __construct(
        public readonly ?string $authorizationUrl,
        public readonly string $providerReference,
        public readonly ?string $providerTransactionId = null,
        /** Public values only (public key, access code) for inline SDKs. */
        public readonly array $clientParams = [],
    ) {}
}
