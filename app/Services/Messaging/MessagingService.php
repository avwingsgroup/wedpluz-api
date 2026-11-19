<?php

namespace App\Services\Messaging;

use App\Enums\MessageType;
use App\Exceptions\ApiException;
use App\Models\Community;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Event;
use App\Models\Media;
use App\Models\Message;
use App\Models\User;
use App\Services\Communities\CommunityService;
use App\Services\Media\UploadService;
use App\Services\Notifications\NotificationService;
use App\Services\Realtime\Realtime;
use App\Support\Sql;
use Illuminate\Support\Facades\DB;

/**
 * Direct and community messaging (business-rules.md, "Communities and messaging").
 *
 * - Users with allowMessageRequests = false cannot be messaged by strangers (403).
 * - A first message to a non-connection creates a request on the recipient's side.
 * - Blocking hides the conversation and rejects further messages.
 * - `clientId` makes sends idempotent (unique per sender).
 * - Community conversations follow the community's member permissions.
 */
class MessagingService
{
    public function __construct(
        private readonly UploadService $uploads,
        private readonly CommunityService $communities,
        private readonly NotificationService $notifications,
        private readonly MessagePresenter $presenter,
    ) {}

    public function startDirect(User $user, string $otherUserId): Conversation
    {
        $other = User::query()->whereKey($otherUserId)->whereNull('anonymized_at')->first();
        if ($other === null || $other->id === $user->id) {
            throw ApiException::validation('You cannot message this user.', ['userId' => ['Select another user.']]);
        }
        $this->assertNotBlocked($user->id, $other->id);

        $key = Conversation::directKey($user->id, $other->id);
        $existing = Conversation::query()->where('direct_key', $key)->first();
        if ($existing) {
            ConversationParticipant::query()->where('conversation_id', $existing->id)->where('user_id', $user->id)->update(['deleted_at' => null]);

            return $existing;
        }

        $connected = $this->isConnected($user->id, $other->id);
        if (! $connected && ! $this->allowsRequests($other->id)) {
            throw ApiException::forbidden($other->display_name.' is not accepting message requests.');
        }

        return Sql::insertOrNull(function () use ($key, $user, $other, $connected) {
            $conversation = Conversation::query()->create(['type' => 'direct', 'direct_key' => $key]);
            ConversationParticipant::query()->insert([
                ['conversation_id' => $conversation->id, 'user_id' => $user->id, 'is_request' => false, 'created_at' => now(), 'updated_at' => now()],
                ['conversation_id' => $conversation->id, 'user_id' => $other->id, 'is_request' => ! $connected, 'created_at' => now(), 'updated_at' => now()],
            ]);

            return $conversation;
        }) ?? Conversation::query()->where('direct_key', $key)->firstOrFail();
    }

    public function participant(Conversation $conversation, User $user): ConversationParticipant
    {
        $participant = ConversationParticipant::query()->where('conversation_id', $conversation->id)->where('user_id', $user->id)->first();
        if ($participant === null) {
            throw ApiException::notFound('Conversation not found.');
        }

        return $participant;
    }

