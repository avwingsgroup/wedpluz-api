<?php

namespace App\Services\Notifications;

use App\Http\Resources\NotificationResource;
use App\Jobs\DeliverNotificationJob;
use App\Models\AppNotification;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\Platform\SettingsService;
use App\Services\Realtime\Realtime;
use App\Support\DemoAccounts;
use App\Support\Sql;
use Illuminate\Database\Eloquent\Model as EloquentModel;

/**
 * The NotificationDispatcher from integrations.md §3.
 *
 * Always stores the in-app notification (powers GET /notifications), then fans
 * out to queued channel jobs according to the user's NotificationPreference.
 * Security and transactional messages (OTP, receipts, ticket issued) are
 * always emailed regardless of opt-outs. Delivery is decoupled from business
 * transactions: jobs are dispatched after commit.
 */
class NotificationService
{
    public const DEFAULT_CHANNELS = ['push' => true, 'email' => true, 'sms' => false, 'whatsapp' => false, 'inApp' => true];

    public const DEFAULT_TOPICS = [
        'eventReminders' => true, 'ticketUpdates' => true, 'messages' => true, 'communityActivity' => true,
        'promotions' => true, 'collaboration' => true, 'walletActivity' => true,
    ];

    public function __construct(private readonly TemplateRenderer $renderer, private readonly SettingsService $settings) {}

    /**
     * @param  array{actor?:?User, tokens?:array<string,string>, data?:array<string,string>, link?:?string, subject?:?EloquentModel,
     *               email?:array<string,scalar|null>, dedupe?:?string, title?:string, body?:string, actionState?:?string}  $options
     */
    public function notify(User $user, string $type, array $options = []): ?AppNotification
    {
        if ($user->anonymized_at !== null) {
            return null;
        }
        $def = NotificationCatalog::get($type);
        $tokens = $options['tokens'] ?? [];
        if (isset($options['actor'])) {
            $tokens['actor'] ??= $options['actor']->display_name;
        }
        if (isset($options['body'])) {
            $tokens['body'] ??= $options['body'];
        }
        $segments = NotificationCatalog::segments($def['pattern'], $tokens);
        $body = $options['body'] ?? NotificationCatalog::plain($segments);
        $title = $options['title'] ?? $def['title'];

        $notification = AppNotification::query()->create([
            'user_id' => $user->id,
            'type' => $type,
            'actor_user_id' => ($options['actor'] ?? null)?->id,
            'title' => mb_substr($title, 0, 200),
            'body' => mb_substr($body, 0, 1000),
            'segments' => $segments,
            'action' => $def['action'],
            'action_state' => $def['action'] === 'accept_decline' ? ($options['actionState'] ?? 'pending') : ($options['actionState'] ?? null),
            'data' => array_map('strval', $options['data'] ?? []),
            'link' => $options['link'] ?? null,
            'is_request' => $def['isRequest'],
            'subject_type' => isset($options['subject']) ? $options['subject']->getMorphClass() : null,
            'subject_id' => isset($options['subject']) ? $options['subject']->getKey() : null,
        ]);
        $notification->setRelation('actor', $options['actor'] ?? null);

        Realtime::toUser($user->id, 'notification.created', (new NotificationResource($notification))->resolve());

        $channels = $this->channelsFor($user, $def['topic'], $def['transactional']);
        $vars = ($options['email'] ?? []) + ['firstName' => $user->first_name, 'title' => $title, 'body' => $body, 'link' => $options['link'] ?? ''];
        $dedupe = $options['dedupe'] ?? $notification->id;
        $templateKey = $def['email'] ?? 'generic';

        if (in_array('email', $channels, true) && $def['email'] !== null) {
            $this->queue($user, 'email', $user->email, $templateKey, $vars, $dedupe, $notification->id);
        }
        if (in_array('push', $channels, true)) {
            $this->queueRaw($user, 'push', null, $type, ['subject' => $title, 'body' => $body, 'data' => ['type' => $type, 'notificationId' => $notification->id] + array_map('strval', $options['data'] ?? [])], $dedupe, $notification->id);
        }
        if (in_array('sms', $channels, true) && $def['sms'] && $user->phone) {
            $this->queue($user, 'sms', $user->phone, $templateKey, $vars, $dedupe, $notification->id);
        }
        if (in_array('whatsapp', $channels, true) && $def['whatsapp'] && $user->phone) {
            $this->queue($user, 'whatsapp', $user->phone, $templateKey, $vars, $dedupe, $notification->id);
        }

        return $notification;
    }

