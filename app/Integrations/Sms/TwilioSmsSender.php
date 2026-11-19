<?php

namespace App\Integrations\Sms;

use App\Integrations\Support\IntegrationException;
use App\Integrations\Support\ProviderHttp;
use Illuminate\Http\Client\PendingRequest;

/** Twilio Programmable Messaging: POST /2010-04-01/Accounts/{sid}/Messages.json. Fallback SMS route. */
class TwilioSmsSender implements SmsSender
{
    /** Twilio error codes that mean the recipient can never receive this message. */
    private const PERMANENT_CODES = [21211, 21214, 21217, 21219, 21401, 21407, 21408, 21610, 21612, 21614];

    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'twilio';
    }

    public function send(string $to, string $message): SmsResult
    {
        $sid = $this->config['account_sid'] ?? null;
        if (! $sid || empty($this->config['auth_token'])) {
            throw IntegrationException::transient('twilio', 'send', 'Twilio is not configured.', 'not_configured');
        }

        $form = ['To' => $to, 'Body' => $message];
        if (! empty($this->config['messaging_service_sid'])) {
            $form['MessagingServiceSid'] = $this->config['messaging_service_sid'];
        } else {
            $form['From'] = $this->config['from'];
        }

        $url = 'https://api.twilio.com/2010-04-01/Accounts/'.$sid.'/Messages.json';
        $response = ProviderHttp::for('twilio', (int) ($this->config['timeout'] ?? 10), 0)->call(
            'sms.send',
            fn (PendingRequest $http) => $http->withBasicAuth($sid, $this->config['auth_token'])->asForm()->post($url, $form),
        );

        if ($response->successful()) {
            return new SmsResult('twilio', (string) $response->json('sid'), (string) $response->json('status', 'queued'));
        }

        $code = (int) $response->json('code');
        if (in_array($code, self::PERMANENT_CODES, true)) {
            throw IntegrationException::permanent('twilio', 'sms.send', 'Invalid recipient number.', 'invalid_recipient');
        }

        throw IntegrationException::fromStatus('twilio', 'sms.send', $response->status(), 'Twilio could not send the message.', (string) $code);
    }
}
