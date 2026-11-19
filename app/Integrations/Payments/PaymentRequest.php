<?php

namespace App\Integrations\Payments;

final class PaymentRequest
{
    public function __construct(
        public readonly string $reference,
        public readonly int $amount,
        public readonly string $currency,
        public readonly string $email,
        public readonly string $customerName,
        public readonly string $returnUrl,
        public readonly string $description,
        public readonly array $metadata = [],
        public readonly ?string $method = null,
    ) {}
}
