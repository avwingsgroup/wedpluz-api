<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Fmt;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Media;
use App\Models\Message;
use App\Models\User;
use App\Services\Messaging\MessagePresenter;
use App\Services\Messaging\MessagingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class MessagingController extends Controller
{
    public function __construct(private readonly MessagingService $messaging, private readonly MessagePresenter $presenter) {}

    /** GET /conversations — keyset paginated by last activity. */
    public function index(Request $request): JsonResponse
    {
        $user = $this->customer();
        $tab = (string) $request->query('tab', 'all');
        $search = trim((string) $request->query('search', ''));
        $blocked = DB::table('blocks')->where('blocker_user_id', $user->id)->pluck('blocked_user_id');

        $query = Conversation::query()->select('conversations.*')
            ->join('conversation_participants as me', fn ($j) => $j->on('me.conversation_id', '=', 'conversations.id')->where('me.user_id', $user->id))
            ->whereNull('me.deleted_at')
            ->where(fn ($q) => $q->whereNotNull('conversations.last_message_id')->orWhere('conversations.type', 'community'))
            ->where(fn ($q) => $q->whereNull('me.cleared_at')->orWhere('conversations.last_message_at', '>', DB::raw('me.cleared_at')))
            ->whereNotExists(fn ($q) => $q->from('conversation_participants as other')->whereColumn('other.conversation_id', 'conversations.id')
                ->where('conversations.type', 'direct')->whereIn('other.user_id', $blocked));

        match ($tab) {
            'unread' => $query->where('me.unread_count', '>', 0)->where('me.is_request', false),
            'communities' => $query->where('conversations.type', 'community'),
            'requests' => $query->where('me.is_request', true)->whereNull('me.request_declined_at'),
            default => $query->where('me.is_request', false),
        };
        if (mb_strlen($search) >= 2) {
            $pattern = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn ($q) => $q
                ->whereHas('community', fn ($c) => $c->where('name', 'ilike', $pattern))
                ->orWhereExists(fn ($s) => $s->from('conversation_participants as p')->join('users', 'users.id', '=', 'p.user_id')
                    ->whereColumn('p.conversation_id', 'conversations.id')->where('p.user_id', '!=', $user->id)->where('users.display_name', 'ilike', $pattern)));
        }

        $page = $query->orderByDesc('me.is_pinned')->orderByRaw('coalesce(conversations.last_message_at, conversations.created_at) desc')->orderByDesc('conversations.id')
            ->cursorPaginate(30, ['conversations.*'], 'cursor', $request->query('cursor'));

        $requests = ConversationParticipant::query()->where('user_id', $user->id)->where('is_request', true)
            ->whereNull('request_declined_at')->whereNull('deleted_at')
            ->whereHas('conversation', fn ($c) => $c->whereNotNull('last_message_id'))->count();

        return $this->ok(['items' => $this->presenter->conversations(collect($page->items()), $user), 'requestsCount' => $requests]);
    }

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate(['userId' => ['required', 'string', 'max:40']]);
        $conversation = $this->messaging->startDirect($this->customer(), $data['userId']);

        return $this->created($this->presenter->conversations(collect([$conversation]), $this->customer())[0]);
    }

    public function show(string $id): JsonResponse
    {
        return $this->ok($this->presenter->detail($this->conversation($id), $this->customer()));
    }

    public function messages(Request $request, string $id): JsonResponse
    {
        $conversation = $this->conversation($id);
        $mine = $this->messaging->participant($conversation, $this->customer());
        $page = Message::query()->where('conversation_id', $conversation->id)
            ->when($mine->cleared_at, fn ($q, $at) => $q->where('created_at', '>', $at))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->cursorPaginate(30, ['*'], 'cursor', $request->query('cursor'));

        $items = collect($page->items())->reverse()->values();

        return response()->json([
            'data' => $this->presenter->many($items, $this->customer()),
            'meta' => ['nextCursor' => $page->nextCursor()?->encode(), 'prevCursor' => $page->previousCursor()?->encode(), 'perPage' => 30],
        ]);
    }

    public function send(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'clientId' => ['required', 'string', 'max:64'],
            'type' => ['required', 'in:text,image,video,audio,file,event_promo'],
            'body' => ['nullable', 'string', 'max:5000'],
            'attachmentUploadIds' => ['sometimes', 'array', 'max:10'],
            'attachmentUploadIds.*' => ['string', 'max:40'],
            'replyToId' => ['nullable', 'string', 'max:40'],
            'eventId' => ['nullable', 'string', 'max:40'],
        ]);
        $message = $this->messaging->send($this->conversation($id), $this->customer(), $data);

        return $this->ok($this->presenter->one($message->refresh(), $this->customer()));
    }

    public function deleteMessage(string $id): JsonResponse
    {
        return $this->ok($this->presenter->one($this->messaging->delete($this->message($id), $this->customer()), $this->customer()));
    }

    public function react(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['emoji' => ['required', 'string', 'max:32']]);

        return $this->ok($this->presenter->one($this->messaging->toggleReaction($this->message($id), $this->customer(), $data['emoji']), $this->customer()));
    }

    public function star(string $id): JsonResponse
    {
        return $this->ok($this->presenter->one($this->messaging->toggleStar($this->message($id), $this->customer()), $this->customer()));
    }

    public function pin(string $id): JsonResponse
    {
        return $this->ok($this->presenter->one($this->messaging->togglePin($this->message($id), $this->customer()), $this->customer()));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['isPinned' => ['sometimes', 'boolean'], 'isMuted' => ['sometimes', 'boolean'], 'isArchived' => ['sometimes', 'boolean']]);
        $conversation = $this->conversation($id);
        $map = ['isPinned' => 'is_pinned', 'isMuted' => 'is_muted', 'isArchived' => 'is_archived'];
        $changes = [];
        foreach ($map as $k => $col) {
            if (array_key_exists($k, $data)) {
                $changes[$col] = $data[$k];
            }
        }
        if ($changes) {
            ConversationParticipant::query()->where('conversation_id', $conversation->id)->where('user_id', $this->customer()->id)->update($changes + ['updated_at' => now()]);
        }

        return $this->ok($this->presenter->conversations(collect([$conversation]), $this->customer())[0]);
    }

    public function destroy(string $id): Response
    {
        $this->messaging->deleteForMe($this->conversation($id), $this->customer());

        return $this->noContent();
    }

    public function read(string $id): Response
    {
        $this->messaging->markRead($this->conversation($id), $this->customer());

        return $this->noContent();
    }

    public function respondRequest(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['action' => ['required', 'in:accept,decline']]);
        $conversation = $this->conversation($id);
        $this->messaging->respondToRequest($conversation, $this->customer(), $data['action']);

        return $this->ok($this->presenter->conversations(collect([$conversation]), $this->customer())[0]);
    }

    public function media(string $id): JsonResponse
    {
        $conversation = $this->conversation($id);
        $items = Media::query()->select('media.*', 'messages.sender_user_id', 'messages.id as message_id')
            ->join('messages', fn ($j) => $j->on('messages.id', '=', 'media.owner_id')->where('media.owner_type', 'message'))
            ->where('messages.conversation_id', $conversation->id)->where('messages.deleted_by', 'none')
            ->orderByDesc('media.created_at')->limit(200)->get();
        $senders = User::withTrashed()->whereIn('id', $items->pluck('sender_user_id')->unique())->get()->keyBy('id');

        return $this->ok($items->map(fn ($m) => [
            'id' => $m->id,
            'messageId' => $m->getAttribute('message_id'),
            'sender' => Fmt::user($senders[$m->getAttribute('sender_user_id')] ?? null),
            'createdAt' => Fmt::date($m->created_at),
            'type' => in_array($m->type, ['image', 'video', 'audio', 'file'], true) ? $m->type : 'file',
            'url' => $m->url,
            'thumbnailUrl' => $m->thumbnail_url,
            'name' => $m->name,
            'sizeBytes' => $m->size_bytes,
            'durationSeconds' => $m->duration_seconds,
            'waveform' => $m->waveform ?? [],
        ])->all());
    }

    public function unreadCount(): JsonResponse
    {
        return $this->ok(['count' => $this->messaging->unreadCount($this->customer())]);
    }

    private function conversation(string $id): Conversation
    {
        $conversation = Conversation::query()->find($id);
        if ($conversation === null) {
            throw ApiException::notFound('Conversation not found.');
        }
        $this->messaging->participant($conversation, $this->customer());

        return $conversation;
    }

    private function message(string $id): Message
    {
        $message = Message::query()->find($id) ?? throw ApiException::notFound('Message not found.');
        $this->conversation($message->conversation_id);

        return $message;
    }
}
