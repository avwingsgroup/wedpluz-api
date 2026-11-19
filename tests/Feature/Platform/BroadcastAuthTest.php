<?php

use App\Models\Conversation;
use App\Models\ConversationParticipant;

function channelAuth($test, string $channel)
{
    return $test->postJson('/api/v1/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $channel]);
}

it('authorises a customer only on their own user channel', function () {
    $me = $this->asCustomer();
    $other = $this->customer();

    channelAuth($this, 'private-user.'.$me->id)->assertOk()->assertJsonStructure(['auth']);
    channelAuth($this, 'private-user.'.$other->id)->assertForbidden();
});

it('limits conversation channels to participants and returns presence data', function () {
    $me = $this->customer();
    $conversation = Conversation::query()->create(['type' => 'direct', 'direct_key' => 'k1']);
    ConversationParticipant::query()->create(['conversation_id' => $conversation->id, 'user_id' => $me->id]);

    $this->asCustomer($me);
    channelAuth($this, 'private-conversation.'.$conversation->id)->assertOk();
    $presence = channelAuth($this, 'presence-conversation.'.$conversation->id)->assertOk()->json('channel_data');
    expect(json_decode($presence, true)['user_info']['id'])->toBe($me->id);

    $this->asCustomer();
    channelAuth($this, 'private-conversation.'.$conversation->id)->assertForbidden();
});

it('limits event operations channels to the team', function () {
    $organizer = $this->customer();
    $event = $this->liveEvent($organizer);

    $this->asCustomer($organizer);
    channelAuth($this, "private-event.{$event->id}.manage")->assertOk();

    $this->asCustomer();
    channelAuth($this, "private-event.{$event->id}.manage")->assertForbidden();
});

it('separates admin and customer channels', function () {
    $this->asCustomer();
    channelAuth($this, 'private-admin')->assertForbidden();

    $this->asAdmin(null, ['dashboard.view']);
    channelAuth($this, 'private-admin')->assertOk();
    channelAuth($this, 'private-admin.moderation')->assertForbidden();

    $this->asAdmin(null, ['moderation.view']);
    channelAuth($this, 'private-admin.moderation')->assertOk();
});

it('rejects unauthenticated channel auth', function () {
    channelAuth($this, 'private-admin')->assertUnauthorized();
});
