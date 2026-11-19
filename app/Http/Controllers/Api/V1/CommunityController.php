<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CommunityStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Fmt;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\LiveRequest;
use App\Models\LiveSession;
use App\Models\Media;
use App\Models\StatusPost;
use App\Models\User;
use App\Services\Communities\CommunityPresenter;
use App\Services\Communities\CommunityService;
use App\Services\Communities\LiveService;
use App\Services\Events\EventAccess;
use App\Services\Media\UploadService;
use App\Services\Realtime\Realtime;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class CommunityController extends Controller
{
    public function __construct(private readonly CommunityService $communities, private readonly CommunityPresenter $presenter) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->customer();
        $archived = filter_var($request->query('archived', false), FILTER_VALIDATE_BOOLEAN);
        $search = ListQuery::from($request)->searchPattern();
        $communities = Community::query()->select('communities.*')
            ->join('community_members', fn ($j) => $j->on('community_members.community_id', '=', 'communities.id')->where('community_members.user_id', $user->id))
            ->where('communities.status', '!=', CommunityStatus::Suspended->value)
            ->when($archived, fn ($q) => $q->where(fn ($w) => $w->where('community_members.is_archived', true)->orWhere('communities.status', 'archived')),
                fn ($q) => $q->where('community_members.is_archived', false)->where('communities.status', 'active'))
            ->when($search, fn ($q, $p) => $q->where('communities.name', 'ilike', $p))
            ->orderByDesc('community_members.is_pinned')->orderByDesc('communities.updated_at')->limit(200)->get();

        return $this->ok($this->presenter->many($communities, $user));
    }

    public function store(Request $request, EventAccess $access): JsonResponse
    {
        $data = $request->validate([
            'eventId' => ['nullable', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'avatarUploadId' => ['nullable', 'string', 'max:40'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['boolean'],
        ]);
        $event = null;
        if (! empty($data['eventId'])) {
            $event = $access->findForTeam($data['eventId'], $this->customer());
            $access->authorize($this->customer(), $event, 'manage_community');
        }
        $community = $this->communities->create($this->customer(), $data, $event);

        return $this->created($this->presenter->one($community, $this->customer()));
    }

    public function show(string $id): JsonResponse
    {
        $community = $this->find($id);
        $this->communities->requireMember($community, $this->customer());

        return $this->ok($this->presenter->one($community, $this->customer()));
    }

    public function update(Request $request, string $id, UploadService $uploads): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'avatarUploadId' => ['sometimes', 'nullable', 'string', 'max:40'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['boolean'],
        ]);
        $community = $this->find($id);
        $this->communities->requireAdmin($community, $this->customer());
        $changes = array_filter(['name' => $data['name'] ?? null], fn ($v) => $v !== null);
        if (array_key_exists('description', $data)) {
            $changes['description'] = $data['description'];
        }
        if (! empty($data['avatarUploadId'])) {
            $changes['avatar_url'] = $uploads->consume($data['avatarUploadId'], $this->customer(), ['avatar', 'cover'], 'avatarUploadId')->url;
        }
        if (isset($data['permissions'])) {
            $changes['permissions'] = array_replace($community->permissionSet(), array_intersect_key(array_map('boolval', $data['permissions']), Community::DEFAULT_PERMISSIONS));
        }
        $community->forceFill($changes)->save();

        return $this->ok($this->presenter->one($community, $this->customer()));
    }

    public function personalize(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['isMuted' => ['sometimes', 'boolean'], 'isPinned' => ['sometimes', 'boolean'], 'isArchived' => ['sometimes', 'boolean']]);
        $community = $this->find($id);
        $this->communities->requireMember($community, $this->customer());
        $changes = [];
        foreach (['isMuted' => 'is_muted', 'isPinned' => 'is_pinned', 'isArchived' => 'is_archived'] as $k => $col) {
            if (array_key_exists($k, $data)) {
                $changes[$col] = $data[$k];
            }
        }
        if ($changes) {
            CommunityMember::query()->row($community->id, $this->customer()->id)->update($changes);
            if (isset($changes['is_muted'])) {
                DB::table('conversation_participants')->where('user_id', $this->customer()->id)
                    ->whereIn('conversation_id', DB::table('conversations')->where('community_id', $community->id)->select('id'))
                    ->update(['is_muted' => $changes['is_muted']]);
            }
        }

        return $this->ok($this->presenter->one($community, $this->customer()));
    }

    public function join(string $id): JsonResponse
    {
        return $this->created($this->presenter->one($this->communities->join($this->find($id), $this->customer()), $this->customer()));
    }

    public function joinByCode(string $code): JsonResponse
    {
        $community = Community::query()->where('invite_code', strtoupper($code))->first() ?? throw ApiException::notFound('This invite link is invalid or has been reset.');

        return $this->created($this->presenter->one($this->communities->join($community, $this->customer(), viaInvite: true), $this->customer()));
    }

    public function leave(string $id): Response
    {
        $this->communities->leave($this->find($id), $this->customer());

        return $this->noContent();
    }

    public function inviteLink(string $id): JsonResponse
    {
        return $this->ok(['inviteLink' => $this->communities->regenerateInvite($this->find($id), $this->customer())]);
    }

    public function members(Request $request, string $id): JsonResponse
    {
        $community = $this->find($id);
        $this->communities->requireMember($community, $this->customer());
        $list = ListQuery::from($request);
        $query = CommunityMember::query()->with('user')->where('community_id', $community->id)
            ->when($list->searchPattern(), fn ($q, $p) => $q->whereHas('user', fn ($u) => $u->where('display_name', 'ilike', $p)))
            ->orderByRaw("case role when 'owner' then 0 when 'co_admin' then 1 else 2 end")->orderBy('joined_at');

        return $this->paginated($list->paginate($query, 50), fn (CommunityMember $m) => CommunityPresenter::member($m));
    }

    public function invite(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'userIds' => ['sometimes', 'array', 'max:100'], 'userIds.*' => ['string', 'max:40'],
            'emails' => ['sometimes', 'array', 'max:100'], 'emails.*' => ['email', 'max:255'],
        ]);
        $invited = $this->communities->invite($this->find($id), $this->customer(), $data['userIds'] ?? [], $data['emails'] ?? []);

        return $this->created(['invited' => $invited]);
    }

    public function changeRole(Request $request, string $id, string $userId): JsonResponse
    {
        $data = $request->validate(['role' => ['required', 'in:co_admin,member']]);

        return $this->ok(CommunityPresenter::member($this->communities->changeRole($this->find($id), $this->customer(), $userId, $data['role'])));
    }

    public function removeMember(string $id, string $userId): Response
    {
        $this->communities->kick($this->find($id), $this->customer(), $userId);

        return $this->noContent();
    }

    // ---- Statuses ------------------------------------------------------------------

    public function statuses(string $id): JsonResponse
    {
        $community = $this->find($id);
        $this->communities->requireMember($community, $this->customer());
        $statuses = StatusPost::query()->with('author')->where('community_id', $community->id)->where('expires_at', '>', now())->orderBy('created_at')->get();

        return $this->ok($this->statusResources($statuses));
    }

    public function postStatus(Request $request, string $id, UploadService $uploads): JsonResponse
    {
        $data = $request->validate(['mediaUploadId' => ['required', 'string', 'max:40'], 'caption' => ['nullable', 'string', 'max:500'], 'mediaType' => ['nullable', 'in:image,video']]);
        $community = $this->find($id);
        $this->communities->requirePermission($community, $this->customer(), 'membersCanPostStatus');
        $upload = $uploads->consume($data['mediaUploadId'], $this->customer(), 'status', 'mediaUploadId');

        $status = StatusPost::query()->create([
            'community_id' => $community->id,
            'author_user_id' => $this->customer()->id,
            'media_url' => (string) $upload->url,
            'media_type' => $data['mediaType'] ?? (str_starts_with($upload->mime_type, 'video/') ? 'video' : 'image'),
            'upload_id' => $upload->id,
            'caption' => $data['caption'] ?? null,
            'expires_at' => now()->addDay(),
        ]);
        $resource = $this->statusResources(collect([$status->load('author')]))[0];
        Realtime::emit('community.'.$community->id, 'status.created', $resource);

        return $this->ok($resource);
    }

    public function likeStatus(string $statusId): JsonResponse
    {
        $status = $this->activeStatus($statusId);
        $deleted = DB::table('status_likes')->where('status_id', $status->id)->where('user_id', $this->customer()->id)->delete();
        if ($deleted) {
            StatusPost::query()->whereKey($status->id)->where('likes_count', '>', 0)->decrement('likes_count');
        } else {
            DB::table('status_likes')->insertOrIgnore(['status_id' => $status->id, 'user_id' => $this->customer()->id, 'created_at' => now()]);
            StatusPost::query()->whereKey($status->id)->increment('likes_count');
        }

        return $this->ok(['liked' => ! $deleted, 'likesCount' => (int) StatusPost::query()->whereKey($status->id)->value('likes_count')]);
    }

    public function viewStatus(string $statusId): Response
    {
        $status = $this->activeStatus($statusId);
        if ($status->author_user_id !== $this->customer()->id
            && DB::table('status_views')->insertOrIgnore(['status_id' => $status->id, 'user_id' => $this->customer()->id, 'created_at' => now()])) {
            StatusPost::query()->whereKey($status->id)->increment('views_count');
        }

        return $this->noContent();
    }

    public function deleteStatus(string $statusId): Response
    {
        $status = StatusPost::query()->find($statusId) ?? throw ApiException::notFound('Status not found.');
        $community = $this->find($status->community_id);
        $member = $this->communities->requireMember($community, $this->customer());
        if ($status->author_user_id !== $this->customer()->id && ! $member->isAdmin()) {
            throw ApiException::forbidden('You can only delete your own statuses.');
        }
        $status->delete();

        return $this->noContent();
    }

    public function media(Request $request, string $id): JsonResponse
    {
        $community = $this->find($id);
        $this->communities->requireMember($community, $this->customer());
        $docs = $request->query('type') === 'docs';
        $query = Media::query()->select('media.*', 'messages.sender_user_id')
            ->join('messages', fn ($j) => $j->on('messages.id', '=', 'media.owner_id')->where('media.owner_type', 'message'))
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where('conversations.community_id', $community->id)->where('messages.deleted_by', 'none')
            ->when($docs, fn ($q) => $q->where('media.type', 'file'), fn ($q) => $q->whereIn('media.type', ['image', 'video']))
            ->orderByDesc('media.created_at');
        $page = ListQuery::from($request)->paginate($query, 30);
        $senders = User::withTrashed()->whereIn('id', collect($page->items())->pluck('sender_user_id'))->get()->keyBy('id');

        return $this->paginated($page, fn (Media $m) => [
            'id' => $m->id,
            'type' => $m->type === 'file' ? 'pdf' : $m->type,
            'url' => $m->url,
            'thumbnailUrl' => $m->thumbnail_url,
            'width' => $m->width,
            'height' => $m->height,
            'durationSeconds' => $m->duration_seconds,
            'name' => $m->name,
            'sizeBytes' => (int) $m->size_bytes,
            'sender' => Fmt::user($senders[$m->getAttribute('sender_user_id')] ?? null),
            'createdAt' => Fmt::date($m->created_at),
        ]);
    }

    // ---- Live -----------------------------------------------------------------------

    public function live(string $id, LiveService $live): JsonResponse
    {
        $community = $this->find($id);
        $this->communities->requireMember($community, $this->customer());

        return $this->ok(LiveService::resource($live->current($community), null));
    }

    public function startLive(Request $request, string $id, LiveService $live): JsonResponse
    {
        $data = $request->validate(['title' => ['nullable', 'string', 'max:200'], 'audioEnabled' => ['sometimes', 'boolean'], 'videoEnabled' => ['sometimes', 'boolean']]);
        $session = $live->start($this->find($id), $this->customer(), $data['title'] ?? null, $data['audioEnabled'] ?? true, $data['videoEnabled'] ?? true);

        return $this->created(LiveService::resource($session, null));
    }

    public function updateLive(Request $request, string $liveId, LiveService $live): JsonResponse
    {
        $data = $request->validate(['audioEnabled' => ['sometimes', 'boolean'], 'videoEnabled' => ['sometimes', 'boolean']]);

        return $this->ok(LiveService::resource($live->update($this->session($liveId), $this->customer(), $data), null));
    }

    public function endLive(string $liveId, LiveService $live): JsonResponse
    {
        return $this->ok(LiveService::resource($live->end($this->session($liveId), $this->customer())->load('host'), null));
    }

    public function joinLive(string $liveId, LiveService $live): JsonResponse
    {
        return $this->ok($live->join($this->session($liveId), $this->customer()));
    }

    public function liveRequests(string $liveId, LiveService $live): JsonResponse
    {
        $session = $this->session($liveId);
        $live->requireHost($session, $this->customer());

        return $this->ok(LiveRequest::query()->with('user')->where('live_session_id', $session->id)->latest()->get()->map(fn ($r) => LiveService::request($r))->all());
    }

    public function requestLive(string $liveId, LiveService $live): JsonResponse
    {
        return $this->created(LiveService::request($live->requestStage($this->session($liveId), $this->customer())->load('user')));
    }

    public function decideLive(Request $request, string $liveId, string $requestId, LiveService $live): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:accepted,declined']]);

        return $this->ok(LiveService::request($live->decide($this->session($liveId), $this->customer(), $requestId, $data['status'])));
    }

    private function find(string $id): Community
    {
        return Community::query()->find($id) ?? throw ApiException::notFound('Community not found.');
    }

    private function session(string $id): LiveSession
    {
        $session = LiveSession::query()->find($id) ?? throw ApiException::notFound('Live session not found.');
        $this->communities->requireMember($this->find($session->community_id), $this->customer());

        return $session;
    }

    private function activeStatus(string $id): StatusPost
    {
        $status = StatusPost::query()->where('expires_at', '>', now())->find($id) ?? throw ApiException::notFound('Status not found.');
        $this->communities->requireMember($this->find($status->community_id), $this->customer());

        return $status;
    }

    private function statusResources($statuses): array
    {
        $user = $this->customer();
        $ids = $statuses->pluck('id');
        $liked = DB::table('status_likes')->where('user_id', $user->id)->whereIn('status_id', $ids)->pluck('status_id')->flip()->all();
        $viewed = DB::table('status_views')->where('user_id', $user->id)->whereIn('status_id', $ids)->pluck('status_id')->flip()->all();

        return $statuses->map(fn (StatusPost $s) => [
            'id' => $s->id,
            'communityId' => $s->community_id,
            'author' => Fmt::user($s->author),
            'mediaUrl' => $s->media_url,
            'mediaType' => $s->media_type,
            'caption' => $s->caption,
            'likesCount' => $s->likes_count,
            'viewsCount' => $s->views_count,
            'isLiked' => isset($liked[$s->id]),
            'viewedByMe' => isset($viewed[$s->id]) || $s->author_user_id === $user->id,
            'expiresAt' => Fmt::date($s->expires_at),
            'createdAt' => Fmt::date($s->created_at),
        ])->values()->all();
    }
}
