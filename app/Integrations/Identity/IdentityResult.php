<?php

namespace App\Integrations\Identity;

final class IdentityResult
{
    public function __construct(
        /** verified | failed | pending */
        public readonly string $status,
        public readonly ?string $providerReference = null,
        public readonly ?float $matchScore = null,
        public readonly ?string $failureReason = null,
    ) {}
}
