<?php

namespace App\Integrations\Identity;

use App\Integrations\Support\IntegrationException;

interface IdentityVerifier
{
    public function name(): string;

    public function supports(string $idType): bool;

    /**
     * Verify an ID number and match it against a selfie.
     *
     * @param  string  $selfie  raw image bytes
     *
     * @throws IntegrationException on provider/network failure (verification stays pending)
     */
    public function verify(string $idType, string $idNumber, string $selfie, string $reference): IdentityResult;
}
