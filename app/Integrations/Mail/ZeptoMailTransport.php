<?php

namespace App\Integrations\Mail;

use App\Integrations\Support\IntegrationException;
use App\Integrations\Support\ProviderHttp;
use Illuminate\Http\Client\PendingRequest;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\MessageConverter;

/**
 * Symfony mailer transport for Zoho ZeptoMail's send-mail API
 * (POST /v1.1/email, `Authorization: Zoho-enczapikey <token>`).
 *
 * Registered as the `zeptomail` mailer, so Laravel Mailables, queued mail and
 * notifications use it transparently. Transient failures throw so the queue
 * worker retries with backoff; permanent ones are not retried.
 */
class ZeptoMailTransport extends AbstractTransport
{
    public function __construct(
        private readonly string $token,
        private readonly string $endpoint,
        private readonly ?string $bounceAddress = null,
        private readonly int $timeout = 15,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        if ($this->token === '') {
            throw IntegrationException::notConfigured('zeptomail');
        }

        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $from = $email->getFrom()[0] ?? null;

        $payload = array_filter([
            'from' => $from ? ['address' => $from->getAddress(), 'name' => $from->getName() ?: null] : null,
            'to' => $this->addresses($email->getTo()),
            'cc' => $this->addresses($email->getCc()),
            'bcc' => $this->addresses($email->getBcc()),
            'reply_to' => array_map(fn (Address $a) => array_filter(['address' => $a->getAddress(), 'name' => $a->getName() ?: null]), $email->getReplyTo()),
            'subject' => (string) $email->getSubject(),
            'htmlbody' => $email->getHtmlBody() ? (string) $email->getHtmlBody() : null,
            'textbody' => $email->getTextBody() ? (string) $email->getTextBody() : null,
            'bounce_address' => $this->bounceAddress,
            'track_opens' => true,
            'track_clicks' => true,
            'attachments' => array_map(fn ($part) => [
                'content' => base64_encode($part->getBody()),
                'mime_type' => $part->getMediaType().'/'.$part->getMediaSubtype(),
                'name' => $part->getFilename() ?? 'attachment',
            ], $email->getAttachments()),
        ], fn ($v) => $v !== null && $v !== []);

        $authorization = str_starts_with($this->token, 'Zoho-enczapikey') ? $this->token : 'Zoho-enczapikey '.$this->token;

        $response = ProviderHttp::for('zeptomail', $this->timeout, 1)->call(
            'send',
            fn (PendingRequest $http) => $http->withHeaders(['Authorization' => $authorization])->post($this->endpoint, $payload),
            ['subject' => $payload['subject'], 'recipients' => count($payload['to'] ?? [])],
        );

        if (! $response->successful()) {
            $error = $response->json('error.details.0.message') ?? $response->json('error.message') ?? 'ZeptoMail rejected the message.';
            throw IntegrationException::fromStatus('zeptomail', 'send', $response->status(), (string) $error, (string) $response->json('error.code'));
        }

        $requestId = $response->json('request_id') ?? $response->json('data.0.additional_info.0.request_id');
        if ($requestId) {
            $message->getOriginalMessage()->getHeaders()->addTextHeader('X-ZeptoMail-Request-Id', (string) $requestId);
        }
    }

    /** @param list<Address> $addresses */
    private function addresses(array $addresses): array
    {
        return array_map(fn (Address $a) => ['email_address' => array_filter([
            'address' => $a->getAddress(),
            'name' => $a->getName() ?: null,
        ])], $addresses);
    }

    public function __toString(): string
    {
        return 'zeptomail';
    }
}