    /** @param array{clientId:string, type:string, body?:?string, attachmentUploadIds?:list<string>, replyToId?:?string, eventId?:?string} $data */
    public function send(Conversation $conversation, User $sender, array $data): Message
    {
        $mine = $this->participant($conversation, $sender);

        $existing = Message::query()->where('sender_user_id', $sender->id)->where('client_id', $data['clientId'])->first();
        if ($existing) {
            return $existing; // idempotent retry
        }

        $others = ConversationParticipant::query()->where('conversation_id', $conversation->id)->where('user_id', '!=', $sender->id)->get();
        if ($conversation->type === 'community') {
            $community = Community::query()->findOrFail($conversation->community_id);
            $this->communities->requirePermission($community, $sender, 'membersCanSendMessages');
        } else {
            $other = $others->first();
            if ($other) {
                $this->assertNotBlocked($sender->id, $other->user_id);
                if ($mine->is_request && $mine->request_declined_at) {
                    throw ApiException::forbidden('You declined this message request.');
                }
                if ($other->is_request && $other->request_declined_at) {
                    throw ApiException::forbidden('This user is not accepting your messages.');
                }
                if ($other->is_request && ! $this->allowsRequests($other->user_id)) {
                    throw ApiException::forbidden('This user is not accepting message requests.');
                }
            }
            if ($mine->is_request) {
                // Replying accepts the request.
                $mine->forceFill(['is_request' => false])->save();
            }
        }

        $type = MessageType::from($data['type']);
        $body = isset($data['body']) ? trim((string) $data['body']) : null;
        $uploadIds = $data['attachmentUploadIds'] ?? [];
        if ($type === MessageType::Text && ($body === null || $body === '')) {
            throw ApiException::validation('Write a message.', ['body' => ['The message cannot be empty.']]);
        }
        if (in_array($type, [MessageType::Image, MessageType::Video, MessageType::Audio, MessageType::File], true) && $uploadIds === []) {
            throw ApiException::validation('Attach a file.', ['attachmentUploadIds' => ['Attach at least one file.']]);
        }
        if ($type === MessageType::System) {
            throw ApiException::validation('Invalid message type.', ['type' => ['System messages cannot be sent by users.']]);
        }
        $eventId = null;
        if ($type === MessageType::EventPromo) {
            $event = isset($data['eventId']) ? Event::query()->discoverable()->find($data['eventId']) : null;
            if ($event === null) {
                throw ApiException::validation('Select an event to share.', ['eventId' => ['The selected event is not available.']]);
            }
            $eventId = $event->id;
        }
        $replyTo = null;
        if (! empty($data['replyToId'])) {
            $replyTo = Message::query()->where('conversation_id', $conversation->id)->find($data['replyToId']);
            if ($replyTo === null) {
                throw ApiException::validation('The message you are replying to was not found.', ['replyToId' => ['Invalid reply.']]);
            }
        }

        $message = DB::transaction(function () use ($conversation, $sender, $data, $type, $body, $uploadIds, $eventId, $replyTo) {
            $message = Sql::insertOrNull(fn () => Message::query()->create([
                'conversation_id' => $conversation->id,
                'sender_user_id' => $sender->id,
                'client_id' => $data['clientId'],
                'type' => $type,
                'body' => $body !== null ? mb_substr($body, 0, 5000) : null,
                'reply_to_id' => $replyTo?->id,
                'event_id' => $eventId,
            ]));
            if ($message === null) {
                return Message::query()->where('sender_user_id', $sender->id)->where('client_id', $data['clientId'])->firstOrFail();
            }

            foreach (array_values($uploadIds) as $i => $uploadId) {
                $upload = $this->uploads->consume($uploadId, $sender, 'message_attachment', "attachmentUploadIds.$i");
                Media::query()->create([
                    'owner_type' => 'message', 'owner_id' => $message->id, 'upload_id' => $upload->id,
                    'type' => match (true) {
                        str_starts_with($upload->mime_type, 'image/') => 'image',
                        str_starts_with($upload->mime_type, 'video/') => 'video',
                        str_starts_with($upload->mime_type, 'audio/') => 'audio',
                        default => 'file',
                    },
                    'url' => (string) $upload->url, 'thumbnail_url' => $upload->thumbnail_url, 'name' => $upload->original_name,
                    'size_bytes' => $upload->size_bytes, 'width' => $upload->width, 'height' => $upload->height,
                    'duration_seconds' => $upload->duration_seconds, 'provider' => $upload->provider,
                    'provider_public_id' => $upload->provider_public_id, 'sort_order' => $i,
                ]);
            }

            $conversation->forceFill(['last_message_id' => $message->id, 'last_message_at' => $message->created_at])->save();
            ConversationParticipant::query()->where('conversation_id', $conversation->id)->where('user_id', '!=', $sender->id)
                ->update(['unread_count' => DB::raw('unread_count + 1'), 'deleted_at' => null, 'updated_at' => now()]);
            ConversationParticipant::query()->where('conversation_id', $conversation->id)->where('user_id', $sender->id)
                ->update(['last_read_message_id' => $message->id, 'last_read_at' => now(), 'deleted_at' => null, 'updated_at' => now()]);

            return $message;
        });

        $this->afterSend($conversation, $message, $sender, $others);

        return $message;
    }

    private function afterSend(Conversation $conversation, Message $message, User $sender, $others): void
    {
        $payload = $this->presenter->one($message, null) + ['clientId' => $message->client_id];
        $payload['isMine'] = false;
        Realtime::emit('conversation.'.$conversation->id, 'message.created', $payload);

        foreach ($others as $participant) {
            Realtime::toUser($participant->user_id, 'message.created', $payload);
            if ($participant->is_muted) {
                continue;
            }
            $recipient = User::query()->find($participant->user_id);
            if ($recipient === null) {
                continue;
            }
            $isFirstRequest = $conversation->type === 'direct' && $participant->is_request
                && Message::query()->where('conversation_id', $conversation->id)->count() === 1;
            if ($isFirstRequest) {
                $this->notifications->notify($recipient, 'message_request', [
                    'actor' => $sender,
                    'data' => ['conversationId' => $conversation->id],
                    'dedupe' => 'msg-request:'.$conversation->id,
                ]);
            } elseif ($conversation->type === 'direct') {
                $this->notifications->pushOnly($recipient, 'messages', $sender->display_name, mb_substr((string) ($message->body ?? 'Sent an attachment'), 0, 120), [
                    'type' => 'message', 'conversationId' => $conversation->id,
                ], 'msg:'.$message->id.':'.$recipient->id);
            }
        }
    }

