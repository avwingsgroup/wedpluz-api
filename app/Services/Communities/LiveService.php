<?php

namespace App\Services\Communities;

use App\Exceptions\ApiException;
use App\Http\Resources\Fmt;
use App\Integrations\Live\LiveKitTokenIssuer;
use App\Models\Community;
use App\Models\LiveRequest;
use App\Models\LiveSession;
use App\Models\User;
use App\Services\Platform\SettingsService;
use App\Services\Realtime\Realtime;
use App\Support\Sql;
use Illuminate\Support\Facades\DB;

/**
 * Community live sessions (contract status: proposed). Media flows through
 * LiveKit; this service manages state, stage requests and short-lived room
 * tokens. One live session per community at a time.
 */
class LiveService
{
    public function __construct(
        private readonly CommunityService $communities,
        private readonly LiveKitTokenIssuer $livekit,
        private readonly SettingsService $settings,
    ) {}

    public function current(Community $community): ?LiveSession
    {
        return LiveSession::query()->with('host')->where('community_id', $community->id)->where('status', 'live')->first();
    }

    public function start(Community $community, User $host, ?string $title, bool $audio, bool $video): LiveSession
    {
        $this->ensureAvailable();
        $this->communities->requirePermission($community, $host, 'membersCanGoLive');

        $session = Sql::insertOrNull(fn () => LiveSession::query()->create([
            'community_id' => $community->id,
            'host_user_id' => $host->id,
            'title' => $title,
            'status' => 'live',
            'provider' => 'livekit',
            'audio_enabled' => $audio,
            'video_enabled' => $video,
            'started_at' => now(),
        ]));
        if ($session === null) {
            throw ApiException::conflict('A live session is already running in this community.');
        }
        $session->forceFill(['provider_room_id' => 'wedpluz-'.$session->id])->save();
        $session->setRelation('host', $host);
        Realtime::emit('community.'.$community->id, 'live.started', self::resource($session, null));

        return $session;
    }

    public function update(LiveSession $session, User $user, array $data): LiveSession
    {
        $this->requireHost($session, $user);
        $session->forceFill(array_filter([
            'audio_enabled' => $data['audioEnabled'] ?? null,
            'video_enabled' => $data['videoEnabled'] ?? null,
        ], fn ($v) => $v !== null))->save();
        Realtime::emit('community.'.$session->community_id, 'live.updated', self::resource($session->load('host'), null));

        return $session;
    }

    public function end(LiveSession $session, User $user): LiveSession
    {
        $community = Community::query()->findOrFail($session->community_id);
        $member = $this->communities->requireMember($community, $user);
        if ($session->host_user_id !== $user->id && ! $member->isAdmin()) {
            throw ApiException::forbidden('Only the host or a community admin can end the live session.');
        }
        if ($session->status === 'live') {
            $session->forceFill(['status' => 'ended', 'ended_at' => now()])->save();
            Realtime::emit('community.'.$session->community_id, 'live.ended', ['id' => $session->id, 'communityId' => $session->community_id, 'endedAt' => Fmt::date($session->ended_at)]);
        }

        return $session;
    }

    /** @return array{joinToken:string, session:array} */
    public function join(LiveSession $session, User $user): array
    {
        $this->ensureAvailable();
        if ($session->status !== 'live') {
            throw ApiException::conflict('This live session has ended.');
        }
        $community = Community::query()->findOrFail($session->community_id);
        $this->communities->requireMember($community, $user);

        $canPublish = $session->host_user_id === $user->id
            || LiveRequest::query()->where('live_session_id', $session->id)->where('user_id', $user->id)->where('status', 'accepted')->exists();
        $token = $this->livekit->issue((string) $session->provider_room_id, $user->id, $user->display_name, $canPublish);
        DB::table('live_sessions')->where('id', $session->id)->increment('viewers_count');

        return ['joinToken' => $token, 'session' => self::resource($session->refresh()->load('host'), $token)];
    }

    public function requestStage(LiveSession $session, User $user): LiveRequest
    {
        $community = Community::query()->findOrFail($session->community_id);
        $this->communities->requireMember($community, $user);
        if ($session->status !== 'live') {
            throw ApiException::conflict('This live session has ended.');
        }
        $request = LiveRequest::query()->firstOrCreate(['live_session_id' => $session->id, 'user_id' => $user->id], ['status' => 'pending']);
        Realtime::emit('community.'.$session->community_id, 'live.request.created', self::request($request->load('user')));

        return $request;
    }

    public function decide(LiveSession $session, User $host, string $requestId, string $status): LiveRequest
    {
        $this->requireHost($session, $host);
        $request = LiveRequest::query()->where('live_session_id', $session->id)->findOrFail($requestId);
        $request->forceFill(['status' => $status])->save();

        return $request->load('user');
    }

    public function requireHost(LiveSession $session, User $user): void
    {
        if ($session->host_user_id !== $user->id) {
            throw ApiException::forbidden('Only the host can do this.');
        }
    }

    private function ensureAvailable(): void
    {
        if (! $this->settings->feature('liveSessionsEnabled') || ! $this->livekit->isConfigured()) {
            throw ApiException::forbidden('Live sessions are not available yet.');
        }
    }

    public static function resource(?LiveSession $s, ?string $token): ?array
    {
        return $s === null ? null : [
            'id' => $s->id,
            'communityId' => $s->community_id,
            'host' => Fmt::user($s->host),
            'title' => $s->title,
            'status' => $s->status,
            'viewersCount' => $s->viewers_count,
            'startedAt' => Fmt::date($s->started_at),
            'endedAt' => Fmt::date($s->ended_at),
            'joinToken' => $token,
            'audioEnabled' => $s->audio_enabled,
            'videoEnabled' => $s->video_enabled,
        ];
    }

    public static function request(LiveRequest $r): array
    {
        return ['id' => $r->id, 'user' => Fmt::user($r->user), 'status' => $r->status, 'createdAt' => Fmt::date($r->created_at)];
    }
}
