<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\AppNotification;
use App\Models\CommunityInvite;
use App\Services\Communities\CommunityService;
use App\Services\Events\CollaborationService;
use App\Services\Realtime\Realtime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tab = (string) $request->query('tab', 'all');
        $search = trim((string) $request->query('search', ''));
        $page = AppNotification::query()->with('actor')->where('user_id', $this->customer()->id)
            ->when($tab === 'requests', fn ($q) => $q->where('is_request', true))
            ->when(mb_strlen($search) >= 2, fn ($q) => $q->where(fn ($w) => $w->where('title', 'ilike', '%'.addcslashes($search, '%_\\').'%')->orWhere('body', 'ilike', '%'.addcslashes($search, '%_\\').'%')))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->cursorPaginate(30, ['*'], 'cursor', $request->query('cursor'));

        return $this->cursorPaginated($page, NotificationResource::class);
    }

    public function unreadCount(): JsonResponse
    {
        $base = AppNotification::query()->where('user_id', $this->customer()->id)->whereNull('read_at');

        return $this->ok([
            'count' => (clone $base)->count(),
            'requests' => (clone $base)->where('is_request', true)->where('action_state', 'pending')->count(),
        ]);
    }

    public function readAll(): Response
    {
        AppNotification::query()->where('user_id', $this->customer()->id)->whereNull('read_at')->update(['read_at' => now()]);
        Realtime::toUser($this->customer()->id, 'notification.read', ['id' => null, 'all' => true]);

        return $this->noContent();
    }

    public function read(string $id): JsonResponse
    {
        $notification = AppNotification::query()->with('actor')->where('user_id', $this->customer()->id)->find($id);
        if ($notification && $notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
            Realtime::toUser($this->customer()->id, 'notification.read', ['id' => $id, 'all' => false]);
        }

        return $this->ok($notification ? new NotificationResource($notification) : null);
    }

    public function act(Request $request, string $id, CollaborationService $collaborations, CommunityService $communities): JsonResponse
    {
        $data = $request->validate(['action' => ['required', 'in:accept,decline,register']]);
        $notification = AppNotification::query()->with('actor')->where('user_id', $this->customer()->id)->find($id)
            ?? throw ApiException::notFound('Notification not found.');

        if ($data['action'] === 'register') {
            $notification->forceFill(['action_state' => 'done', 'read_at' => $notification->read_at ?? now()])->save();

            return $this->ok(new NotificationResource($notification));
        }
        if ($notification->action !== 'accept_decline') {
            throw ApiException::validation('This notification has no action.', ['action' => ['This notification cannot be accepted or declined.']]);
        }
        if ($notification->action_state !== 'pending') {
            throw ApiException::conflict('You have already responded to this.');
        }

        $accept = $data['action'] === 'accept';
        match ($notification->subject_type) {
            'event_manager', 'event_vendor_assignment' => $collaborations->respond($this->customer(), (string) $notification->subject_id, $accept ? 'accepted' : 'declined'),
            'community_invite' => $communities->respondToInvite(CommunityInvite::query()->findOrFail($notification->subject_id), $this->customer(), $accept),
            default => throw ApiException::conflict('This invite is no longer available.'),
        };
        $notification->forceFill(['action_state' => $accept ? 'accepted' : 'declined', 'read_at' => $notification->read_at ?? now()])->save();

        return $this->ok(new NotificationResource($notification->refresh()->load('actor')));
    }

    public function destroy(string $id): Response
    {
        AppNotification::query()->where('user_id', $this->customer()->id)->whereKey($id)->delete();

        return $this->noContent();
    }
}
