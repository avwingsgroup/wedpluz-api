<?php

namespace App\Integrations\Sms;

final class SmsResult
{
    public function __construct(
        public readonly string $provider,
        public readonly ?string $messageId,
        public readonly string $status = 'sent',
        public readonly bool $usedFallback = false,
    ) {}
}
