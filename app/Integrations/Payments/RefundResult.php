<?php

namespace App\Integrations\Payments;

final class RefundResult
{
    public function __construct(
        /** processing | completed | failed */
        public readonly string $status,
        public readonly ?string $providerReference = null,
        public readonly ?string $failureReason = null,
    ) {}
}
