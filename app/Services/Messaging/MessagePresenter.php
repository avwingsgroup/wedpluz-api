<?php

namespace App\Services\Messaging;

use App\Http\Resources\Fmt;
use App\Models\Community;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use App\Services\Communities\CommunityPresenter;
use App\Services\Events\EventPresenter;
use App\Support\Models;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Message, Conversation and ConversationDetail shapes, batched per page. */
class MessagePresenter
{
    public function __construct(private readonly CommunityPresenter $communities) {}

    public function one(Message $message, ?User $viewer): array
    {
        return $this->many(collect([$message]), $viewer)[0];
    }

    /** @param Collection<int, Message> $messages */
    public function many(Collection $messages, ?User $viewer): array
    {
        if ($messages->isEmpty()) {
            return [];
        }
        $messages = Models::collect($messages);
        $messages->loadMissing(['sender', 'attachments', 'replyTo.sender', 'event']);
        $ids = $messages->pluck('id');
        $reactions = DB::table('message_reactions')->whereIn('message_id', $ids)->get()->groupBy('message_id');
        $stars = $viewer ? DB::table('message_stars')->where('user_id', $viewer->id)->whereIn('message_id', $ids)->pluck('message_id')->flip()->all() : [];

        // Read receipts for direct conversations: read when every other participant has read past it.
        $readUpTo = ConversationParticipant::query()->whereIn('conversation_id', $messages->pluck('conversation_id')->unique())
            ->get(['conversation_id', 'user_id', 'last_read_at'])->groupBy('conversation_id');

        return $messages->map(function (Message $m) use ($viewer, $reactions, $stars, $readUpTo) {
            $deleted = $m->isDeleted();
            $grouped = collect($reactions[$m->id] ?? [])->groupBy('emoji');
            $others = collect($readUpTo[$m->conversation_id] ?? [])->where('user_id', '!=', $m->sender_user_id);
            $read = $others->isNotEmpty() && $others->every(fn ($p) => $p->last_read_at !== null && $p->last_read_at->gte($m->created_at));
            $event = $m->event;

            return [
                'id' => $m->id,
                'conversationId' => $m->conversation_id,
                'sender' => Fmt::user($m->sender),
                'type' => $m->type->value,
                'body' => $deleted ? null : $m->body,
                'attachments' => $deleted ? [] : $m->attachments->map(fn ($a) => [
                    'id' => $a->id,
                    'type' => in_array($a->type, ['image', 'video', 'audio', 'file'], true) ? $a->type : 'file',
                    'url' => $a->url,
                    'thumbnailUrl' => $a->thumbnail_url,
                    'name' => $a->name,
                    'sizeBytes' => $a->size_bytes,
                    'durationSeconds' => $a->duration_seconds,
                    'waveform' => $a->waveform ?? [],
                ])->all(),
                'replyTo' => $m->replyTo ? [
                    'id' => $m->replyTo->id,
                    'body' => $m->replyTo->isDeleted() ? null : $m->replyTo->body,
                    'type' => $m->replyTo->type->value,
                    'sender' => Fmt::user($m->replyTo->sender),
                ] : null,
                'reactions' => $grouped->map(fn ($rows, $emoji) => [
                    'emoji' => (string) $emoji,
                    'count' => $rows->count(),
                    'reactedByMe' => $viewer !== null && $rows->contains('user_id', $viewer->id),
                ])->values()->all(),
                'event' => $event ? [
                    'id' => $event->id, 'slug' => $event->slug, 'title' => $event->title,
                    'startsAt' => Fmt::date($event->starts_at) ?? Fmt::date($event->created_at),
                    'coverUrl' => $event->cover_url ?: EventPresenter::placeholderCover(),
                    'isFree' => $event->is_free, 'currency' => $event->currency, 'minPrice' => $event->min_price,
                ] : null,
                'isPinned' => $m->is_pinned,
                'isStarred' => isset($stars[$m->id]),
                'isMine' => $viewer !== null && $m->sender_user_id === $viewer->id,
                'deletedBy' => $m->deleted_by,
                'status' => $read ? 'read' : 'sent',
                'createdAt' => Fmt::date($m->created_at),
                'clientId' => $m->client_id,
            ];
        })->values()->all();
    }

    /** @param Collection<int, Conversation> $conversations */
    public function conversations(Collection $conversations, User $viewer): array
    {
        if ($conversations->isEmpty()) {
            return [];
        }
        $conversations = Models::collect($conversations);
        $conversations->loadMissing(['lastMessage.sender', 'community']);
        $ids = $conversations->pluck('id');
        $participants = ConversationParticipant::query()->with('user')->whereIn('conversation_id', $ids)->get()->groupBy('conversation_id');

        return $conversations->map(function (Conversation $c) use ($viewer, $participants) {
            $rows = $participants[$c->id] ?? collect();
            $mine = $rows->firstWhere('user_id', $viewer->id);
            $others = $rows->where('user_id', '!=', $viewer->id);
            $other = $others->first()?->user;
            $last = $c->lastMessage;
            $isCommunity = $c->type === 'community';

            return [
                'id' => $c->id,
                'type' => $isCommunity ? 'community' : ($mine?->is_request ? 'request' : 'direct'),
                'title' => $isCommunity ? (string) $c->community?->name : (Fmt::user($other)['displayName'] ?? 'Wedpluz user'),
                'avatarUrl' => $isCommunity ? $c->community?->avatar_url : (Fmt::user($other)['avatarUrl'] ?? null),
                'participants' => $rows->take(10)->map(fn ($p) => Fmt::user($p->user))->filter()->values()->all(),
                'communityId' => $c->community_id,
                'lastMessage' => $last ? [
                    'id' => $last->id,
                    'body' => $last->isDeleted() ? null : $last->body,
                    'createdAt' => Fmt::date($last->created_at),
                    'type' => $last->type->value,
                    'sender' => Fmt::user($last->sender),
                ] : null,
                'unreadCount' => (int) ($mine?->unread_count ?? 0),
                'isPinned' => (bool) $mine?->is_pinned,
                'isMuted' => (bool) $mine?->is_muted,
                'isArchived' => (bool) $mine?->is_archived,
                'isRequest' => (bool) $mine?->is_request,
                'updatedAt' => Fmt::date($c->last_message_at ?? $c->updated_at),
            ];
        })->values()->all();
    }

    public function detail(Conversation $conversation, User $viewer): array
    {
        $base = $this->conversations(collect([$conversation]), $viewer)[0];
        $pinned = Message::query()->where('conversation_id', $conversation->id)->where('is_pinned', true)->where('deleted_by', 'none')->latest()->limit(20)->get();
        $community = $conversation->community_id ? Community::query()->find($conversation->community_id) : null;

        return $base + [
            'community' => $community ? $this->communities->one($community, $viewer) : null,
            'pinnedMessages' => $this->many($pinned, $viewer),
        ];
    }
}
