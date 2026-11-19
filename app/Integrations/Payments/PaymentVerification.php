<?php

namespace App\Integrations\Payments;

final class PaymentVerification
{
    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const PENDING = 'pending';

    public function __construct(
        public readonly string $status,
        public readonly ?int $amount = null,
        public readonly ?string $currency = null,
        public readonly ?string $providerTransactionId = null,
        public readonly int $fee = 0,
        public readonly array $raw = [],
        public readonly ?string $failureReason = null,
    ) {}
}