    public function delete(Message $message, User $user): Message
    {
        if ($message->sender_user_id !== $user->id) {
            throw ApiException::forbidden('You can only delete your own messages.');
        }
        if (! $message->isDeleted()) {
            $message->forceFill(['deleted_by' => 'sender', 'deleted_at' => now(), 'is_pinned' => false])->save();
            Realtime::emit('conversation.'.$message->conversation_id, 'message.deleted', ['id' => $message->id, 'conversationId' => $message->conversation_id, 'deletedBy' => 'sender']);
        }

        return $message;
    }

    public function toggleReaction(Message $message, User $user, string $emoji): Message
    {
        $exists = DB::table('message_reactions')->where('message_id', $message->id)->where('user_id', $user->id)->where('emoji', $emoji)->delete();
        if (! $exists) {
            DB::table('message_reactions')->insertOrIgnore(['message_id' => $message->id, 'user_id' => $user->id, 'emoji' => $emoji, 'created_at' => now()]);
        }
        $this->broadcastUpdate($message);

        return $message;
    }

    public function toggleStar(Message $message, User $user): Message
    {
        $deleted = DB::table('message_stars')->where('message_id', $message->id)->where('user_id', $user->id)->delete();
        if (! $deleted) {
            DB::table('message_stars')->insertOrIgnore(['message_id' => $message->id, 'user_id' => $user->id, 'created_at' => now()]);
        }

        return $message;
    }

    public function togglePin(Message $message, User $user): Message
    {
        $conversation = Conversation::query()->findOrFail($message->conversation_id);
        if ($conversation->type === 'community') {
            $this->communities->requireAdmin(Community::query()->findOrFail($conversation->community_id), $user);
        }
        $message->forceFill(['is_pinned' => ! $message->is_pinned])->save();
        $this->broadcastUpdate($message);

        return $message;
    }

    public function markRead(Conversation $conversation, User $user): void
    {
        $this->participant($conversation, $user);
        ConversationParticipant::query()->where('conversation_id', $conversation->id)->where('user_id', $user->id)->update([
            'unread_count' => 0, 'last_read_message_id' => $conversation->last_message_id, 'last_read_at' => now(), 'updated_at' => now(),
        ]);
        $payload = ['conversationId' => $conversation->id, 'userId' => $user->id, 'readAt' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'lastReadMessageId' => $conversation->last_message_id];
        Realtime::emit('conversation.'.$conversation->id, 'conversation.read', $payload);
        Realtime::toUser($user->id, 'conversation.read', $payload);
    }

    public function respondToRequest(Conversation $conversation, User $user, string $action): void
    {
        $participant = $this->participant($conversation, $user);
        if (! $participant->is_request) {
            throw ApiException::conflict('This conversation is not a message request.');
        }
        $participant->forceFill($action === 'accept'
            ? ['is_request' => false, 'request_declined_at' => null]
            : ['request_declined_at' => now(), 'is_archived' => true, 'deleted_at' => now()])->save();
    }

    public function deleteForMe(Conversation $conversation, User $user): void
    {
        $participant = $this->participant($conversation, $user);
        if ($conversation->type === 'community') {
            $participant->forceFill(['is_archived' => true])->save();

            return;
        }
        $participant->forceFill(['deleted_at' => now(), 'cleared_at' => now(), 'unread_count' => 0])->save();
    }

    public function unreadCount(User $user): int
    {
        return (int) ConversationParticipant::query()->where('user_id', $user->id)->whereNull('deleted_at')->where('is_request', false)->sum('unread_count');
    }

    public function isBlocked(string $a, string $b): bool
    {
        return DB::table('blocks')->where(fn ($q) => $q->where('blocker_user_id', $a)->where('blocked_user_id', $b))
            ->orWhere(fn ($q) => $q->where('blocker_user_id', $b)->where('blocked_user_id', $a))->exists();
    }

    private function assertNotBlocked(string $a, string $b): void
    {
        if ($this->isBlocked($a, $b)) {
            throw ApiException::forbidden('You cannot message this user.');
        }
    }

    private function allowsRequests(string $userId): bool
    {
        return (bool) (DB::table('user_privacy_settings')->where('user_id', $userId)->value('allow_message_requests') ?? true);
    }

    /** Connected: share a community, or the other side already replied to them before. */
    private function isConnected(string $a, string $b): bool
    {
        return DB::table('community_members as m1')->join('community_members as m2', 'm1.community_id', '=', 'm2.community_id')
            ->where('m1.user_id', $a)->where('m2.user_id', $b)->exists();
    }

    private function broadcastUpdate(Message $message): void
    {
        Realtime::emit('conversation.'.$message->conversation_id, 'message.updated', $this->presenter->one($message->refresh(), null));
    }
}
