<?php

namespace App\Services\Notifications;

use App\Exceptions\ApiException;
use App\Jobs\DeliverNotificationJob;
use App\Jobs\SendCampaignChunkJob;
use App\Models\AppNotification;
use App\Models\NotificationCampaign;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Support\Sql;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Admin notification campaigns. The audience is resolved when sending starts
 * (`estimatedSize` is only a preview). Marketing campaigns honour the
 * `promotions` topic opt-out and each user's channel preferences. Delivery is
 * queued in chunks with a delay between chunks to respect provider limits.
 */
class CampaignService
{
    private const CHUNK = 500;

    public function __construct(private readonly TemplateRenderer $renderer, private readonly NotificationService $notifications) {}

    public function audience(array $audience): Builder
    {
        $filters = (array) ($audience['filters'] ?? []);
        $query = User::query()->where('status', 'active')->whereNull('anonymized_at');

        return match ($audience['type'] ?? 'all') {
            'segment' => $query
                ->when($filters['city'] ?? null, fn ($q, $c) => $q->where('city', 'ilike', $c))
                ->when($filters['plan'] ?? null, fn ($q, $p) => $q->where('plan', $p))
                ->when($filters['signupSource'] ?? null, fn ($q, $s) => $q->where('signup_source', $s))
                ->when($filters['role'] ?? null, fn ($q, $r) => $q->whereExists(fn ($e) => $e->from('user_roles')->whereColumn('user_roles.user_id', 'users.id')->where('role', $r)))
                ->when($filters['interest'] ?? null, fn ($q, $i) => $q->whereExists(fn ($e) => $e->from('user_interests')->whereColumn('user_interests.user_id', 'users.id')->where('interest_slug', $i))),
            'event_attendees' => $query->whereIn('id', DB::table('tickets')->where('event_id', $filters['eventId'] ?? '')->whereIn('status', ['valid', 'checked_in'])->select('holder_user_id')),
            'organizers' => $query->whereExists(fn ($e) => $e->from('events')->whereColumn('events.organizer_user_id', 'users.id')),
            'vendors' => $query->whereExists(fn ($e) => $e->from('vendor_profiles')->whereColumn('vendor_profiles.user_id', 'users.id')->where('status', 'active')),
            'custom' => $query->where(function ($q) use ($filters) {
                $ids = array_filter(array_map('trim', explode(',', (string) ($filters['userIds'] ?? ''))));
                $emails = array_filter(array_map(fn ($e) => mb_strtolower(trim($e)), explode(',', (string) ($filters['emails'] ?? ''))));
                $q->whereIn('id', $ids ?: ['__none__'])->orWhereIn(DB::raw('lower(email)'), $emails ?: ['__none__']);
            }),
            default => $query,
        };
    }

    public function send(NotificationCampaign $campaign): NotificationCampaign
    {
        if (! in_array($campaign->status, ['draft', 'scheduled'], true)) {
            throw ApiException::conflict('This campaign has already been sent or cancelled.');
        }
        if ($campaign->scheduled_at && $campaign->scheduled_at->isFuture() && $campaign->status === 'draft') {
            $campaign->forceFill(['status' => 'scheduled'])->save();

            return $campaign;
        }
        $this->start($campaign);

        return $campaign->refresh();
    }

    /** Resolve the audience now and queue chunks. */
    public function start(NotificationCampaign $campaign): void
    {
        $updated = NotificationCampaign::query()->whereKey($campaign->id)->whereIn('status', ['draft', 'scheduled'])->update(['status' => 'sending', 'sent_at' => now()]);
        if ($updated === 0) {
            return;
        }
        $delay = 0;
        $this->audience($campaign->audience ?? [])->select('id')->orderBy('id')->chunk(self::CHUNK, function ($users) use ($campaign, &$delay) {
            SendCampaignChunkJob::dispatch($campaign->id, $users->pluck('id')->all())->delay(now()->addSeconds($delay));
            $delay += 30;
        });
        SendCampaignChunkJob::dispatch($campaign->id, [], finalize: true)->delay(now()->addSeconds($delay + 5));
    }

    /** @param list<string> $userIds */
    public function deliverChunk(NotificationCampaign $campaign, array $userIds): void
    {
        if ($campaign->status === 'cancelled') {
            return;
        }
        $users = User::query()->whereIn('id', $userIds)->get();
        $prefs = NotificationPreference::query()->whereIn('user_id', $userIds)->get()->keyBy('user_id');
        $channels = (array) $campaign->channels;

        foreach ($users as $user) {
            $topics = array_replace(NotificationService::DEFAULT_TOPICS, $prefs[$user->id]?->topics ?? []);
            $userChannels = array_replace(NotificationService::DEFAULT_CHANNELS, $prefs[$user->id]?->channels ?? []);
            if (! $topics['promotions']) {
                continue;
            }
            $vars = ['firstName' => $user->first_name, 'title' => (string) ($campaign->subject ?? $campaign->name), 'body' => $campaign->body];

            if (in_array('in_app', $channels, true)) {
                AppNotification::query()->create([
                    'user_id' => $user->id, 'type' => 'system', 'title' => mb_substr((string) ($campaign->subject ?? $campaign->name), 0, 200),
                    'body' => mb_substr($campaign->body, 0, 1000), 'segments' => [['text' => mb_substr($campaign->body, 0, 1000)]],
                    'action' => 'none', 'data' => ['campaignId' => $campaign->id], 'is_request' => false,
                ]);
            }
            foreach (['email' => 'email', 'push' => 'push', 'sms' => 'sms', 'whatsapp' => 'whatsapp'] as $channel => $pref) {
                if (! in_array($channel, $channels, true) || ! $userChannels[$pref]) {
                    continue;
                }
                $recipient = match ($channel) {
                    'email' => $user->email, 'push' => null, default => $user->phone
                };
                if ($channel !== 'push' && ! $recipient) {
                    continue;
                }
                $content = $this->content($campaign, $channel, $vars);
                $delivery = Sql::insertOrNull(fn () => NotificationDelivery::query()->create([
                    'campaign_id' => $campaign->id, 'user_id' => $user->id, 'channel' => $channel, 'recipient' => $recipient,
                    'template_key' => 'campaign', 'dedupe_key' => 'campaign:'.$campaign->id.':'.$channel.':'.$user->id, 'status' => 'queued',
                ]));
                if ($delivery) {
                    DeliverNotificationJob::dispatch($delivery->id, $content);
                }
            }
        }
    }

    public function finalize(NotificationCampaign $campaign): void
    {
        if ($campaign->status === 'sending') {
            $campaign->forceFill(['status' => 'sent'])->save();
        }
    }

    private function content(NotificationCampaign $campaign, string $channel, array $vars): array
    {
        $template = $campaign->template_id ? DB::table('notification_templates')->where('id', $campaign->template_id)->first() : null;
        $subject = $template?->subject ?? $campaign->subject ?? $campaign->name;
        $body = $template?->body ?? ($channel === 'email' ? '<p>'.nl2br(e($campaign->body)).'</p>' : $campaign->body);

        return [
            'subject' => $this->renderer->fill((string) $subject, $vars, false),
            'body' => $this->renderer->fill((string) $body, $vars, $channel === 'email' && $template !== null),
            'data' => ['campaignId' => $campaign->id],
        ];
    }
}
