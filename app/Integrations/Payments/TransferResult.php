<?php

namespace App\Integrations\Payments;

final class TransferResult
{
    public function __construct(
        /** processing | successful | failed */
        public readonly string $status,
        public readonly ?string $providerReference = null,
        public readonly ?string $recipientCode = null,
        public readonly ?string $failureReason = null,
    ) {}
}