    /**
     * Send a templated message on one channel without an in-app notification
     * (OTP codes, password-change notices, invites to non-users...).
     *
     * @param  array<string, scalar|null>  $vars
     */
    public function sendDirect(string $channel, string $recipient, string $templateKey, array $vars, ?string $dedupe = null, ?User $user = null): ?NotificationDelivery
    {
        return $this->queue($user, $channel, $recipient, $templateKey, $vars, $dedupe ?? $templateKey.':'.$channel.':'.hash('sha256', $recipient.microtime()), null);
    }

    /**
     * Push only (no in-app row), for high-volume events such as chat messages.
     *
     * @param  array<string,string>  $data
     */
    public function pushOnly(User $user, string $topic, string $title, string $body, array $data, string $dedupe): void
    {
        if (in_array('push', $this->channelsFor($user, $topic, false), true)) {
            $this->queueRaw($user, 'push', null, 'push:'.$topic, ['subject' => $title, 'body' => $body, 'data' => $data], $dedupe, null);
        }
    }

    /** @return list<string> */
    public function channelsFor(User $user, string $topic, bool $transactional): array
    {
        $pref = $user->relationLoaded('notificationPreference') ? $user->notificationPreference : NotificationPreference::query()->find($user->id);
        $channelPrefs = array_replace(self::DEFAULT_CHANNELS, $pref?->channels ?? []);
        $topicPrefs = array_replace(self::DEFAULT_TOPICS, $pref?->topics ?? []);
        $topicOn = (bool) ($topicPrefs[$topic] ?? true);

        $channels = [];
        if ($transactional || ($topicOn && $channelPrefs['email'])) {
            $channels[] = 'email';
        }
        if ($topicOn && $channelPrefs['push']) {
            $channels[] = 'push';
        }
        if ($topicOn && $channelPrefs['sms'] && $this->settings->feature('smsOtpEnabled')) {
            $channels[] = 'sms';
        }
        if ($topicOn && $channelPrefs['whatsapp'] && $this->settings->feature('whatsappNotificationsEnabled')) {
            $channels[] = 'whatsapp';
        }

        return $channels;
    }

    /** @param array<string, scalar|null> $vars */
    private function queue(?User $user, string $channel, string $recipient, string $templateKey, array $vars, string $dedupe, ?string $notificationId): ?NotificationDelivery
    {
        $rendered = $this->renderer->render($templateKey, $channel, $vars)
            ?? ($channel === 'email' || $channel === 'push' ? null : $this->renderer->render('generic', $channel, $vars));
        if ($rendered === null) {
            return null;
        }
        $content = ['subject' => $rendered['subject'], 'body' => $rendered['body']];
        if ($channel === 'whatsapp' && $rendered['whatsappTemplate']) {
            $content['whatsappTemplate'] = $rendered['whatsappTemplate'];
            $content['whatsappParams'] = array_map(fn ($v) => (string) ($vars[$v] ?? ''), $rendered['variables']);
        }

        return $this->queueRaw($user, $channel, $recipient, $templateKey, $content, $dedupe, $notificationId);
    }

    private function queueRaw(?User $user, string $channel, ?string $recipient, string $templateKey, array $content, string $dedupe, ?string $notificationId): ?NotificationDelivery
    {
        // Demo accounts have made-up phone numbers (possibly someone's real number) and
        // undeliverable emails that would bounce: only in-app and push reach them.
        if (in_array($channel, ['email', 'sms', 'whatsapp'], true)
            && (DemoAccounts::isDemoEmail($user?->email) || ($channel === 'email' && DemoAccounts::isDemoEmail($recipient)))) {
            return null;
        }
        $delivery = Sql::insertOrNull(fn () => NotificationDelivery::query()->create([
            'notification_id' => $notificationId,
            'user_id' => $user?->id,
            'channel' => $channel,
            'recipient' => $recipient,
            'template_key' => $templateKey,
            'dedupe_key' => mb_substr($channel.':'.$dedupe, 0, 191),
            'status' => 'queued',
        ]));
        if ($delivery === null) {
            return null; // duplicate: already queued or sent
        }

        DeliverNotificationJob::dispatch($delivery->id, $content);

        return $delivery;
    }
}
