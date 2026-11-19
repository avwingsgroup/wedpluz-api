<?php

namespace App\Jobs;

use App\Integrations\Push\FcmClient;
use App\Integrations\Sms\SmsSender;
use App\Integrations\Support\IntegrationException;
use App\Integrations\WhatsApp\WhatsAppClient;
use App\Mail\TemplatedMail;
use App\Models\DeviceToken;
use App\Models\NotificationDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Delivers one outbound message (email, SMS, WhatsApp or push) recorded in
 * `notification_deliveries`. Idempotent: a delivery already marked sent is
 * skipped. Transient provider errors retry with backoff; permanent errors
 * (invalid recipient, unregistered device) fail immediately without retry.
 */
class DeliverNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 60;

    /**
     * @param  array{subject?:?string, body:string, whatsappTemplate?:?string, whatsappParams?:list<string>, data?:array<string,string>}  $content
     */
    public function __construct(public readonly string $deliveryId, public readonly array $content)
    {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 120, 600, 1800];
    }

    public function handle(SmsSender $sms, WhatsAppClient $whatsapp, FcmClient $fcm): void
    {
        $delivery = NotificationDelivery::query()->find($this->deliveryId);
        if ($delivery === null || in_array($delivery->status, ['sent', 'delivered', 'read', 'opened'], true)) {
            return;
        }
        $delivery->increment('attempts');

        try {
            [$provider, $messageId] = match ($delivery->channel) {
                'email' => $this->sendEmail($delivery),
                'sms' => $this->sendSms($sms, $delivery),
                'whatsapp' => $this->sendWhatsApp($whatsapp, $delivery),
                'push' => $this->sendPush($fcm, $delivery),
                default => throw IntegrationException::permanent('notifications', 'deliver', 'Unknown channel '.$delivery->channel),
            };
        } catch (IntegrationException $e) {
            $delivery->forceFill(['status' => $e->transient ? 'retrying' : 'failed', 'provider' => $e->provider, 'error' => mb_substr($e->getMessage(), 0, 1000)])->save();
            if ($e->transient && $this->attempts() < $this->tries) {
                throw $e;
            }
            $delivery->forceFill(['status' => 'failed'])->save();
            Log::notice('notification.delivery_failed', ['delivery' => $delivery->id, 'channel' => $delivery->channel, 'error' => $e->getMessage()]);

            return;
        }

        $delivery->forceFill([
            'status' => 'sent',
            'provider' => $provider,
            'provider_message_id' => $messageId,
            'sent_at' => now(),
            'error' => null,
        ])->save();
        $this->bumpCampaignStat($delivery, 'sent');
    }

    public function failed(\Throwable $e): void
    {
        $delivery = NotificationDelivery::query()->find($this->deliveryId);
        if ($delivery) {
            $delivery->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)])->save();
            $this->bumpCampaignStat($delivery, 'failed');
        }
    }

    private function sendEmail(NotificationDelivery $delivery): array
    {
        if (DB::table('email_suppressions')->where('email', mb_strtolower((string) $delivery->recipient))->exists()) {
            throw IntegrationException::permanent('zeptomail', 'send', 'Recipient is suppressed (bounce or complaint).', 'suppressed');
        }
        try {
            $sent = Mail::to($delivery->recipient)->send(new TemplatedMail((string) ($this->content['subject'] ?? 'Wedpluz'), $this->content['body']));
        } catch (IntegrationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw IntegrationException::transient('mail', 'send', $e->getMessage());
        }
        $requestId = $sent?->getOriginalMessage()->getHeaders()->get('X-ZeptoMail-Request-Id')?->getBodyAsString();

        return [config('mail.default'), $requestId ?: $sent?->getMessageId()];
    }

    private function sendSms(SmsSender $sms, NotificationDelivery $delivery): array
    {
        $result = $sms->send((string) $delivery->recipient, $this->content['body']);

        return [$result->provider, $result->messageId];
    }

    private function sendWhatsApp(WhatsAppClient $whatsapp, NotificationDelivery $delivery): array
    {
        $template = $this->content['whatsappTemplate'] ?? null;
        $id = $template
            ? $whatsapp->sendTemplate((string) $delivery->recipient, $template, $this->content['whatsappParams'] ?? [])
            : $whatsapp->sendText((string) $delivery->recipient, $this->content['body']);

        return ['whatsapp', $id];
    }

    private function sendPush(FcmClient $fcm, NotificationDelivery $delivery): array
    {
        $tokens = DeviceToken::query()->where('user_id', $delivery->user_id)->get();
        if ($tokens->isEmpty()) {
            throw IntegrationException::permanent('fcm', 'send', 'User has no registered devices.', 'no_devices');
        }

        $lastId = null;
        $sent = 0;
        $transient = null;
        foreach ($tokens as $token) {
            try {
                $lastId = $fcm->send($token->token, (string) ($this->content['subject'] ?? 'Wedpluz'), $this->content['body'], $this->content['data'] ?? []);
                $sent++;
            } catch (IntegrationException $e) {
                if ($e->providerCode === 'unregistered') {
                    $token->delete();
                } elseif ($e->transient) {
                    $transient = $e;
                } elseif ($e->providerCode === 'not_configured') {
                    throw $e;
                }
            }
        }
        if ($sent === 0) {
            throw $transient ?? IntegrationException::permanent('fcm', 'send', 'No valid device tokens.', 'no_devices');
        }

        return ['fcm', $lastId];
    }

    private function bumpCampaignStat(NotificationDelivery $delivery, string $key): void
    {
        if ($delivery->campaign_id === null) {
            return;
        }
        DB::table('notification_campaigns')->where('id', $delivery->campaign_id)->update([
            'stats' => DB::raw("jsonb_set(stats, '{".$key."}', to_jsonb(coalesce((stats->>'".$key."')::int, 0) + 1))"),
        ]);
    }
}
