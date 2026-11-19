<?php

namespace App\Integrations\Payments;

final class TransferRequest
{
    public function __construct(
        public readonly string $reference,
        public readonly int $amount,
        public readonly string $currency,
        public readonly string $bankCode,
        public readonly string $accountNumber,
        public readonly string $accountName,
        public readonly string $narration,
        public readonly ?string $recipientCode = null,
    ) {}
}
