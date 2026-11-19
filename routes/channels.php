<?php

use App\Enums\AdminPermission;
use App\Models\AdminUser;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Event;
use App\Models\User;
use App\Services\Events\EventAccess;
use Illuminate\Support\Facades\Broadcast;

/*
| Channel authorisation for POST /api/v1/broadcasting/auth (realtime-events.md §2).
| The route runs behind auth:sanctum, so $user is either a customer (User) or
| an AdminUser depending on the bearer token. Each rule mirrors the REST policy
| for the same resource and never trusts a client-supplied role. Returning
| false yields 403; an array (presence channels) is the member payload.
*/

$customer = fn ($user): ?User => $user instanceof User && ! $user->isSuspended() && $user->currentAccessToken()?->can('customer') ? $user : null;
$admin = fn ($user): ?AdminUser => $user instanceof AdminUser && $user->isActive() && $user->currentAccessToken()?->can('admin') ? $user : null;

$inConversation = function (User $user, string $conversationId): bool {
    $conversation = Conversation::query()->find($conversationId);
    if ($conversation === null) {
        return false;
    }
    if ($conversation->type === 'community' && $conversation->community_id) {
        return CommunityMember::query()->where('community_id', $conversation->community_id)->where('user_id', $user->id)->exists();
    }

    return ConversationParticipant::query()->where('conversation_id', $conversationId)->where('user_id', $user->id)->exists();
};

Broadcast::channel('user.{userId}', fn ($user, string $userId) => ($u = $customer($user)) !== null && $u->id === $userId);

// Serves both private-conversation.{id} (messages) and presence-conversation.{id}
// (typing, online): Laravel strips the prefix before matching. The member
// summary is returned so presence subscribers receive channel_data.
Broadcast::channel('conversation.{conversationId}', function ($user, string $conversationId) use ($customer, $inConversation) {
    $u = $customer($user);
    if ($u === null || ! $inConversation($u, $conversationId)) {
        return false;
    }

    return ['id' => $u->id, 'name' => $u->display_name, 'username' => $u->username, 'avatarUrl' => $u->avatar_url];
});

Broadcast::channel('community.{communityId}', function ($user, string $communityId) use ($customer) {
    $u = $customer($user);

    return $u !== null && Community::query()->whereKey($communityId)->exists()
        && CommunityMember::query()->where('community_id', $communityId)->where('user_id', $u->id)->exists();
});

Broadcast::channel('event.{eventId}.manage', function ($user, string $eventId) use ($customer) {
    $u = $customer($user);
    $event = $u ? Event::query()->find($eventId) : null;
    if ($event === null) {
        return false;
    }
    $access = app(EventAccess::class);

    return $access->can($u, $event, 'manage_guests') || $access->can($u, $event, 'scan_tickets');
});

Broadcast::channel('admin', fn ($user) => $admin($user) !== null);

Broadcast::channel('admin.moderation', fn ($user) => ($a = $admin($user)) !== null && $a->hasPermission(AdminPermission::ModerationView->value));
