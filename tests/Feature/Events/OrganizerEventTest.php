<?php

use App\Models\Event;
use App\Models\EventCategory;
use App\Models\EventManager;

function draftPayload(array $overrides = []): array
{
    $category = EventCategory::query()->firstOrCreate(['slug' => 'weddings'], ['name' => 'Weddings', 'icon' => 'heart']);

    return array_merge([
        'title' => 'Ada & Tunde Wedding',
        'description' => '<p>Join us!</p><script>alert(1)</script>',
        'categoryId' => $category->id,
        'schedules' => [[
            'id' => 'tmp-1',
            'startsAt' => now()->addDays(20)->toIso8601String(),
            'endsAt' => now()->addDays(20)->addHours(6)->toIso8601String(),
            'attendanceMode' => 'physical',
            'venue' => ['name' => 'Eko Hotel', 'address' => 'VI', 'city' => 'Lagos', 'state' => 'Lagos', 'countryCode' => 'NG'],
        ]],
        'ticketTypes' => [['name' => 'Regular', 'price' => 500000, 'quantity' => 200, 'scheduleId' => 'tmp-1', 'isActive' => true]],
    ], $overrides);
}

it('creates a draft, sanitises rich text and maps client schedule ids', function () {
    $this->asCustomer();
    $event = $this->postJson('/api/v1/organizer/events', draftPayload())->assertCreated()->json('data');

    expect($event['status'])->toBe('draft')
        ->and($event['description'])->not->toContain('<script>')
        ->and($event['ticketTypes'][0]['scheduleId'])->toBe($event['schedules'][0]['id'])
        ->and($event['minPrice'])->toBe(500000);
});

it('hides drafts from public discovery', function () {
    $this->asCustomer();
    $event = $this->postJson('/api/v1/organizer/events', draftPayload())->json('data');
    app('auth')->forgetGuards();
    $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/events/'.$event['slug'])->assertStatus(404);
});

it('validates completeness on publish', function () {
    $this->asCustomer();
    $event = $this->postJson('/api/v1/organizer/events', ['title' => 'Half done'])->json('data');

    $this->postJson("/api/v1/organizer/events/{$event['id']}/publish")
        ->assertStatus(422)->assertJsonPath('message', 'Complete the event before publishing.')
        ->assertJsonPath('errors.schedules.0', 'Add at least one date')
        ->assertJsonPath('errors.ticketTypes.0', 'Add at least one ticket type or mark the event as free');
});

it('sends unverified organisers to review and puts verified free events live', function () {
    $this->asCustomer();
    $event = $this->postJson('/api/v1/organizer/events', draftPayload())->json('data');
    $this->postJson("/api/v1/organizer/events/{$event['id']}/publish")->assertOk()->assertJsonPath('data.status', 'pending_review');

    $this->asCustomer($this->customer(['identity_status' => 'verified']));
    $free = $this->postJson('/api/v1/organizer/events', draftPayload(['ticketTypes' => [], 'isFree' => true]))->json('data');
    $this->postJson("/api/v1/organizer/events/{$free['id']}/publish")->assertOk()->assertJsonPath('data.status', 'live');

    expect(Event::query()->find($free['id'])->community)->not->toBeNull();
    $this->getJson('/api/v1/events')->assertOk()->assertJsonPath('meta.total', 1);
});

it('gates sub-events behind the Premium plan', function () {
    $this->asCustomer();
    $payload = draftPayload(['subEvents' => [['title' => 'After party', 'startsAt' => now()->addDays(20)->toIso8601String()]]]);
    $this->postJson('/api/v1/organizer/events', $payload)->assertStatus(402)->assertJsonPath('code', 'PAYMENT_REQUIRED');

    $this->asCustomer($this->customer(['plan' => 'premium']));
    $this->postJson('/api/v1/organizer/events', $payload)->assertCreated()->assertJsonCount(1, 'data.subEvents');
});

it('enforces partial manager permissions server-side', function () {
    $organizer = $this->customer(['identity_status' => 'verified']);
    $event = $this->liveEvent($organizer);
    $manager = $this->customer();
    EventManager::query()->create(['event_id' => $event->id, 'user_id' => $manager->id, 'access' => 'partial', 'permissions' => ['manage_guests'], 'status' => 'accepted', 'invited_at' => now()]);

    $this->asCustomer($manager);
    $this->getJson("/api/v1/organizer/events/{$event->id}")->assertOk()->assertJsonPath('data.manage.myRole', 'manager')->assertJsonPath('data.stats.revenue', 0);
    $this->getJson("/api/v1/organizer/events/{$event->id}/requests")->assertOk();
    $this->putJson("/api/v1/organizer/events/{$event->id}", ['title' => 'Hijacked'])->assertStatus(403);
    $this->postJson("/api/v1/organizer/events/{$event->id}/managers", ['email' => 'x@example.com', 'access' => 'full', 'permissions' => []])->assertStatus(403);
    $this->deleteJson("/api/v1/organizer/events/{$event->id}")->assertStatus(403);
});

it('invites a manager who accepts from the notification', function () {
    $organizer = $this->customer(['identity_status' => 'verified']);
    $event = $this->liveEvent($organizer);
    $invitee = $this->customer(['email' => 'mgr@example.com']);

    $this->asCustomer($organizer);
    $this->postJson("/api/v1/organizer/events/{$event->id}/managers", ['email' => 'mgr@example.com', 'access' => 'partial', 'permissions' => ['scan_tickets']])
        ->assertCreated()->assertJsonPath('data.status', 'pending');

    $this->asCustomer($invitee);
    $notification = $this->getJson('/api/v1/notifications?tab=requests')->assertOk()->json('data.0');
    expect($notification['type'])->toBe('manager_invite')->and($notification['actionState'])->toBe('pending');

    $this->postJson("/api/v1/notifications/{$notification['id']}/actions", ['action' => 'accept'])->assertOk()->assertJsonPath('data.actionState', 'accepted');
    $this->getJson('/api/v1/organizer/events?tab=team')->assertOk()->assertJsonPath('data.0.myRole', 'manager');
});
