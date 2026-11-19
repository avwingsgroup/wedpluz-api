<?php

use App\Models\Community;
use App\Models\Message;

it('starts a direct conversation, sends idempotently by clientId and tracks unread', function () {
    $alice = $this->customer();
    $bob = $this->customer();

    $this->asCustomer($alice);
    $conversationId = $this->postJson('/api/v1/conversations', ['userId' => $bob->id])->assertSuccessful()->json('data.id');
    // Starting again returns the same conversation.
    expect($this->postJson('/api/v1/conversations', ['userId' => $bob->id])->json('data.id'))->toBe($conversationId);

    $send = fn () => $this->postJson("/api/v1/conversations/{$conversationId}/messages", ['clientId' => 'c-1', 'type' => 'text', 'body' => 'Hello <b>Bob</b>']);
    $first = $send()->assertSuccessful()->json('data');
    $retry = $send()->assertSuccessful()->json('data');
    expect($retry['id'])->toBe($first['id'])->and($first['clientId'])->toBe('c-1');
    expect(Message::query()->where('conversation_id', $conversationId)->count())->toBe(1);

    // Bob is not connected to Alice, so it arrives as a message request: not in the badge until accepted.
    $this->asCustomer($bob);
    $this->getJson('/api/v1/messages/unread-count')->assertOk()->assertJsonPath('data.count', 0);
    $this->patchJson("/api/v1/conversations/{$conversationId}/request", ['action' => 'accept'])->assertOk();
    $this->getJson('/api/v1/messages/unread-count')->assertJsonPath('data.count', 1);
    $this->getJson("/api/v1/conversations/{$conversationId}/messages")->assertOk()->assertJsonPath('data.0.id', $first['id']);
    $this->postJson("/api/v1/conversations/{$conversationId}/read")->assertSuccessful();
    $this->getJson('/api/v1/messages/unread-count')->assertJsonPath('data.count', 0);
});

it('hides conversations from non-participants', function () {
    $alice = $this->customer();
    $bob = $this->customer();
    $this->asCustomer($alice);
    $conversationId = $this->postJson('/api/v1/conversations', ['userId' => $bob->id])->json('data.id');

    $this->asCustomer();
    $this->getJson("/api/v1/conversations/{$conversationId}")->assertNotFound();
    $this->postJson("/api/v1/conversations/{$conversationId}/messages", ['clientId' => 'x', 'type' => 'text', 'body' => 'hi'])->assertNotFound();
});

it('creates a community, lets others join by invite code and enforces owner-only settings', function () {
    $owner = $this->customer();
    $this->asCustomer($owner);
    $community = $this->postJson('/api/v1/communities', ['name' => 'Lagos Weddings', 'description' => 'Planning crew'])->assertCreated()->json('data');
    $code = $this->postJson("/api/v1/communities/{$community['id']}/invite-link")->assertSuccessful()->json('data.code')
        ?? Community::query()->find($community['id'])->invite_code;

    $member = $this->customer();
    $this->asCustomer($member);
    $this->postJson("/api/v1/communities/join/{$code}")->assertCreated();
    $this->getJson("/api/v1/communities/{$community['id']}/members")->assertOk()->assertJsonPath('meta.total', 2);

    $this->putJson("/api/v1/communities/{$community['id']}", ['name' => 'Hijacked'])->assertForbidden();
});
