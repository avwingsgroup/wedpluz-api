<?php

namespace App\Integrations\Sms;

use App\Integrations\Support\IntegrationException;

interface SmsSender
{
    /**
     * Send one SMS to an E.164 number.
     *
     * @throws IntegrationException transient=true for provider/network failures,
     *                              providerCode=invalid_recipient for permanent recipient errors
     */
    public function send(string $to, string $message): SmsResult;

    public function name(): string;
}
